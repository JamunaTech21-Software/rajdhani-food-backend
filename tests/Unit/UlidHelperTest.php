<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\UlidHelper;

/**
 * ULIDs are the primary key of every table (doc 8.0). The property that earns
 * them that job is sortability — if it broke, InnoDB would go back to page
 * splitting and nothing would visibly fail. Hence these tests.
 */
final class UlidHelperTest extends TestCase
{
    public function testIsTwentySixCrockfordCharacters(): void
    {
        $ulid = UlidHelper::generate();

        self::assertSame(26, strlen($ulid));
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $ulid);
        self::assertTrue(UlidHelper::isValid($ulid));
    }

    public function testSortsInGenerationOrderAcrossMilliseconds(): void
    {
        $first = UlidHelper::generate(1_700_000_000_000);
        $second = UlidHelper::generate(1_700_000_000_001);
        $third = UlidHelper::generate(1_700_000_001_000);

        self::assertLessThan($second, $first);
        self::assertLessThan($third, $second);
    }

    public function testIsMonotonicWithinTheSameMillisecond(): void
    {
        // This is the case that matters in practice: a seeder inserting hundreds
        // of rows generates many IDs inside one millisecond.
        $ids = [];

        for ($i = 0; $i < 200; $i++) {
            $ids[] = UlidHelper::generate(1_700_000_000_000);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids, 'IDs generated in one millisecond must still sort in generation order');
        self::assertCount(200, array_unique($ids));
    }

    public function testTimestampRoundTrips(): void
    {
        $ms = 1_700_000_123_456;

        self::assertSame($ms, UlidHelper::timestampOf(UlidHelper::generate($ms)));
    }

    public function testRejectsMalformedValues(): void
    {
        self::assertFalse(UlidHelper::isValid(''));
        self::assertFalse(UlidHelper::isValid('too-short'));
        self::assertFalse(UlidHelper::isValid(str_repeat('A', 25)));
        // I, L, O and U are excluded from Crockford base32 to avoid confusion
        // with 1, 1, 0 and V.
        self::assertFalse(UlidHelper::isValid(str_repeat('I', 26)));
        // A leading character above '7' overflows the 48-bit timestamp field.
        self::assertFalse(UlidHelper::isValid('Z' . str_repeat('A', 25)));
    }
}
