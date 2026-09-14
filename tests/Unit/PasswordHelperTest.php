<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Support\Env;

final class PasswordHelperTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);

        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('This PHP build has no argon2id; see RTPP-90.');
        }
    }

    public function testHashesWithArgon2idAndVerifies(): void
    {
        $hash = PasswordHelper::hash('Rajdhani#Tea2026');

        // The algorithm is visible in the hash prefix, so this asserts the
        // actual algorithm used rather than that *some* hashing happened.
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(PasswordHelper::verify('Rajdhani#Tea2026', $hash));
        self::assertFalse(PasswordHelper::verify('Rajdhani#Tea2027', $hash));
    }

    public function testTheSamePasswordHashesDifferentlyEveryTime(): void
    {
        // Distinct salts. Equal hashes would mean identical passwords were
        // identifiable across accounts straight from the table.
        self::assertNotSame(
            PasswordHelper::hash('Rajdhani#Tea2026'),
            PasswordHelper::hash('Rajdhani#Tea2026'),
        );
    }

    /**
     * The state AdminSeeder leaves the first Super Admin in: the account exists
     * and has never been claimed. A null hash must fail closed.
     */
    public function testANullHashNeverVerifies(): void
    {
        self::assertFalse(PasswordHelper::verify('anything at all', null));
        self::assertFalse(PasswordHelper::verify('', null));
        self::assertFalse(PasswordHelper::verify('anything at all', ''));
    }

    public function testAcceptsAPasswordMeetingEveryRule(): void
    {
        self::assertSame([], PasswordHelper::policyViolations('Rajdhani#Tea2026'));
    }

    /** @param string[] $expectedFragments */
    #[DataProvider('policyFailures')]
    public function testReportsEveryFailedRuleAtOnce(string $password, array $expectedFragments): void
    {
        $violations = PasswordHelper::policyViolations($password);
        $messages = implode(' | ', array_column($violations, 'message'));

        self::assertCount(count($expectedFragments), $violations, $messages);

        foreach ($expectedFragments as $fragment) {
            self::assertStringContainsString($fragment, $messages);
        }
    }

    /** @return array<string,array{string,string[]}> */
    public static function policyFailures(): array
    {
        return [
            'too short'      => ['Aa1#bcd', ['at least 10 characters']],
            'no uppercase'   => ['rajdhani#tea2026', ['uppercase']],
            'no lowercase'   => ['RAJDHANI#TEA2026', ['lowercase']],
            'no digit'       => ['Rajdhani#TeaTea', ['digit']],
            'no symbol'      => ['RajdhaniTea2026', ['symbol']],
            'nothing at all' => ['aaaaaaaaaaa', ['uppercase', 'digit', 'symbol']],
        ];
    }

    /** Length is counted in characters, not bytes — ten Bangla letters is ten. */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $violations = PasswordHelper::policyViolations('Aa1#ঢাকা');
        $messages = implode(' ', array_column($violations, 'message'));

        self::assertStringContainsString('at least 10 characters', $messages);
    }

    public function testViolationsCarryTheFieldNameForTheErrorEnvelope(): void
    {
        $violations = PasswordHelper::policyViolations('short', 'new_password');

        self::assertNotSame([], $violations);

        foreach ($violations as $violation) {
            self::assertSame('new_password', $violation['field']);
        }
    }

    public function testTokensAreUniqueAndFitTheChar64Column(): void
    {
        $first = PasswordHelper::newToken();

        self::assertSame(64, strlen($first));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, PasswordHelper::newToken());
    }
}
