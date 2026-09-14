<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Http\Request;

/**
 * Requires a valid customer access token (doc §7.1).
 *
 * The mirror of RequireAdmin, and separate from it rather than parameterised on
 * purpose: a single middleware taking an audience argument would be one wrong
 * argument away from an admin route accepting customer tokens, and the router
 * registers middleware by class name with no arguments.
 *
 * The `aud` check that keeps the two apart lives inside JwtHelper::decode().
 */
final class RequireCustomer implements Middleware
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
            (string) config('auth.audiences.customer', 'customer'),
        );

        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            throw ApiError::unauthenticated('Malformed token');
        }

        $request->setAttribute('customer_id', $subject);

        return $next($request);
    }
}
