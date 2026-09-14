<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Repositories\RateLimitRepository;
use Rajdhani\Services\RateLimiter;

/**
 * The §14.2 rate limits, against the real counter table.
 *
 * The property that matters most is the one the stack change put at risk: the
 * counter is **shared state**, not per-process memory. Under PHP-FPM each
 * request runs in whichever worker is free, so a counter held in a PHP variable
 * counts a fraction of the traffic and the limit silently never fires
 * (doc §19, deviation 2).
 *
 * A single PHPUnit process cannot literally be two FPM workers, so that is
 * asserted the way it can be: a limiter built fresh — new service, new
 * repository, nothing carried over — must see what a previous one counted. If
 * the state were in the object, it would not.
 */
final class RateLimitTest extends DatabaseTestCase
{
    private RateLimitRepository $buckets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buckets = new RateLimitRepository($this->db);
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->buckets);
    }

    /** A completely fresh object graph, as a second PHP process would have. */
    private function freshLimiter(): RateLimiter
    {
        return new RateLimiter(new RateLimitRepository($this->db));
    }

    public function testTheCounterIsSharedStateNotProcessMemory(): void
    {
        $ip = $this->uniqueIp();

        for ($i = 0; $i < 5; $i++) {
            $this->limiter()->enforce('global', $ip);
        }

        // Nothing of the first limiter survives into this one except the row.
        self::assertSame(5, $this->buckets->hitsFor("global:{$ip}"));

        $this->freshLimiter()->enforce('global', $ip);

        self::assertSame(6, $this->buckets->hitsFor("global:{$ip}"));
    }

    public function testTheLimitIsEnforcedAtExactlyTheConfiguredNumber(): void
    {
        $ip = $this->uniqueIp();
        $limit = (int) config('auth.rate_limits.global.attempts', 100);

        // The limit is inclusive: the hundredth request is allowed.
        for ($i = 0; $i < $limit; $i++) {
            $this->limiter()->enforce('global', $ip);
        }

        self::assertSame($limit, $this->buckets->hitsFor("global:{$ip}"));

        try {
            $this->limiter()->enforce('global', $ip);
            self::fail('Request ' . ($limit + 1) . ' should have been refused');
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::RATE_LIMITED, $e->errorCode());
            self::assertSame(429, $e->status());
        }
    }

    public function testTwoAddressesHaveSeparateBudgets(): void
    {
        $first = $this->uniqueIp();
        $second = $this->uniqueIp();
        $limit = (int) config('auth.rate_limits.global.attempts', 100);

        for ($i = 0; $i < $limit + 1; $i++) {
            try {
                $this->limiter()->enforce('global', $first);
            } catch (ApiError) {
                // expected on the last one
            }
        }

        // The neighbour is untouched — one noisy visitor must not take the site
        // down for everyone behind a different address.
        $this->limiter()->enforce('global', $second);
        self::assertSame(1, $this->buckets->hitsFor("global:{$second}"));
    }

    public function testTheWindowRollsOverAndTheBudgetReturns(): void
    {
        $ip = $this->uniqueIp();
        $key = "global:{$ip}";
        $limit = (int) config('auth.rate_limits.global.attempts', 100);

        for ($i = 0; $i < $limit + 1; $i++) {
            try {
                $this->limiter()->enforce('global', $ip);
            } catch (ApiError) {
                // expected
            }
        }

        // Age the window rather than waiting fifteen minutes for it.
        $this->db->exec(
            'UPDATE rate_limits SET window_start = NOW(3) - INTERVAL 20 MINUTE,
                                    expires_at   = NOW(3) - INTERVAL 5 MINUTE
              WHERE bucket_key = ' . $this->db->quote($key)
        );

        $this->limiter()->enforce('global', $ip);

        // A new window starts at one, not at limit + 2.
        self::assertSame(1, $this->buckets->hitsFor($key));
    }

    // ─── public forms ───────────────────────────────────────────────────────

    public function testThePublicFormLimitIsFarTighterThanTheGlobalOne(): void
    {
        $ip = $this->uniqueIp();
        $limit = (int) config('auth.rate_limits.public_form.attempts', 5);

        self::assertLessThan((int) config('auth.rate_limits.global.attempts', 100), $limit);

        for ($i = 0; $i < $limit; $i++) {
            $this->limiter()->enforce('public_form', $ip, 'enquiry');
        }

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(429);
        $this->limiter()->enforce('public_form', $ip, 'enquiry');
    }

    /**
     * The four public forms hold separate budgets. Someone who has just sent an
     * enquiry must still be able to report a problem through the contact form.
     */
    public function testEachFormHasItsOwnBudget(): void
    {
        $ip = $this->uniqueIp();
        $limit = (int) config('auth.rate_limits.public_form.attempts', 5);

        for ($i = 0; $i < $limit + 1; $i++) {
            try {
                $this->limiter()->enforce('public_form', $ip, 'enquiry');
            } catch (ApiError) {
                // expected
            }
        }

        $this->limiter()->enforce('public_form', $ip, 'contact');
        self::assertSame(1, $this->buckets->hitsFor("public_form:contact:{$ip}"));
    }

    public function testTheFormBudgetIsSeparateFromTheGlobalOne(): void
    {
        $ip = $this->uniqueIp();

        $this->limiter()->enforce('public_form', $ip, 'enquiry');

        self::assertSame(1, $this->buckets->hitsFor("public_form:enquiry:{$ip}"));
        self::assertSame(0, $this->buckets->hitsFor("global:{$ip}"));
    }

    // ─── behaviour at the edges ─────────────────────────────────────────────

    /**
     * A request with no identifiable address — a CLI run, or a proxy that
     * stripped it — must not be counted. Everyone would otherwise share one
     * bucket and the site would rate-limit itself as a single visitor.
     */
    public function testAnEmptyIdentifierIsNotCounted(): void
    {
        $this->limiter()->enforce('global', '');

        self::assertSame(0, $this->buckets->hitsFor('global:'));
    }

    public function testAnUnknownScopeIsNotEnforced(): void
    {
        $ip = $this->uniqueIp();

        // No configured limit means no limit, rather than a limit of zero that
        // would refuse the first request.
        $this->limiter()->enforce('no_such_scope', $ip);

        self::assertSame(0, $this->buckets->hitsFor("no_such_scope:{$ip}"));
    }

    /**
     * The limiter fails open. A guard rail that becomes a wall when it breaks is
     * worse than the thing it guards against.
     */
    public function testAFailingCounterLetsTheRequestThrough(): void
    {
        // A genuinely broken counter rather than a stub: a real connection
        // pointed at a schema that has no rate_limits table, so the production
        // code path fails exactly as it would if the table were dropped or the
        // database were unreachable.
        $broken = new \PDO(
            sprintf(
                'mysql:%s;dbname=information_schema;charset=utf8mb4',
                is_string(config('database.socket')) && config('database.socket') !== ''
                    ? 'unix_socket=' . config('database.socket')
                    : sprintf('host=%s;port=%s', (string) config('database.host'), (string) config('database.port')),
            ),
            (string) config('database.username'),
            (string) config('database.password', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $limiter = new RateLimiter(new RateLimitRepository($broken));

        $limiter->enforce('global', $this->uniqueIp());

        self::assertTrue(true, 'A broken counter must not refuse the request');
    }

    public function testAVeryLongKeyIsTruncatedRatherThanRejected(): void
    {
        // bucket_key is VARCHAR(191); an IPv6 address plus a long form name
        // must not overflow it and take down the endpoint.
        $this->limiter()->enforce('public_form', str_repeat('a', 300), 'enquiry');

        self::assertGreaterThan(0, (int) $this->db->query('SELECT COUNT(*) FROM rate_limits')?->fetchColumn());
    }

    public function testExpiredBucketsArePruned(): void
    {
        $ip = $this->uniqueIp();
        $this->limiter()->enforce('global', $ip);

        $this->db->exec('UPDATE rate_limits SET expires_at = NOW(3) - INTERVAL 1 DAY');

        self::assertGreaterThan(0, $this->buckets->deleteExpired());
        self::assertSame(0, $this->buckets->hitsFor("global:{$ip}"));
    }

    /** Each test gets its own address so buckets cannot leak between them. */
    private function uniqueIp(): string
    {
        return '203.0.' . random_int(0, 255) . '.' . random_int(1, 254);
    }
}
