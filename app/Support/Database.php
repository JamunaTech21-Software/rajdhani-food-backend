<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The single PDO connection for a request.
 *
 * PHP-FPM gives each request a fresh process state, so there is no connection
 * pool to manage — one lazily-created connection per request is the whole
 * lifecycle. Shared hosting caps concurrent MySQL connections (doc 16.6), which
 * is the reason not to open more than one.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $socket = config('database.socket');
        $charset = (string) config('database.charset', 'utf8mb4');
        $database = (string) config('database.database');

        $dsn = is_string($socket) && $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset)
            : sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                (string) config('database.host'),
                (string) config('database.port'),
                $database,
                $charset,
            );

        try {
            /** @var array<int,mixed> $options */
            $options = config('database.options', []);

            self::$connection = new PDO(
                $dsn,
                (string) config('database.username'),
                (string) config('database.password', ''),
                $options,
            );
            /*
             * Pin the session to UTC.
             *
             * Every DATETIME(3) in this application is written as a UTC string
             * built in PHP and read back the same way, so it is self-consistent
             * — but MySQL's own NOW() follows the *server's* timezone, which is
             * +06:00 on a machine set to Asia/Dhaka. Any query comparing a
             * stored timestamp against NOW(), or writing one with it, would be
             * six hours out, and the failure is silent: a rate-limit window
             * that never expires, a token that never times out.
             *
             * Set here rather than through PDO's init-command option because
             * that constant was renamed in PHP 8.4 and deprecated in 8.5, and
             * this project supports 8.2 upward. One exec() works on all of them.
             *
             * '+00:00' rather than 'UTC': the named zones live in
             * mysql.time_zone, which is routinely empty on shared hosting, and
             * `SET time_zone = 'UTC'` then fails at connect.
             */
            self::$connection->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            // The message carries credentials in some drivers; never let it
            // reach a response body.
            throw new RuntimeException('Database connection failed', 0, $e);
        }

        return self::$connection;
    }

    /** Used by /health/db, which must distinguish "down" from "misconfigured". */
    public static function isReachable(): bool
    {
        try {
            self::connection()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$connection = null;
    }
}
