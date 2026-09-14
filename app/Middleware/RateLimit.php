<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Http\Request;
use Rajdhani\Services\RateLimiter;

/**
 * The global limit from §14.2: 100 requests per 15 minutes per IP.
 *
 * Registered as global middleware, so it counts every route — with two
 * exemptions:
 *
 *   - **`OPTIONS`**, because a CORS preflight is the browser asking permission
 *     and is not a request the client chose to make. Counting it would halve
 *     every cross-origin client's effective budget.
 *   - **the health endpoints**, because an uptime monitor polls them on a fixed
 *     schedule from one address (§16.5). Rate-limiting the monitor would produce
 *     exactly the alert it exists to avoid.
 */
final class RateLimit implements Middleware
{
    private const EXEMPT_PATHS = ['/health', '/health/db'];

    public function __construct(
        private readonly RateLimiter $limiter = new RateLimiter(),
    ) {
    }

    public function handle(Request $request, callable $next): mixed
    {
        if ($request->method === 'OPTIONS' || in_array($request->path, self::EXEMPT_PATHS, true)) {
            return $next($request);
        }

        $this->limiter->enforce('global', $request->ip);

        return $next($request);
    }
}
