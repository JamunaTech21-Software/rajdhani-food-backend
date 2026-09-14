<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * PDO configuration (doc 5.3).
 *
 * ERRMODE_EXCEPTION and EMULATE_PREPARES=false are not defaults worth changing:
 * the first turns silent failures into exceptions the error handler can render
 * as a proper envelope, the second makes prepared statements actually prepared
 * server-side rather than interpolated by the driver.
 */
return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::get('DB_PORT', '3306'),

    // Local development runs MySQL 8 on a non-default port over a socket, so the
    // socket wins when set. Production uses host/port.
    'socket'   => Env::get('DB_SOCKET'),

    'database' => Env::require('DB_DATABASE'),
    'username' => Env::require('DB_USERNAME'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),

    'options' => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ],
];
