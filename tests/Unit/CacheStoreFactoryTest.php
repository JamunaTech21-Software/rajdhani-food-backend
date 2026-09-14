<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Support\CacheStoreFactory;
use Rajdhani\Support\Env;
use Rajdhani\Support\FileCache;

/**
 * `CacheStoreFactory` (RTPP-36).
 *
 * Only the fallback path is unit-tested here: `config()` caches each file's
 * values for the life of the PHP process (the same issue `RecaptchaVerifier`
 * and others route around with a constructor override), so flipping
 * `REDIS_URL` mid-test-run to exercise the Redis branch would be order
 * dependent on whatever earlier test first read `config('cache.*')`. The
 * fallback is also the one branch every environment this project actually
 * targets exercises (doc §5.4, §16.6) — Redis staying unconfigured is the
 * expected case, not an edge case.
 */
final class CacheStoreFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);
    }

    public function testFallsBackToFileCacheWhenRedisIsNotConfigured(): void
    {
        self::assertInstanceOf(FileCache::class, CacheStoreFactory::make());
    }

    public function testTheExtensionIsAbsentInThisProjectsOwnEnvironmentByDesign(): void
    {
        // Documents the assumption `RedisCacheStore`'s `phpstan.neon` ignore
        // rule and class doc both rely on — if this ever starts failing, the
        // Redis branch has become testable here and should be.
        self::assertFalse(extension_loaded('redis'));
    }
}
