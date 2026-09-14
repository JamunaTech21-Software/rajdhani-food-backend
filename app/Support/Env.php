<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use RuntimeException;

/**
 * Reads `.env` once and answers questions about it.
 *
 * Deliberately not a general-purpose dotenv parser. It does what section 15 of
 * the requirements document needs and nothing more: values are flat strings, no
 * interpolation, no nesting.
 *
 * The important behaviour is `require()`. A configuration mistake should stop
 * the process at boot with a message naming the variable, rather than surfacing
 * three layers deep as a null dereference during a request.
 */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $vars = null;

    public static function load(string $path): void
    {
        $vars = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines === false ? [] : $lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $value = trim($value);

                // Strip one matching pair of surrounding quotes, then trailing
                // comments on unquoted values.
                if (strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                } else {
                    $commentAt = strpos($value, ' #');

                    if ($commentAt !== false) {
                        $value = trim(substr($value, 0, $commentAt));
                    }
                }

                $vars[trim($key)] = $value;
            }
        }

        self::$vars = $vars;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$vars === null) {
            throw new RuntimeException('Env::load() must be called before Env::get().');
        }

        $value = self::$vars[$key] ?? null;

        if ($value === null || $value === '') {
            $fromProcess = getenv($key);
            $value = $fromProcess === false || $fromProcess === '' ? null : $fromProcess;
        }

        return $value ?? $default;
    }

    /**
     * Fetch a variable that the application cannot run without.
     *
     * @throws RuntimeException naming the variable, so the failure is actionable
     */
    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(
                "Required environment variable {$key} is missing or empty. "
                . 'See .env.example and section 15 of the requirements document.'
            );
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    /** @return string[] */
    public static function list(string $key): array
    {
        $value = self::get($key);

        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }
}
