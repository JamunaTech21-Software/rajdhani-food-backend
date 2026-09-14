<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Services\RecaptchaVerifier;
use Rajdhani\Support\Env;
use Rajdhani\Tests\Support\FakeRecaptchaClient;

/**
 * `RecaptchaVerifier` (doc §14.2; RTPP-34) against every verdict shape a
 * real Google response could produce, plus the two resilience decisions
 * (unconfigured, transport failure) that never touch Google at all.
 *
 * No database in reach anywhere in this file: `configuredVerifier()` passes
 * both the secret key and the score threshold directly, so the default
 * `SettingsRepository` this class would otherwise construct is never
 * actually called — see the class doc on why both are constructor
 * overrides.
 */
final class RecaptchaVerifierTest extends TestCase
{
    private FakeRecaptchaClient $client;

    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);

        $this->client = new FakeRecaptchaClient();
    }

    /**
     * This repo's actual state: `RECAPTCHA_SECRET_KEY` is not in `.env` yet
     * (doc §19: a client-supplied credential, not yet provided). A
     * scripted submission with no token must still pass when the check
     * itself isn't running — honeypot and the rate limit are still active
     * independently.
     */
    public function testSkipsVerificationEntirelyWhenNotConfigured(): void
    {
        $verifier = new RecaptchaVerifier($this->client, secretKey: null);

        // Does not throw, and never touches the fake client.
        $verifier->verify('', 'enquiry', '203.0.113.5');

        self::assertNull($this->client->lastToken);
    }

    /** The DoD's own wording: "a scripted submission without a token is rejected" — once actually configured. */
    public function testAnEmptyTokenIsRejectedWhenConfigured(): void
    {
        $verifier = $this->configuredVerifier();

        $this->expectException(ApiError::class);
        $verifier->verify('', 'enquiry', '203.0.113.5');
    }

    public function testAHighScoreSuccessfulVerdictPasses(): void
    {
        $this->client->nextResult = ['success' => true, 'score' => 0.9, 'action' => 'enquiry'];
        $verifier = $this->configuredVerifier();

        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');

        self::assertSame('a-real-token', $this->client->lastToken);
        self::assertSame('203.0.113.5', $this->client->lastRemoteIp);
    }

    public function testALowScoreIsRejected(): void
    {
        $this->client->nextResult = ['success' => true, 'score' => 0.1, 'action' => 'enquiry'];
        $verifier = $this->configuredVerifier();

        $this->expectException(ApiError::class);
        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
    }

    public function testAnUnsuccessfulVerdictIsRejectedRegardlessOfScore(): void
    {
        $this->client->nextResult = ['success' => false, 'score' => 0.9, 'action' => 'enquiry'];
        $verifier = $this->configuredVerifier();

        $this->expectException(ApiError::class);
        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
    }

    /** A token minted for a different form is being replayed. */
    public function testATokenMintedForADifferentActionIsRejected(): void
    {
        $this->client->nextResult = ['success' => true, 'score' => 0.9, 'action' => 'newsletter'];
        $verifier = $this->configuredVerifier();

        $this->expectException(ApiError::class);
        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
    }

    /** Google not reporting an action at all (older integrations) is not treated as a mismatch. */
    public function testANullActionFromGoogleIsNotTreatedAsAMismatch(): void
    {
        $this->client->nextResult = ['success' => true, 'score' => 0.9, 'action' => null];
        $verifier = $this->configuredVerifier();

        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
        self::assertSame('a-real-token', $this->client->lastToken);
    }

    /**
     * A dead Google is not a reason to block a real customer — see the
     * class doc on why this fails open rather than closed.
     */
    public function testATransportFailureFailsOpen(): void
    {
        $this->client->throwOnVerify = true;
        $verifier = $this->configuredVerifier();

        // Does not throw.
        $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
        self::assertTrue(true);
    }

    /** The DoD's own wording: rejections must not reveal the threshold. */
    public function testTheRejectionMessageNamesNoInternalDetail(): void
    {
        $this->client->nextResult = ['success' => true, 'score' => 0.0, 'action' => 'enquiry'];
        $verifier = $this->configuredVerifier();

        try {
            $verifier->verify('a-real-token', 'enquiry', '203.0.113.5');
            self::fail('Expected an ApiError.');
        } catch (ApiError $e) {
            self::assertStringNotContainsString('0.', $e->getMessage());
            self::assertStringNotContainsString('threshold', strtolower($e->getMessage()));
        }
    }

    private function configuredVerifier(): RecaptchaVerifier
    {
        return new RecaptchaVerifier($this->client, secretKey: 'test-secret-key', threshold: 0.5);
    }
}
