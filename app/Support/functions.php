<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

if (!function_exists('env')) {
    /**
     * Shorthand for optional configuration. Anything the application genuinely
     * requires should use Env::require() so a missing value fails at boot.
     */
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $relative = ''): string
    {
        $root = dirname(__DIR__, 2);

        return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('config')) {
    /**
     * Dot-notation access to the files in config/, loaded once each.
     *
     * config('database.charset') reads config/database.php and returns
     * $config['charset'].
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var array<string,array<string,mixed>> $loaded */
        static $loaded = [];

        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (!isset($loaded[$file])) {
            $path = base_path("config/{$file}.php");

            if (!is_file($path)) {
                return $default;
            }

            /** @var array<string,mixed> $values */
            $values = require $path;
            $loaded[$file] = $values;
        }

        $value = $loaded[$file];

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
