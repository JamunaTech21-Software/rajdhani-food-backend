<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Support;

use Rajdhani\Support\CacheStore;

/** An in-memory `CacheStore` (RTPP-36) — no disk, no Redis, so a test can assert exactly what was stored and forgotten. */
final class FakeCacheStore implements CacheStore
{
    /** @var array<string,array<string,mixed>> */
    public array $entries = [];

    /** @var list<string> keys `get()` was asked for, in order */
    public array $reads = [];

    public function get(string $key): ?array
    {
        $this->reads[] = $key;

        return $this->entries[$key] ?? null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->entries[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }
}
