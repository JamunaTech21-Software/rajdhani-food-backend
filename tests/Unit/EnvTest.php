<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Support\Env;
use RuntimeException;

final class EnvTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/rajdhani-env-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
        Env::load($this->path);
    }

    public function testReadsSimplePairsAndIgnoresCommentsAndBlanks(): void
    {
        $this->write("# a comment\n\nAPP_ENV=local\n  DB_PORT = 3307 \n");

        self::assertSame('local', Env::get('APP_ENV'));
        self::assertSame('3307', Env::get('DB_PORT'));
    }

    public function testStripsSurroundingQuotesAndTrailingComments(): void
    {
        $this->write("A=\"quoted value\"\nB='single'\nC=bare # trailing\n");

        self::assertSame('quoted value', Env::get('A'));
        self::assertSame('single', Env::get('B'));
        self::assertSame('bare', Env::get('C'));
    }

    public function testRequireNamesTheMissingVariable(): void
    {
        // A configuration mistake should stop the process at boot with an
        // actionable message, not surface later as a null dereference.
        $this->write("APP_ENV=local\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/JWT_ACCESS_SECRET/');

        Env::require('JWT_ACCESS_SECRET');
    }

    public function testRequireTreatsAnEmptyValueAsMissing(): void
    {
        $this->write("JWT_ACCESS_SECRET=\n");

        $this->expectException(RuntimeException::class);
        Env::require('JWT_ACCESS_SECRET');
    }

    public function testBoolAndIntCoercion(): void
    {
        $this->write("T1=true\nT2=1\nT3=on\nF1=false\nF2=0\nN=42\nBAD=abc\n");

        self::assertTrue(Env::bool('T1'));
        self::assertTrue(Env::bool('T2'));
        self::assertTrue(Env::bool('T3'));
        self::assertFalse(Env::bool('F1'));
        self::assertFalse(Env::bool('F2'));
        self::assertTrue(Env::bool('ABSENT', true));
        self::assertSame(42, Env::int('N', 0));
        self::assertSame(7, Env::int('BAD', 7));
    }

    public function testListSplitsAndTrims(): void
    {
        $this->write("CORS_ORIGINS=https://a.com, https://b.com ,,\n");

        self::assertSame(['https://a.com', 'https://b.com'], Env::list('CORS_ORIGINS'));
        self::assertSame([], Env::list('ABSENT'));
    }

    public function testMissingFileYieldsNoVariablesRatherThanCrashing(): void
    {
        Env::load($this->path . '-does-not-exist');

        self::assertNull(Env::get('ANYTHING'));
    }
}
