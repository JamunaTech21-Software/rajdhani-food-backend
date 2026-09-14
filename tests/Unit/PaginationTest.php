<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\Pagination;

final class PaginationTest extends TestCase
{
    public function testDefaultsWhenTheQueryIsEmpty(): void
    {
        $p = Pagination::fromQuery([]);

        self::assertSame(1, $p->page);
        self::assertSame(Pagination::DEFAULT_LIMIT, $p->limit);
        self::assertSame(0, $p->offset());
    }

    public function testCapsTheLimit(): void
    {
        // The page size comes from the query string. Uncapped, it is a trivial
        // way to ask the database for the entire table.
        self::assertSame(Pagination::MAX_LIMIT, Pagination::fromQuery(['limit' => '100000'])->limit);
    }

    public function testClampsNonsenseInput(): void
    {
        self::assertSame(1, Pagination::fromQuery(['page' => '0'])->page);
        self::assertSame(1, Pagination::fromQuery(['page' => '-5'])->page);
        self::assertSame(1, Pagination::fromQuery(['limit' => '0'])->limit);
        self::assertSame(1, Pagination::fromQuery(['page' => 'abc'])->page);
    }

    public function testOffsetAndMeta(): void
    {
        $p = Pagination::fromQuery(['page' => '3', 'limit' => '12']);

        self::assertSame(24, $p->offset());
        self::assertSame(
            ['page' => 3, 'limit' => 12, 'total' => 48, 'totalPages' => 4],
            $p->meta(48),
        );
    }

    public function testZeroResultsIsZeroPages(): void
    {
        self::assertSame(0, Pagination::fromQuery([])->meta(0)['totalPages']);
    }
}
