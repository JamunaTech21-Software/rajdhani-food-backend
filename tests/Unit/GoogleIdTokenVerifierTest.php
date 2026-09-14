<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Services\GoogleIdTokenVerifier;
use Rajdhani\Support\Env;
use Rajdhani\Tests\Support\FakeJwkSource;

/**
 * Every check in GoogleIdTokenVerifier, stated as an attack it defeats.
 *
 * The tokens here are genuinely signed with a genuinely generated RSA key, so
 * these exercise the real signature path rather than a stubbed "it's valid".
 */
final class GoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '568033856683-test.apps.googleusercontent.com';

    private FakeJwkSource $keys;
    private GoogleIdTokenVerifier $verifier;

    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);

        $this->keys = new FakeJwkSource();
        $this->verifier = new GoogleIdTokenVerifier($this->keys, self::CLIENT_ID);
    }

    public function testAcceptsAWellFormedToken(): void
    {
        $profile = $this->verifier->verify($this->keys->sign($this->claims()));

        self::assertSame('google-subject-123', $profile['sub']);
        self::assertSame('customer@example.com', $profile['email']);
        self::assertSame('Test Customer', $profile['name']);
        self::assertSame('https://lh3.googleusercontent.com/a/test', $profile['picture']);
    }

    public function testLowercasesTheEmail(): void
    {
        // Email is a unique key in this schema; two cases must not become two
        // accounts.
        $profile = $this->verifier->verify($this->keys->sign($this->claims(['email' => 'Customer@Example.COM'])));

        self::assertSame('customer@example.com', $profile['email']);
    }

    // ─── signature ──────────────────────────────────────────────────────────

    public function testRejectsATokenSignedWithSomebodyElsesKey(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->signWithForeignKey($this->claims()));
    }

    public function testRejectsATamperedPayload(): void
    {
        $token = $this->keys->sign($this->claims());
        [$header, , $signature] = explode('.', $token);

        // Swap in a different Google subject, keeping the original signature.
        $forgedPayload = rtrim(strtr(base64_encode(json_encode(
            $this->claims(['sub' => 'somebody-else', 'email' => 'victim@example.com']),
            JSON_THROW_ON_ERROR,
        )), '+/', '-_'), '=');

        $this->expectUnauthenticated();
        $this->verifier->verify("{$header}.{$forgedPayload}.{$signature}");
    }

    public function testRejectsTheNoneAlgorithm(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(
            ['alg' => 'none', 'kid' => $this->keys->keyId, 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        )), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode($this->claims(), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectUnauthenticated();
        $this->verifier->verify("{$header}.{$payload}.");
    }

    public function testRejectsAnUnknownKeyId(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->sign($this->claims(), keyId: 'a-key-that-does-not-exist'));
    }

    /**
     * A key id the cache has not seen is far more likely a rotation than a
     * forgery, so the verifier refetches once before giving up.
     */
    public function testRefetchesOnceWhenTheKeyIdIsUnknown(): void
    {
        try {
            $this->verifier->verify($this->keys->sign($this->claims(), keyId: 'rotated-in-key'));
        } catch (ApiError) {
            // expected
        }

        self::assertSame(2, $this->keys->fetchCount, 'Expected one cached read and one forced refresh');
    }

    // ─── claims ─────────────────────────────────────────────────────────────

    /**
     * The check people leave out. This token is real, correctly signed by
     * Google, and completely valid — for someone else's application. Accepting
     * it would let any other site's sign-in become ours.
     */
    public function testRejectsAValidTokenIssuedForADifferentApplication(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->sign($this->claims([
            'aud' => '999999-somebodyelse.apps.googleusercontent.com',
        ])));
    }

    public function testAcceptsAnAudienceArrayContainingOurClientId(): void
    {
        $profile = $this->verifier->verify($this->keys->sign($this->claims([
            'aud' => ['999999-other.apps.googleusercontent.com', self::CLIENT_ID],
        ])));

        self::assertSame('google-subject-123', $profile['sub']);
    }

    public function testRejectsAnIssuerThatIsNotGoogle(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->sign($this->claims(['iss' => 'https://accounts.evil.example'])));
    }

    public function testAcceptsBothGoogleIssuerSpellings(): void
    {
        foreach (['https://accounts.google.com', 'accounts.google.com'] as $issuer) {
            $profile = $this->verifier->verify($this->keys->sign($this->claims(['iss' => $issuer])));
            self::assertSame('google-subject-123', $profile['sub'], "issuer {$issuer} should be accepted");
        }
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->sign($this->claims(['exp' => time() - 3600])));
    }

    public function testRejectsATokenIssuedInTheFuture(): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($this->keys->sign($this->claims(['iat' => time() + 3600])));
    }

    /**
     * Without this check, anyone could register a Google account against an
     * address they do not control and then claim the existing customer record
     * that email-linking would hand them.
     */
    public function testRejectsAnUnverifiedEmail(): void
    {
        $this->expectException(ApiError::class);
        $this->verifier->verify($this->keys->sign($this->claims(['email_verified' => false])));
    }

    public function testAcceptsEmailVerifiedAsTheStringTrue(): void
    {
        // Google sends a boolean in some flows and the string in others.
        $profile = $this->verifier->verify($this->keys->sign($this->claims(['email_verified' => 'true'])));

        self::assertSame('customer@example.com', $profile['email']);
    }

    public function testFallsBackToTheEmailLocalPartWhenNameIsMissing(): void
    {
        $claims = $this->claims();
        unset($claims['name']);

        self::assertSame('customer', $this->verifier->verify($this->keys->sign($claims))['name']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->expectUnauthenticated();
        $this->verifier->verify($token);
    }

    /** @return array<string,array{string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty'        => [' '],
            'one segment'  => ['abc'],
            'two segments' => ['abc.def'],
            'not base64'   => ['!!!.!!!.!!!'],
            'not json'     => ['aGVsbG8.aGVsbG8.aGVsbG8'],
        ];
    }

    /**
     * A missing client id must stop the process, not silently skip the audience
     * check — which would accept a Google token minted for any application.
     */
    public function testRefusesToVerifyWithoutAConfiguredClientId(): void
    {
        $verifier = new GoogleIdTokenVerifier($this->keys, '');

        $this->expectException(\RuntimeException::class);
        $verifier->verify($this->keys->sign($this->claims()));
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function claims(array $overrides = []): array
    {
        return $overrides + [
            'iss'            => 'https://accounts.google.com',
            'aud'            => self::CLIENT_ID,
            'sub'            => 'google-subject-123',
            'email'          => 'customer@example.com',
            'email_verified' => true,
            'name'           => 'Test Customer',
            'picture'        => 'https://lh3.googleusercontent.com/a/test',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ];
    }

    private function expectUnauthenticated(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::UNAUTHENTICATED->status());
    }
}
