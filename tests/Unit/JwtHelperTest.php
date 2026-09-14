<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Support\Env;

/**
 * The security properties of JwtHelper, stated as tests.
 *
 * Every case here corresponds to a way JWT verification has been broken in real
 * libraries. They are worth having as tests rather than comments because a
 * later refactor that reorders the checks would still pass a happy-path test.
 */
final class JwtHelperTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-at-least-32-characters';
    private const OTHER_SECRET = 'a-different-secret-of-similar-length-here';

    protected function setUp(): void
    {
        // EnvTest loads its own fixtures and leaves Env pointing at them.
        Env::load(TEST_ENV_PATH);
    }

    public function testRoundTripsClaims(): void
    {
        $token = JwtHelper::encode(JwtHelper::claims('admin-1', 'admin', 900, ['role' => 'EDITOR']), self::SECRET);
        $claims = JwtHelper::decode($token, self::SECRET, 'admin');

        self::assertSame('admin-1', $claims['sub']);
        self::assertSame('admin', $claims['aud']);
        self::assertSame('EDITOR', $claims['role']);
        self::assertIsInt($claims['exp']);
    }

    public function testRejectsATamperedPayload(): void
    {
        $token = JwtHelper::encode(JwtHelper::claims('admin-1', 'admin', 900, ['role' => 'SALES']), self::SECRET);
        [$header, $payload, $signature] = explode('.', $token);

        // Re-encode the payload with an escalated role, leaving the signature.
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/') . '==', true) ?: '{}', true);
        self::assertIsArray($claims);
        $claims['role'] = 'SUPER_ADMIN';
        $forged = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        JwtHelper::decode("{$header}.{$forged}.{$signature}", self::SECRET, 'admin');
    }

    public function testRejectsATokenSignedWithADifferentSecret(): void
    {
        // This is what keeps a refresh secret from minting access tokens.
        $token = JwtHelper::encode(JwtHelper::claims('admin-1', 'admin', 900), self::OTHER_SECRET);

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        JwtHelper::decode($token, self::SECRET, 'admin');
    }

    public function testRejectsTheNoneAlgorithm(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(
            JwtHelper::claims('admin-1', 'admin', 900),
            JSON_THROW_ON_ERROR
        )), '+/', '-_'), '=');

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        JwtHelper::decode("{$header}.{$payload}.", self::SECRET, 'admin');
    }

    /**
     * The property the whole two-system design rests on (doc 7): a token minted
     * for one audience is useless on the other, even though both are signed with
     * the same key.
     */
    public function testRejectsAValidTokenFromTheWrongAudience(): void
    {
        $token = JwtHelper::encode(JwtHelper::claims('user-1', 'customer', 900), self::SECRET);

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        JwtHelper::decode($token, self::SECRET, 'admin');
    }

    /**
     * Expiry is TOKEN_EXPIRED, not UNAUTHENTICATED — the client refreshes on the
     * first and gives up on the second, so the distinction is part of the API
     * contract rather than a nicety.
     */
    public function testAnExpiredTokenReportsTokenExpired(): void
    {
        $leeway = (int) config('auth.jwt.leeway_seconds', 30);
        $token = JwtHelper::encode(JwtHelper::claims('admin-1', 'admin', -($leeway + 60)), self::SECRET);

        $this->expectApiError(ErrorCode::TOKEN_EXPIRED);
        JwtHelper::decode($token, self::SECRET, 'admin');
    }

    public function testAcceptsATokenInsideTheLeewayWindow(): void
    {
        $leeway = (int) config('auth.jwt.leeway_seconds', 30);

        // Expired a second ago, but within the allowance for clock skew.
        $token = JwtHelper::encode(JwtHelper::claims('admin-1', 'admin', -1), self::SECRET);
        $claims = JwtHelper::decode($token, self::SECRET, 'admin');

        self::assertSame('admin-1', $claims['sub']);
        self::assertGreaterThan(0, $leeway);
    }

    /** @param non-empty-string $token */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        JwtHelper::decode($token, self::SECRET, 'admin');
    }

    /** @return array<string,array{string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty'            => [' '],
            'one segment'      => ['abc'],
            'two segments'     => ['abc.def'],
            'four segments'    => ['a.b.c.d'],
            'not base64'       => ['!!!.!!!.!!!'],
            'not json'         => ['aGVsbG8.aGVsbG8.aGVsbG8'],
        ];
    }

    /**
     * Deliberately not asserting a specific number: the lifetimes are
     * operator-tunable through .env, and a test that pins them would fail on a
     * machine that had legitimately shortened them. What must hold is that a
     * configured key resolves to a usable duration and an unknown one falls
     * back. DurationTest covers the parsing of both notations.
     */
    public function testTtlResolvesConfiguredKeysAndFallsBackForUnknownOnes(): void
    {
        self::assertGreaterThan(0, JwtHelper::ttl('admin_access', 999));
        self::assertGreaterThan(0, JwtHelper::ttl('admin_refresh', 999));
        self::assertSame(42, JwtHelper::ttl('no_such_key', 42));
    }

    private function expectApiError(ErrorCode $code): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode($code->status());
    }
}
