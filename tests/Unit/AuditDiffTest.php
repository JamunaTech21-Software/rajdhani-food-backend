<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Support\AuditDiff;

/** RTPP-35 — the compaction rule that keeps a large rich-text field out of `audit_logs` on every unrelated edit. */
final class AuditDiffTest extends TestCase
{
    public function testACreateStoresTheWholeAfterRowWithNoBefore(): void
    {
        $diff = AuditDiff::compact(null, ['id' => '01', 'title' => 'New banner']);

        self::assertNull($diff['before']);
        self::assertSame(['id' => '01', 'title' => 'New banner'], $diff['after']);
    }

    public function testADeleteStoresTheWholeBeforeRowWithNoAfter(): void
    {
        $diff = AuditDiff::compact(['id' => '01', 'title' => 'Old banner'], null);

        self::assertSame(['id' => '01', 'title' => 'Old banner'], $diff['before']);
        self::assertNull($diff['after']);
    }

    public function testAnUpdateKeepsOnlyTheFieldsThatChanged(): void
    {
        $diff = AuditDiff::compact(
            ['id' => '01', 'title' => 'Old', 'body' => 'A very long rich-text body that must not be repeated'],
            ['id' => '01', 'title' => 'New', 'body' => 'A very long rich-text body that must not be repeated'],
        );

        self::assertSame(['title' => 'Old'], $diff['before']);
        self::assertSame(['title' => 'New'], $diff['after']);
    }

    public function testIdenticalBeforeAndAfterProducesAnEmptyDiff(): void
    {
        $row = ['id' => '01', 'title' => 'Unchanged'];

        $diff = AuditDiff::compact($row, $row);

        self::assertSame([], $diff['before']);
        self::assertSame([], $diff['after']);
    }

    public function testAFieldAddedOnlyOnOneSideCountsAsChanged(): void
    {
        $diff = AuditDiff::compact(
            ['id' => '01'],
            ['id' => '01', 'note' => 'added later'],
        );

        self::assertSame(['note' => null], $diff['before']);
        self::assertSame(['note' => 'added later'], $diff['after']);
    }
}
