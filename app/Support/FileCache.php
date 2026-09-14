<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * A small TTL cache on disk, in storage/cache.
 *
 * There is no Redis or Memcached on shared hosting (doc §16.6), and the things
 * that need caching here — Google's signing keys, later the rendered layout —
 * are small, read far more often than written, and survivable if lost. A file
 * is the right size of tool.
 *
 * **Every read failure returns a miss rather than throwing.** A corrupt or
 * half-written cache file must degrade into "fetch it again", never into a 500
 * on a login attempt.
 */
final class FileCache
{
    public function __construct(private readonly ?string $directory = null)
    {
    }

    /** @return array<string,mixed>|null null when absent, expired or unreadable */
    public function get(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $entry */
            $entry = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($entry) || !isset($entry['expires_at'], $entry['value'])) {
            return null;
        }

        if (!is_numeric($entry['expires_at']) || time() >= (int) $entry['expires_at']) {
            return null;
        }

        return is_array($entry['value']) ? $entry['value'] : null;
    }

    /** @param array<string,mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(
            ['expires_at' => time() + $ttlSeconds, 'value' => $value],
            JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            return;
        }

        // Write to a unique temporary file and rename. rename() is atomic on the
        // same filesystem, so a concurrent reader sees either the old file or
        // the new one — never a half-written one.
        $temporary = $this->path($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }

        if (!@rename($temporary, $this->path($key))) {
            @unlink($temporary);
        }
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $key): string
    {
        // Hashed so a key can contain anything without becoming a path
        // traversal or an illegal filename.
        return $this->directory() . '/' . hash('sha256', $key) . '.json';
    }

    private function directory(): string
    {
        return $this->directory ?? base_path('storage/cache');
    }
}
