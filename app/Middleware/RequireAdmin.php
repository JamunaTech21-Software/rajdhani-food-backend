<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Http\Request;

/**
 * Requires a valid admin access token (doc 7.2, 7.4).
 *
 * **No database query.** The access token carries `sub` and `role`, both signed,
 * and section 7.2 accepts that a deactivated admin keeps working access for up
 * to the twenty minutes until their access token expires — deactivation revokes
 * the refresh tokens, so the session cannot be extended past that. Adding a
 * lookup here would buy a shorter window at the cost of a query on every single
 * admin request; the document already made that trade, and this is where it is
 * honoured rather than quietly reversed.
 *
 * The `aud` check inside JwtHelper::decode() is what keeps a customer token off
 * these routes. It is not optional and it is not done here — putting it in the
 * decoder means no future middleware can forget it.
 *
 * Role *authorisation* is not this middleware's job. It establishes who the
 * caller is; RequireRole (RTPP-13) decides what they may do.
 */
final class RequireAdmin implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw ApiError::unauthenticated('Authentication required');
        }

        $claims = JwtHelper::decode(
            $token,
            (string) config('auth.jwt.access_secret'),
            (string) config('auth.audiences.admin', 'admin'),
        );

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            throw ApiError::unauthenticated('Malformed token');
        }

        $request->setAttribute('admin_id', $subject);
        $request->setAttribute('admin_role', is_string($claims['role'] ?? null) ? $claims['role'] : null);

        return $next($request);
    }
}
