<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rajdhani\Support\Duration;

final class DurationTest extends TestCase
{
    public function testReadsPlainSeconds(): void
    {
        self::assertSame(900, Duration::seconds('900'));
        self::assertSame(0, Duration::seconds('0'));
    }

    public function testReadsUnitSuffixes(): void
    {
        self::assertSame(30, Duration::seconds('30s'));
        self::assertSame(900, Duration::seconds('15m'));
        self::assertSame(1200, Duration::seconds('20m'));
        self::assertSame(7200, Duration::seconds('2h'));
        self::assertSame(604800, Duration::seconds('7d'));
        self::assertSame(2592000, Duration::seconds('30d'));
    }

    public function testIsCaseAndWhitespaceTolerant(): void
    {
        self::assertSame(604800, Duration::seconds(' 7D '));
        self::assertSame(900, Duration::seconds('15 m'));
    }

    /**
     * The point of the class: config/auth.php writes `20m` because section 7
     * does, and an operator writes `1200` in .env. Both must produce the same
     * token lifetime.
     */
    public function testTheTwoNotationsAgree(): void
    {
        self::assertSame(Duration::seconds('1200'), Duration::seconds('20m'));
    }

    public function testFallsBackToTheDefaultForUnreadableInput(): void
    {
        self::assertSame(60, Duration::seconds('twenty minutes', 60));
        self::assertSame(60, Duration::seconds('', 60));
        self::assertSame(60, Duration::seconds('15y', 60));
    }

    public function testThrowsWhenThereIsNoDefaultToFallBackOn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Duration::seconds('twenty minutes');
    }
}
