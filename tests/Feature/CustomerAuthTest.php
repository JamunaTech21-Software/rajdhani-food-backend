<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Http\Request;
use Rajdhani\Repositories\CustomerRepository;
use Rajdhani\Repositories\LoginAttemptRepository;
use Rajdhani\Repositories\RefreshTokenRepository;
use Rajdhani\Services\CustomerAuthService;
use Rajdhani\Services\GoogleIdTokenVerifier;
use Rajdhani\Services\TokenService;
use Rajdhani\Support\Cookie;
use Rajdhani\Tests\Support\FakeJwkSource;

/**
 * Customer sign-in against a real database (doc §7.1).
 *
 * The Google half is a locally generated key pair rather than a stub, so a test
 * that signs in is doing full RS256 verification on the way through.
 */
final class CustomerAuthTest extends DatabaseTestCase
{
    private const CLIENT_ID = '568033856683-test.apps.googleusercontent.com';

    private FakeJwkSource $google;
    private CustomerAuthService $auth;
    private CustomerRepository $customers;
    private RefreshTokenRepository $refreshTokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->google = new FakeJwkSource();
        $this->customers = new CustomerRepository($this->db);
        $this->refreshTokens = new RefreshTokenRepository($this->db);

        $this->auth = new CustomerAuthService(
            $this->customers,
            new GoogleIdTokenVerifier($this->google, self::CLIENT_ID),
            $this->refreshTokens,
            new LoginAttemptRepository($this->db),
            new TokenService($this->refreshTokens),
        );
    }

    public function testFirstSignInCreatesTheCustomer(): void
    {
        $email = $this->uniqueEmail();

        $result = $this->auth->signInWithGoogle($this->idToken($email), $this->request());

        self::assertTrue($result['is_new']);
        self::assertSame($email, $result['customer']['email']);
        self::assertSame('Test Customer', $result['customer']['name']);

        $claims = JwtHelper::decode(
            $result['tokens']['access_token'],
            (string) config('auth.jwt.access_secret'),
            'customer',
        );
        self::assertSame('customer', $claims['aud']);
    }

    /**
     * The DoD's real content, restated for a single site: however many times the
     * same Google account signs in, there is exactly one customer row.
     *
     * (The issue words this as "on both brand domains". The second brand was
     * removed in v3.0 — doc §19, deviation 5 — so the surviving property is
     * one Google account to one record.)
     */
    public function testSigningInRepeatedlyResolvesToOneRecord(): void
    {
        $email = $this->uniqueEmail();

        $first = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $second = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $third = $this->auth->signInWithGoogle($this->idToken($email), $this->request());

        self::assertTrue($first['is_new']);
        self::assertFalse($second['is_new']);
        self::assertFalse($third['is_new']);

        self::assertSame($first['customer']['id'], $second['customer']['id']);
        self::assertSame($first['customer']['id'], $third['customer']['id']);
        self::assertSame(1, $this->countCustomers($email));
    }

    /** Doc §7.1 step 5: an account can predate Google linking. */
    public function testAnExistingEmailAccountIsLinkedRatherThanDuplicated(): void
    {
        $email = $this->uniqueEmail();
        $existingId = $this->insertCustomerWithoutGoogle($email);

        $result = $this->auth->signInWithGoogle($this->idToken($email), $this->request());

        self::assertFalse($result['is_new']);
        self::assertSame($existingId, $result['customer']['id']);
        self::assertSame(1, $this->countCustomers($email));

        $row = $this->customers->findById($existingId);
        self::assertIsArray($row);
        self::assertSame('google-' . $email, $row['google_id'], 'The Google account should now be linked');
    }

    /**
     * google_id is checked before email. If the person changes their Google
     * address, they keep their account rather than being handed a new one — or,
     * worse, somebody else's.
     */
    public function testTheGoogleAccountWinsOverAMatchingEmail(): void
    {
        $original = $this->uniqueEmail();
        $renamed = $this->uniqueEmail();

        $first = $this->auth->signInWithGoogle($this->idToken($original), $this->request());

        // Same Google subject, different address — someone changed their Gmail.
        $second = $this->auth->signInWithGoogle(
            $this->idToken($renamed, subject: 'google-' . $original),
            $this->request(),
        );

        self::assertSame($first['customer']['id'], $second['customer']['id']);
    }

    public function testNameAndAvatarAreRefreshedFromGoogleOnEachSignIn(): void
    {
        $email = $this->uniqueEmail();
        $this->auth->signInWithGoogle($this->idToken($email), $this->request());

        $result = $this->auth->signInWithGoogle(
            $this->idToken($email, overrides: ['name' => 'Renamed In Google', 'picture' => 'https://example.test/new']),
            $this->request(),
        );

        self::assertSame('Renamed In Google', $result['customer']['name']);
        self::assertSame('https://example.test/new', $result['customer']['avatar_url']);
    }

    public function testABlockedCustomerIsRefused(): void
    {
        $email = $this->uniqueEmail();
        $result = $this->auth->signInWithGoogle($this->idToken($email), $this->request());

        $this->db->exec(
            'UPDATE customers SET is_blocked = 1 WHERE id = ' . $this->db->quote($result['customer']['id'])
        );

        $error = $this->captureApiError(
            fn () => $this->auth->signInWithGoogle($this->idToken($email), $this->request())
        );

        self::assertSame(ErrorCode::FORBIDDEN, $error->errorCode());
    }

    public function testAForgedTokenNeverReachesTheDatabase(): void
    {
        $email = $this->uniqueEmail();
        $forged = $this->google->signWithForeignKey($this->claims($email));

        $this->captureApiError(fn () => $this->auth->signInWithGoogle($forged, $this->request()));

        self::assertSame(0, $this->countCustomers($email), 'A rejected token must not create an account');
    }

    // ─── sessions ───────────────────────────────────────────────────────────

    public function testRefreshRotatesAndKeepsTheSession(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $session = $this->issueSession($signIn['customer']['id'], $email);

        $refreshed = $this->auth->refresh($this->request(cookie: $session['refresh_token']));

        self::assertSame($email, $refreshed['customer']['email']);
        self::assertArrayNotHasKey('refresh_token', $refreshed['tokens']);
    }

    public function testReplayingASpentRefreshTokenRevokesTheFamily(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $session = $this->issueSession($signIn['customer']['id'], $email);

        $this->auth->refresh($this->request(cookie: $session['refresh_token']));

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::UNAUTHENTICATED->status());
        $this->auth->refresh($this->request(cookie: $session['refresh_token']));
    }

    public function testBlockingTakesEffectAtTheNextRefresh(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $session = $this->issueSession($signIn['customer']['id'], $email);

        $this->db->exec(
            'UPDATE customers SET is_blocked = 1 WHERE id = ' . $this->db->quote($signIn['customer']['id'])
        );

        $this->expectException(ApiError::class);
        $this->auth->refresh($this->request(cookie: $session['refresh_token']));
    }

    public function testLogoutRevokesThePresentedToken(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $session = $this->issueSession($signIn['customer']['id'], $email);

        $this->auth->logout($this->request(cookie: $session['refresh_token']));

        $this->expectException(ApiError::class);
        $this->auth->refresh($this->request(cookie: $session['refresh_token']));
    }

    // ─── profile ────────────────────────────────────────────────────────────

    public function testProfileUpdatesAreLimitedToTheAllowedFields(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $id = $signIn['customer']['id'];

        $updated = $this->auth->updateProfile($id, [
            'phone'        => '+880 1700-111222',
            'city'         => 'Dhaka',
            'company_name' => 'Test Traders',

            // None of these may move.
            'email'      => 'attacker@evil.test',
            'name'       => 'Attacker',
            'is_blocked' => 0,
        ]);

        self::assertSame('+880 1700-111222', $updated['phone']);
        self::assertSame('Dhaka', $updated['city']);
        self::assertSame('Test Traders', $updated['company_name']);
        self::assertSame($email, $updated['email']);
        self::assertSame('Test Customer', $updated['name']);
    }

    public function testAnEmptyStringClearsAnOptionalField(): void
    {
        $email = $this->uniqueEmail();
        $id = $this->auth->signInWithGoogle($this->idToken($email), $this->request())['customer']['id'];

        $this->auth->updateProfile($id, ['city' => 'Dhaka']);
        $cleared = $this->auth->updateProfile($id, ['city' => '']);

        self::assertNull($cleared['city']);
    }

    public function testAnUnusablePhoneNumberIsRejected(): void
    {
        $email = $this->uniqueEmail();
        $id = $this->auth->signInWithGoogle($this->idToken($email), $this->request())['customer']['id'];

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->auth->updateProfile($id, ['phone' => 'call me maybe']);
    }

    public function testTheProfileNeverExposesTheGoogleId(): void
    {
        $email = $this->uniqueEmail();
        $profile = $this->auth->signInWithGoogle($this->idToken($email), $this->request())['customer'];

        self::assertArrayNotHasKey('google_id', $profile);
        self::assertArrayNotHasKey('is_blocked', $profile);
    }

    /** Doc §9.2: deleting the account takes the personal data with it. */
    public function testDeletingTheAccountCascadesToWishlistAndSessions(): void
    {
        $email = $this->uniqueEmail();
        $signIn = $this->auth->signInWithGoogle($this->idToken($email), $this->request());
        $id = $signIn['customer']['id'];
        $this->issueSession($id, $email);
        $this->addWishlistItem($id);

        self::assertSame(1, $this->countWishlistItems($id));
        self::assertGreaterThan(0, $this->countRefreshTokens($id));

        $this->auth->deleteAccount($id, $this->request());

        self::assertSame(0, $this->countCustomers($email));
        self::assertSame(0, $this->countWishlistItems($id));
        self::assertSame(0, $this->countRefreshTokens($id), 'Sessions must go with the account');
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function uniqueEmail(): string
    {
        return 'cust-' . bin2hex(random_bytes(6)) . '@example.test';
    }

    /** @param array<string,mixed> $overrides */
    private function idToken(string $email, ?string $subject = null, array $overrides = []): string
    {
        return $this->google->sign($overrides + $this->claims($email, $subject));
    }

    /** @return array<string,mixed> */
    private function claims(string $email, ?string $subject = null): array
    {
        return [
            'iss'            => 'https://accounts.google.com',
            'aud'            => self::CLIENT_ID,
            'sub'            => $subject ?? 'google-' . $email,
            'email'          => $email,
            'email_verified' => true,
            'name'           => 'Test Customer',
            'picture'        => 'https://lh3.googleusercontent.com/a/test',
            'iat'            => time(),
            'exp'            => time() + 3600,
        ];
    }

    private function insertCustomerWithoutGoogle(string $email): string
    {
        $id = UlidHelper::generate();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $statement = $this->db->prepare(
            'INSERT INTO customers (id, google_id, email, name, email_verified, is_blocked, created_at, updated_at)
             VALUES (:id, NULL, :email, :name, 0, 0, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'         => $id,
            ':email'      => $email,
            ':name'       => 'Pre-existing Customer',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $id;
    }

    /** @return array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_at:int} */
    private function issueSession(string $customerId, string $email): array
    {
        return (new TokenService($this->refreshTokens))->issue(
            'customer',
            $customerId,
            ['email' => $email],
            $this->request(),
        );
    }

    private function addWishlistItem(string $customerId): void
    {
        $productId = $this->db->query('SELECT id FROM products LIMIT 1')?->fetchColumn();

        if (!is_string($productId)) {
            self::markTestSkipped('No products seeded; run php bin/seed.php');
        }

        $statement = $this->db->prepare(
            'INSERT INTO wishlist_items (id, customer_id, product_id, created_at) VALUES (:id, :c, :p, :now)'
        );
        $statement->execute([
            ':id'  => UlidHelper::generate(),
            ':c'   => $customerId,
            ':p'   => $productId,
            ':now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function countCustomers(string $email): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM customers WHERE email = :email');
        $statement->execute([':email' => $email]);

        return (int) $statement->fetchColumn();
    }

    private function countWishlistItems(string $customerId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM wishlist_items WHERE customer_id = :id');
        $statement->execute([':id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    private function countRefreshTokens(string $customerId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM refresh_tokens WHERE customer_id = :id');
        $statement->execute([':id' => $customerId]);

        return (int) $statement->fetchColumn();
    }

    private function request(?string $cookie = null): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/auth/customer/google';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $_GET = [];
        $_POST = [];
        $_COOKIE = $cookie === null ? [] : [Cookie::name('customer') => $cookie];

        return Request::capture();
    }

    private function captureApiError(callable $action): ApiError
    {
        try {
            $action();
        } catch (ApiError $e) {
            return $e;
        }

        self::fail('Expected an ApiError, none was thrown.');
    }
}
