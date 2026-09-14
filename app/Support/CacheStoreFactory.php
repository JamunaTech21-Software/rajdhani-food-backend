<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * Picks the `CacheStore` implementation once (RTPP-36, doc §5.4, §14.1).
 *
 * Redis only if it is both configured and actually present — a `REDIS_URL`
 * left over from a different environment must not throw at boot on a host
 * where the extension was never installed, so both conditions are checked,
 * not just the config value.
 */
final class CacheStoreFactory
{
    public static function make(): CacheStore
    {
        $url = config('cache.redis_url');

        if (is_string($url) && $url !== '' && extension_loaded('redis')) {
            return new RedisCacheStore($url);
        }

        return new FileCache();
    }
}
