<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Http\Request;

/**
 * Identifies the customer when there is one, and does not mind when there is not.
 *
 * Most of the customer site is public but personalises when signed in — a
 * product page marks what is already wishlisted, an enquiry form pre-fills
 * (doc §7.1). Those routes cannot use RequireCustomer, because an anonymous
 * visitor must still get the page.
 *
 * **A bad token is treated as no token**, not as an error. An expired access
 * token on a public page should render the anonymous version, not a 401 that a
 * public page has no way to recover from. Routes that genuinely need identity
 * use RequireCustomer, which rejects.
 */
final class OptionalCustomer implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            try {
                $claims = JwtHelper::decode(
                    $token,
                    (string) config('auth.jwt.access_secret'),
                    (string) config('auth.audiences.customer', 'customer'),
                );

                $subject = $claims['sub'] ?? null;

                if (is_string($subject) && $subject !== '') {
                    $request->setAttribute('customer_id', $subject);
                }
            } catch (ApiError) {
                // Anonymous. Swallowed deliberately, and only here — this is the
                // one place in the application where a failed token check is not
                // an error, so it is worth being explicit that it is a choice.
            }
        }

        return $next($request);
    }
}
