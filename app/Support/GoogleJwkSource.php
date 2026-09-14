<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use RuntimeException;

/**
 * Google's published signing keys, cached on disk.
 *
 * Google rotates these every few days and overlaps the old and new key while it
 * does, which is why the endpoint returns several. Two rules follow:
 *
 *   - **Cache them.** Fetching on every sign-in adds a round trip to Google to
 *     each login and breaks entirely if their endpoint is briefly unreachable.
 *     The TTL comes from the response's own `Cache-Control: max-age`, so the
 *     cache expires exactly when Google says it should rather than on a number
 *     invented here.
 *   - **Refetch once on a key-id miss.** A token signed with a key newer than
 *     the cache is not a forgery, it is a rotation. The verifier asks for a
 *     forced refresh once before rejecting it.
 */
final class GoogleJwkSource implements JwkSource
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google-jwks';
    private const MIN_TTL = 300;
    private const MAX_TTL = 86400;
    private const FALLBACK_TTL = 3600;

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly FileCache $cache = new FileCache(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function keys(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->cache->get(self::CACHE_KEY);

            if ($cached !== null && isset($cached['keys']) && is_array($cached['keys'])) {
                /** @var list<array<string,mixed>> $keys */
                $keys = $cached['keys'];

                return $keys;
            }
        }

        $response = $this->http->get(self::CERTS_URL);

        if ($response['status'] !== 200) {
            throw new RuntimeException("Google returned HTTP {$response['status']} for its signing keys.");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys']) || $decoded['keys'] === []) {
            throw new RuntimeException('Google returned no usable signing keys.');
        }

        /** @var list<array<string,mixed>> $keys */
        $keys = array_values($decoded['keys']);

        $this->cache->put(self::CACHE_KEY, ['keys' => $keys], $this->ttlFrom($response['headers']));

        return $keys;
    }

    /** @param array<string,string> $headers */
    private function ttlFrom(array $headers): int
    {
        $cacheControl = $headers['cache-control'] ?? '';

        if (preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $match) === 1) {
            // Clamped at both ends: a max-age of a few seconds would defeat the
            // cache, and one of a month would outlive several rotations.
            return max(self::MIN_TTL, min(self::MAX_TTL, (int) $match[1]));
        }

        return self::FALLBACK_TTL;
    }
}
