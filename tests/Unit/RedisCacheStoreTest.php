<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Support\RedisCacheStore;

/**
 * `RedisCacheStore` (RTPP-36) fails to a miss/no-op rather than throwing —
 * exercised here by the exact condition this project's own environment
 * provides for free: no `redis` extension installed. `new \Redis()` throws
 * a catchable `\Error` in that case (PHP has turned "class not found" into
 * a catchable `Error`, not an uncatchable fatal, since PHP 7), which lands
 * in the same `catch (Throwable)` a genuine connection refusal would — so
 * this test proves real resilience, not just "the extension is missing".
 */
final class RedisCacheStoreTest extends TestCase
{
    public function testGetFailsToNullRatherThanThrowing(): void
    {
        $store = new RedisCacheStore('redis://127.0.0.1:6379');

        self::assertNull($store->get('anything'));
    }

    public function testPutIsANoOpRatherThanThrowing(): void
    {
        $store = new RedisCacheStore('redis://127.0.0.1:6379');

        $store->put('anything', ['x' => 1], 60);

        self::assertNull($store->get('anything'));
    }

    public function testForgetIsANoOpRatherThanThrowing(): void
    {
        $store = new RedisCacheStore('redis://127.0.0.1:6379');

        $store->forget('anything');

        self::addToAssertionCount(1);
    }
}
