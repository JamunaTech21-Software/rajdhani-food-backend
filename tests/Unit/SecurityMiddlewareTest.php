<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Http\Request;
use Rajdhani\Middleware\GuardQueryParameters;
use Rajdhani\Middleware\RateLimit;
use Rajdhani\Middleware\ThrottleForm;
use Rajdhani\Middleware\VerifyHuman;
use Rajdhani\Services\RecaptchaVerifier;
use Rajdhani\Support\Env;
use Rajdhani\Tests\Support\FakeRecaptchaClient;

/**
 * The §14.2 transport guards that can be tested without a database.
 *
 * The rate-limit *counting* is in RateLimitTest, which needs the shared table.
 * What is here is the routing around it: which requests are exempt, and the
 * parameter-pollution guard, neither of which touches storage.
 */
final class SecurityMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);
    }

    // ─── parameter pollution ────────────────────────────────────────────────

    public function testASingleValuedParameterPassesThrough(): void
    {
        $reached = (new GuardQueryParameters())->handle(
            $this->request('/public/layout', query: ['category' => 'green-tea']),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /**
     * PHP turns `?status[]=A&status[]=B` into an array where every caller
     * expects a string. `(string) $array` emits "Array", `strlen()` throws, and
     * a reviewer reading `$request->query('status')` sees none of it.
     */
    public function testARepeatedParameterIsRejected(): void
    {
        try {
            (new GuardQueryParameters())->handle(
                $this->request('/public/layout', query: ['status' => ['A', 'B']]),
                static fn (): string => 'reached',
            );
            self::fail('An array-valued query parameter should be refused');
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::VALIDATION_ERROR, $e->errorCode());
            self::assertSame('status', $e->details()[0]['field']);
        }
    }

    public function testNestedArraysAreRejectedToo(): void
    {
        $this->expectException(ApiError::class);

        (new GuardQueryParameters())->handle(
            $this->request('/public/layout', query: ['filter' => ['price' => ['min' => '1']]]),
            static fn (): string => 'reached',
        );
    }

    public function testAnEmptyQueryStringIsFine(): void
    {
        self::assertSame(
            'reached',
            (new GuardQueryParameters())->handle(
                $this->request('/public/layout'),
                static fn (): string => 'reached',
            ),
        );
    }

    // ─── what the global limiter skips ──────────────────────────────────────

    /**
     * An uptime monitor polls /health on a schedule from one address (§16.5).
     * Throttling it would produce exactly the alert it exists to avoid — and
     * these must be exempt *without* consulting the counter, so this passes
     * with no database at all.
     *
     * @param string $path
     */
    #[DataProvider('exemptRequests')]
    public function testExemptRequestsNeverReachTheCounter(string $method, string $path): void
    {
        // No database is configured in this test; if the middleware tried to
        // count, it would have to touch one. Reaching the handler is the proof.
        $reached = (new RateLimit())->handle(
            $this->request($path, method: $method),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /** @return array<string,array{string,string}> */
    public static function exemptRequests(): array
    {
        return [
            'liveness probe'   => ['GET', '/health'],
            'dependency probe' => ['GET', '/health/db'],

            // A preflight is the browser asking permission, not a request the
            // client chose to make. Counting it would halve every cross-origin
            // client's budget.
            'CORS preflight'   => ['OPTIONS', '/public/layout'],
        ];
    }

    /**
     * The form throttle counts submissions, not page views: a GET of the form,
     * or its preflight, must not consume one of the visitor's five.
     *
     * @param string $method
     */
    #[DataProvider('nonSubmissions')]
    public function testTheFormThrottleIgnoresNonSubmissions(string $method): void
    {
        $reached = (new ThrottleForm('enquiry'))->handle(
            $this->request('/public/enquiries', method: $method),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /** @return array<string,array{string}> */
    public static function nonSubmissions(): array
    {
        return [
            'reading the form' => ['GET'],
            'preflight'        => ['OPTIONS'],
        ];
    }

    // ─── RTPP-34: honeypot and reCAPTCHA ────────────────────────────────────

    public function testVerifyHumanIgnoresNonSubmissions(): void
    {
        $reached = (new VerifyHuman('enquiry', $this->recaptchaVerifier()))->handle(
            $this->request('/public/enquiries', method: 'GET'),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    public function testAFilledHoneypotFieldIsRejectedBeforeRecaptchaEvenRuns(): void
    {
        $client = new FakeRecaptchaClient();
        $middleware = new VerifyHuman('enquiry', new RecaptchaVerifier($client, secretKey: 'test-secret', threshold: 0.5));

        try {
            $middleware->handle(
                $this->request('/public/enquiries', method: 'POST', body: ['website' => 'http://spam.example']),
                static fn (): string => 'reached',
            );
            self::fail('A filled honeypot field should be rejected.');
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->errorCode());
        }

        // The honeypot short-circuits before the token is ever checked.
        self::assertNull($client->lastToken);
    }

    public function testAnEmptyHoneypotFieldPassesThrough(): void
    {
        $reached = (new VerifyHuman('enquiry', $this->recaptchaVerifier()))->handle(
            $this->request('/public/enquiries', method: 'POST', body: ['website' => '']),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    public function testAMissingHoneypotFieldPassesThrough(): void
    {
        $reached = (new VerifyHuman('enquiry', $this->recaptchaVerifier()))->handle(
            $this->request('/public/enquiries', method: 'POST', body: []),
            static fn (): string => 'reached',
        );

        self::assertSame('reached', $reached);
    }

    /** With reCAPTCHA unconfigured (this repo's actual state), the token check is a no-op — the request reaches the handler either way. */
    private function recaptchaVerifier(): RecaptchaVerifier
    {
        return new RecaptchaVerifier(new FakeRecaptchaClient(), secretKey: null);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    private function request(string $path, string $method = 'GET', array $query = [], array $body = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = "/api/v1{$path}";
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        unset($_SERVER['CONTENT_TYPE']);
        $_GET = $query;
        // No CONTENT_TYPE set, so Request::parseBody() falls back to $_POST
        // rather than trying to read php://input as JSON — there is no real
        // request stream to read from in a unit test.
        $_POST = $body;
        $_COOKIE = [];

        return Request::capture();
    }
}
