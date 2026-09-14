<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use InvalidArgumentException;

/**
 * Turns a token lifetime into seconds.
 *
 * Two notations reach this code and both have to work. `config/auth.php`
 * defaults are written the way section 7 writes them — `15m`, `7d` — because
 * that is what the document says and a config file that disagrees with the
 * specification is a bug waiting to be argued about. `.env` values are plain
 * integers, because that is what an operator setting `ADMIN_ACCESS_EXPIRY=900`
 * will naturally type.
 *
 * Accepting only one of the two would mean a deployment where the token
 * lifetime silently falls back to a default.
 */
final class Duration
{
    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    public static function seconds(string $value, ?int $default = null): int
    {
        $value = trim($value);

        if ($value === '') {
            return self::orFail($default, $value);
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^(\d+)\s*([smhdw])$/i', $value, $match) === 1) {
            return (int) $match[1] * self::UNITS[strtolower($match[2])];
        }

        return self::orFail($default, $value);
    }

    private static function orFail(?int $default, string $value): int
    {
        if ($default !== null) {
            return $default;
        }

        throw new InvalidArgumentException(
            "Cannot read '{$value}' as a duration. Use seconds (900) or a unit suffix (15m, 7d)."
        );
    }
}
