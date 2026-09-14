<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ApiResponse;
use Rajdhani\Repositories\RateLimitRepository;

/**
 * The §14.2 rate limits.
 *
 * Two policies, both per IP and both configured in `config/auth.php`:
 *
 *   global       100 requests / 15 minutes — a crawler or a broken client
 *   public_form  5 requests / hour         — form spam
 *
 * A third, the admin login throttle, is *not* here: it counts failures per
 * email rather than requests per IP, and lives in AdminAuthService where the
 * outcome of the attempt is known (doc §7.2).
 *
 * **The limiter fails open.** If the counter table is unreachable the request is
 * allowed through, because a database blip should not take the whole site down —
 * a rate limiter is a guard rail, and a guard rail that becomes a wall when it
 * breaks is worse than the thing it guards against.
 */
final class RateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $buckets = new RateLimitRepository(),
    ) {
    }

    /**
     * Count this request and throw if the bucket is over its limit.
     *
     * @param string $scope 'global' or 'public_form'
     *
     * @throws ApiError RATE_LIMITED, carrying Retry-After
     */
    public function enforce(string $scope, string $identifier, ?string $suffix = null): void
    {
        [$limit, $window] = $this->policy($scope);

        if ($limit <= 0 || $identifier === '') {
            // No identifiable client — a CLI invocation, or a proxy that
            // stripped the address. Counting everyone into one bucket would
            // rate-limit the whole site as if it were one visitor.
            return;
        }

        $key = $suffix === null ? "{$scope}:{$identifier}" : "{$scope}:{$suffix}:{$identifier}";

        try {
            $state = $this->buckets->hit($key, $window);
        } catch (\Throwable) {
            return;
        }

        $remaining = max(0, $limit - $state['hits']);
        $retryAfter = max(1, $state['resets_at'] - time());

        // Sent on every response, not only on a rejection: a well-behaved client
        // can then slow down before it is refused.
        ApiResponse::header('X-RateLimit-Limit', (string) $limit);
        ApiResponse::header('X-RateLimit-Remaining', (string) $remaining);
        ApiResponse::header('X-RateLimit-Reset', (string) $state['resets_at']);

        if ($state['hits'] > $limit) {
            ApiResponse::header('Retry-After', (string) $retryAfter);

            throw ApiError::rateLimited(sprintf(
                'Too many requests. Try again in %d %s.',
                $retryAfter >= 60 ? (int) ceil($retryAfter / 60) : $retryAfter,
                $retryAfter >= 60 ? 'minutes' : 'seconds',
            ));
        }
    }

    /** @return array{int,int} limit, window in seconds */
    private function policy(string $scope): array
    {
        /** @var array<string,mixed> $configured */
        $configured = config("auth.rate_limits.{$scope}", []);

        return [
            is_numeric($configured['attempts'] ?? null) ? (int) $configured['attempts'] : 0,
            is_numeric($configured['per_seconds'] ?? null) ? (int) $configured['per_seconds'] : 900,
        ];
    }
}
