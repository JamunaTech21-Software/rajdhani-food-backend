<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiResponse;
use Rajdhani\Http\Request;

/**
 * Section 4.1. Runs before everything else in the pipeline.
 *
 * Two things here are not negotiable and are easy to get subtly wrong:
 *
 *  - With credentials enabled, `Access-Control-Allow-Origin` must echo one exact
 *    origin. The wildcard is rejected by browsers in that mode, and refresh
 *    cookies would silently stop flowing.
 *  - `Vary: Origin` must be sent, or a shared cache can serve one origin's
 *    CORS headers to another.
 *
 * A disallowed origin is not an error response. It is a normal response with no
 * CORS headers, which the browser then blocks — that is the correct shape, and
 * it avoids telling a prober which origins are on the list.
 */
final class Cors implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        /** @var string[] $allowed */
        $allowed = config('cors.origins', []);
        $origin = $request->header('Origin');

        $isAllowed = $origin !== null && in_array($origin, $allowed, true);

        if ($isAllowed) {
            ApiResponse::header('Access-Control-Allow-Origin', (string) $origin);
            ApiResponse::header('Vary', 'Origin');

            if (config('cors.credentials', true) === true) {
                ApiResponse::header('Access-Control-Allow-Credentials', 'true');
            }

            /** @var string[] $exposed */
            $exposed = config('cors.exposed_headers', []);
            if ($exposed !== []) {
                ApiResponse::header('Access-Control-Expose-Headers', implode(', ', $exposed));
            }
        }

        if ($request->method === 'OPTIONS') {
            if ($isAllowed) {
                /** @var string[] $methods */
                $methods = config('cors.methods', []);
                /** @var string[] $headers */
                $headers = config('cors.allowed_headers', []);

                ApiResponse::header('Access-Control-Allow-Methods', implode(', ', $methods));
                ApiResponse::header('Access-Control-Allow-Headers', implode(', ', $headers));
                ApiResponse::header('Access-Control-Max-Age', (string) config('cors.max_age', 86400));
            }

            // Preflight ends here either way; it never reaches a controller.
            ApiResponse::noContent();
        }

        return $next($request);
    }
}
