<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\DateHelper;

/**
 * `DateHelper::iso()` — the fix for every timestamp in the API going out as
 * MySQL's own format instead of the ISO 8601 doc §7 already promises.
 */
final class DateHelperTest extends TestCase
{
    public function testConvertsMysqlDatetimeWithFractionalSecondsToIso8601(): void
    {
        self::assertSame('2026-09-13T08:34:28.127Z', DateHelper::iso('2026-09-13 08:34:28.127'));
    }

    public function testConvertsMysqlDatetimeWithoutFractionalSecondsToIso8601(): void
    {
        self::assertSame('2026-09-13T08:34:28Z', DateHelper::iso('2026-09-13 08:34:28'));
    }

    /** Safari's Date parser accepts this exact shape; it rejects MySQL's space-separated one. */
    public function testTheResultParsesAsAValidDate(): void
    {
        $iso = DateHelper::iso('2026-09-13 08:34:28.127');

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $iso);

        self::assertNotFalse($parsed);
        self::assertSame('2026-09-13', $parsed->format('Y-m-d'));
    }
}
