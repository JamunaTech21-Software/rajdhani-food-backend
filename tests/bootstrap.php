<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * `config/auth.php` and `config/database.php` call Env::require(), so they throw
 * at load time when a variable is missing — the behaviour production wants and a
 * test run does not. A developer's `.env` has everything; CI has none of it and
 * passes the database settings as process environment variables instead.
 *
 * So: load the real `.env` when there is one, and otherwise synthesise a
 * throwaway file. Nothing here calls putenv(), because EnvTest asserts what
 * happens when a variable is *absent*, and polluting the process environment
 * would quietly make those assertions untestable.
 *
 * TEST_ENV_PATH is exported for tests that need to restore this state — EnvTest
 * loads its own fixtures and leaves Env pointing at them.
 */

use Rajdhani\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';

if (!is_file($envPath)) {
    $fallbacks = [
        'APP_ENV'     => 'testing',
        'APP_DEBUG'   => 'true',
        'APP_URL'     => 'http://localhost:8000',
        'APP_TIMEZONE' => 'UTC',
        'LOG_LEVEL'   => 'error',

        // CI provides these through the environment; Env::get() falls back to
        // getenv() for anything the file leaves empty, so naming them here with
        // the CI defaults keeps both paths working.
        'DB_HOST'     => getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1',
        'DB_PORT'     => getenv('DB_PORT') !== false ? (string) getenv('DB_PORT') : '3306',
        'DB_DATABASE' => getenv('DB_DATABASE') !== false ? (string) getenv('DB_DATABASE') : 'rajdhani_ci',
        'DB_USERNAME' => getenv('DB_USERNAME') !== false ? (string) getenv('DB_USERNAME') : 'root',
        'DB_CHARSET'  => 'utf8mb4',

        'CORS_ORIGINS' => 'http://localhost:5173',

        // Deliberately obvious. If one of these ever turns up in a token
        // outside a test run, it should be recognisable on sight.
        'JWT_ACCESS_SECRET'  => 'test-only-access-secret-do-not-use-anywhere',
        'JWT_REFRESH_SECRET' => 'test-only-refresh-secret-do-not-use-anywhere',
    ];

    $lines = [];

    foreach ($fallbacks as $key => $value) {
        $lines[] = "{$key}={$value}";
    }

    // DB_PASSWORD is intentionally written empty rather than omitted: an empty
    // value falls through to getenv(), which is how CI supplies it.
    $lines[] = 'DB_PASSWORD=';

    $envPath = sys_get_temp_dir() . '/rajdhani-test-env-' . getmypid();
    file_put_contents($envPath, implode("\n", $lines) . "\n");

    register_shutdown_function(static function () use ($envPath): void {
        if (is_file($envPath)) {
            unlink($envPath);
        }
    });
}

define('TEST_ENV_PATH', $envPath);

Env::load($envPath);
