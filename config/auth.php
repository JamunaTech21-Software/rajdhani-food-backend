<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * Section 7. Two audiences share one backend; an admin token must never be
 * valid on a customer route, which is what the `aud` claim enforces.
 *
 * The access and refresh secrets are deliberately separate values: if one leaks,
 * it does not mint the other kind of token.
 */
return [
    'audiences' => [
        'customer' => 'customer',
        'admin'    => 'admin',
    ],

    'jwt' => [
        'algorithm'      => 'HS256',
        'issuer'         => Env::get('APP_URL', 'http://localhost:8000'),
        'access_secret'  => Env::require('JWT_ACCESS_SECRET'),
        'refresh_secret' => Env::require('JWT_REFRESH_SECRET'),

        // Small allowance for clock skew between the API and a client.
        'leeway_seconds' => 30,
    ],

    'ttl' => [
        'customer_access'  => Env::get('JWT_ACCESS_EXPIRY', '15m'),
        'customer_refresh' => Env::get('JWT_REFRESH_EXPIRY', '30d'),
        'admin_access'     => Env::get('ADMIN_ACCESS_EXPIRY', '20m'),
        'admin_refresh'    => Env::get('ADMIN_REFRESH_EXPIRY', '7d'),
    ],

    /**
     * argon2id parameters (doc 7.2). These are the PHP defaults; they are stated
     * explicitly so that changing them is a deliberate, reviewable act rather
     * than an accident of a PHP upgrade.
     */
    'argon2id' => [
        'memory_cost' => 65536,
        'time_cost'   => 4,
        'threads'     => 1,
    ],

    'password_policy' => [
        'min_length'   => 10,
        'require_upper' => true,
        'require_lower' => true,
        'require_digit' => true,
        'require_symbol' => true,
    ],

    'invite_ttl_hours' => 48,
    'reset_ttl_hours'  => 1,

    'google_client_id' => Env::get('GOOGLE_CLIENT_ID'),

    'rate_limits' => [
        'global'      => ['attempts' => 100, 'per_seconds' => 900],
        'public_form' => ['attempts' => 5,   'per_seconds' => 3600],
        'admin_login' => ['attempts' => 5,   'per_seconds' => 900, 'lockout_seconds' => 1800],
    ],
];
