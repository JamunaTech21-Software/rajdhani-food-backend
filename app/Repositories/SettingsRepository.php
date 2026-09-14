<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `settings` (doc §8.2) — the key/value store for what is neither content nor
 * secret.
 *
 * Values are always strings; the schema has no type column, so interpretation
 * belongs to the caller. `bool()` exists because "is this feature on" is the
 * common case and every caller writing its own `=== '1'` check is how a setting
 * ends up meaning one thing in two places.
 */
final class SettingsRepository extends Repository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->scalar(
            'SELECT value FROM settings WHERE `key` = :key LIMIT 1',
            [':key' => $key],
        );

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Every row, for `GET /admin/settings` (doc §9.8; RTPP-32) — the
     * admin-facing counterpart to `get()`/`bool()`/`int()`'s internal,
     * one-key-at-a-time reads.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        return $this->all('SELECT `key`, value, `group` FROM settings ORDER BY `group`, `key`');
    }

    /**
     * `PUT /admin/settings`. Only ever `UPDATE`s — `settings.key` is an
     * allowlist populated by `SettingsSeeder`, not something client input may
     * create, so a typo'd key is reported back rather than silently becoming
     * a new row nothing ever reads.
     *
     * @param array<string,string> $values key => value
     *
     * @return list<string> keys that do not exist
     */
    public function updateMany(array $values): array
    {
        $missing = [];

        foreach ($values as $key => $value) {
            $affected = $this->run(
                'UPDATE settings SET value = :value, updated_at = :updated_at WHERE `key` = :key',
                [':value' => $value, ':updated_at' => $this->now(), ':key' => $key],
            );

            if ($affected === 0) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
