<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\SlugHelper;

final class SlugHelperTest extends TestCase
{
    public function testLowercasesAndHyphenates(): void
    {
        self::assertSame('rajdhani-premium-tea', SlugHelper::make('Rajdhani Premium Tea'));
    }

    public function testCollapsesPunctuationAndTrimsEdges(): void
    {
        self::assertSame('gold-blend-500g', SlugHelper::make('  Gold Blend — 500g!!  '));
        self::assertSame('a-b', SlugHelper::make('a///b'));
    }

    public function testNeverReturnsAnEmptySlug(): void
    {
        // A product named entirely in punctuation still needs a usable URL.
        self::assertSame('item', SlugHelper::make('!!!'));
        self::assertSame('item', SlugHelper::make(''));
    }

    public function testRespectsTheColumnLength(): void
    {
        $slug = SlugHelper::make(str_repeat('long ', 100));

        self::assertLessThanOrEqual(180, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public function testAppendsASuffixUntilFree(): void
    {
        $taken = ['premium-tea' => true, 'premium-tea-2' => true];

        self::assertSame(
            'premium-tea-3',
            SlugHelper::unique('Premium Tea', static fn (string $s): bool => isset($taken[$s])),
        );
    }

    public function testReturnsTheBaseWhenNothingIsTaken(): void
    {
        self::assertSame('premium-tea', SlugHelper::unique('Premium Tea', static fn (): bool => false));
    }
}
