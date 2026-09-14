<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use JsonException;
use Throwable;

/**
 * The optional Redis-backed `CacheStore` (RTPP-36, doc §5.4, §14.1) —
 * selected by `CacheStoreFactory` only when `REDIS_URL` is set and the
 * `redis` PHP extension is loaded. Neither is expected to be true on the
 * shared cPanel hosting this project targets (§16.6); this class exists so
 * the option named in the ticket is real, not because it is expected to run
 * in production.
 *
 * **The `redis` extension itself is not installed in this project's own
 * environment** — PHPStan still type-checks this file cleanly because it
 * bundles stubs for common extensions regardless of what's actually loaded,
 * but PHP itself would need the real class to exist the moment this code
 * runs. `CacheStoreFactory` only constructs this class behind
 * `extension_loaded('redis')`, so that moment never arrives on a host
 * without it.
 *
 * Every method fails to a miss/no-op on any connection or command error,
 * identical to `FileCache`'s own contract — a Redis outage must degrade
 * this feature back to "compute it fresh", never into a 500.
 */
final class RedisCacheStore implements CacheStore
{
    private ?\Redis $client = null;

    public function __construct(private readonly string $url)
    {
    }

    /** @return array<string,mixed>|null */
    public function get(string $key): ?array
    {
        try {
            $raw = $this->connection()->get($this->prefixed($key));
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw)) {
            return null;
        }

        try {
            /** @var mixed $value */
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $payload = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        try {
            $this->connection()->setex($this->prefixed($key), $ttlSeconds, $payload);
        } catch (Throwable) {
            // A cache write is never the reason a request fails.
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->connection()->del($this->prefixed($key));
        } catch (Throwable) {
        }
    }

    private function connection(): \Redis
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $parts = parse_url($this->url);
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 6379;
        $password = is_string($parts['pass'] ?? null) ? $parts['pass'] : null;

        $client = new \Redis();
        $client->connect($host, $port, 1.0);

        if ($password !== null && $password !== '') {
            $client->auth($password);
        }

        $this->client = $client;

        return $client;
    }

    private function prefixed(string $key): string
    {
        return 'rajdhani:' . $key;
    }
}
