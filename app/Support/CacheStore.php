<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * A small TTL key/value cache, swappable between `FileCache` (always
 * available) and `RedisCacheStore` (RTPP-36, only if `REDIS_URL` is set and
 * the extension is loaded) — see `CacheStoreFactory`.
 *
 * Both implementations share one contract: a read failure or a miss look
 * identical (`null`, never an exception), and a write failure is silent.
 * Nothing that calls this interface may treat the cache as a source of
 * truth — it is always safe to skip straight to computing the value fresh.
 */
interface CacheStore
{
    /** @return array<string,mixed>|null null when absent, expired or unreadable */
    public function get(string $key): ?array;

    /** @param array<string,mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
