<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Http\Request;
use Rajdhani\Repositories\AdminUserRepository;
use Rajdhani\Repositories\LoginAttemptRepository;
use Rajdhani\Repositories\RefreshTokenRepository;
use Rajdhani\Services\AdminAuthService;
use Rajdhani\Services\TokenService;
use Rajdhani\Support\Cookie;

/**
 * The section 7.2 admin authentication rules, asserted against a real database.
 *
 * Each test creates its own admin inside the surrounding transaction, so nothing
 * here depends on the seeder having run or on any particular account existing.
 */
final class AdminAuthTest extends DatabaseTestCase
{
    private const PASSWORD = 'Rajdhani#Tea2026';

    private AdminAuthService $auth;
    private AdminUserRepository $admins;
    private RefreshTokenRepository $refreshTokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admins = new AdminUserRepository($this->db);
        $this->refreshTokens = new RefreshTokenRepository($this->db);

        $this->auth = new AdminAuthService(
            $this->admins,
            new LoginAttemptRepository($this->db),
            $this->refreshTokens,
            new TokenService($this->refreshTokens),
        );
    }

    // ---------------------------------------------------------------- login

    public function testLoggingInReturnsAProfileAndAnAdminAudienceToken(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);

        $result = $this->auth->login($email, self::PASSWORD, $this->request());

        self::assertSame($email, $result['admin']['email']);
        self::assertSame('SUPER_ADMIN', $result['admin']['role']);

        $claims = JwtHelper::decode(
            $result['tokens']['access_token'],
            (string) config('auth.jwt.access_secret'),
            'admin',
        );

        self::assertSame('admin', $claims['aud']);
        self::assertSame('SUPER_ADMIN', $claims['role']);
    }

    /** The refresh token belongs in the cookie only — never in a response body. */
    public function testTheResponseNeverCarriesTheRefreshToken(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);

        $result = $this->auth->login($email, self::PASSWORD, $this->request());

        self::assertArrayNotHasKey('refresh_token', $result['tokens']);
        self::assertSame(
            ['access_token', 'expires_in', 'refresh_expires_at'],
            array_keys($result['tokens']),
        );
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->login($email, 'Not#TheRightOne1', $this->request());
    }

    /**
     * The RTPP-10 hand-off: the seeded Super Admin has no password and holds an
     * invite. It must not be possible to log into it at all.
     */
    public function testAnUnclaimedInvitedAccountCannotLogIn(): void
    {
        $email = $this->createAdmin(password: null, inviteToken: PasswordHelper::newToken());

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->login($email, '', $this->request());
    }

    public function testADeactivatedAdminCannotLogIn(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD, active: false);

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->login($email, self::PASSWORD, $this->request());
    }

    /**
     * Wrong password and no-such-account must be indistinguishable, or the
     * endpoint tells an attacker which addresses are registered.
     */
    public function testAnUnknownAccountFailsIdenticallyToAWrongPassword(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);

        $wrongPassword = $this->captureApiError(fn () => $this->auth->login($email, 'Wrong#Password1', $this->request()));
        $noSuchAccount = $this->captureApiError(fn () => $this->auth->login('ghost@nowhere.test', 'Wrong#Password1', $this->request()));

        self::assertSame($wrongPassword->errorCode(), $noSuchAccount->errorCode());
        self::assertSame($wrongPassword->getMessage(), $noSuchAccount->getMessage());
    }

    // -------------------------------------------------------------- throttle

    public function testTheAccountLocksAfterTheConfiguredNumberOfFailures(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $max = (int) config('auth.rate_limits.admin_login.attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            $this->captureApiError(fn () => $this->auth->login($email, 'Wrong#Password1', $this->request()));
        }

        // Even the correct password is now refused, and with a different code
        // so the client can tell "wait" from "wrong".
        $error = $this->captureApiError(fn () => $this->auth->login($email, self::PASSWORD, $this->request()));

        self::assertSame(ErrorCode::RATE_LIMITED, $error->errorCode());
    }

    public function testOneAccountsLockoutDoesNotAffectAnother(): void
    {
        $locked = $this->createAdmin(password: self::PASSWORD);
        $other = $this->createAdmin(password: self::PASSWORD);
        $max = (int) config('auth.rate_limits.admin_login.attempts', 5);

        for ($i = 0; $i < $max + 1; $i++) {
            $this->captureApiError(fn () => $this->auth->login($locked, 'Wrong#Password1', $this->request()));
        }

        $result = $this->auth->login($other, self::PASSWORD, $this->request());

        self::assertSame($other, $result['admin']['email']);
    }

    // --------------------------------------------------------------- refresh

    /**
     * What "rotation" has to mean: the presented refresh token is spent, a
     * replacement exists, and both belong to the same family so reuse detection
     * can still see the whole chain.
     *
     * Note what is *not* asserted — that the new access token differs from the
     * old one. Two access tokens minted in the same second for the same admin
     * carry identical claims and are therefore byte-identical. That is harmless
     * (they are signed, short-lived and interchangeable) but it makes the
     * obvious assertion a false negative.
     */
    public function testRefreshRotatesTheRefreshTokenAndKeepsTheSession(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $first = $this->issueSession($email);
        $firstJti = explode('.', $first['refresh_token'], 2)[0];

        $refreshed = $this->auth->refresh($this->request(cookie: $first['refresh_token']));

        self::assertSame($email, $refreshed['admin']['email']);
        self::assertIsString($refreshed['tokens']['access_token']);

        $presented = $this->refreshTokenRow($firstJti);
        self::assertNotNull($presented['revoked_at'], 'The presented token must be spent');

        $familyId = (string) $presented['family_id'];
        $live = (int) $this->db->query(
            'SELECT COUNT(*) FROM refresh_tokens
              WHERE family_id = ' . $this->db->quote($familyId) . ' AND revoked_at IS NULL'
        )?->fetchColumn();

        self::assertSame(1, $live, 'Exactly one live token should remain in the family');
    }

    /**
     * Breach detection (doc 7.1). A refresh token is single-use, so a second
     * presentation means two parties hold it — and there is no way to tell the
     * thief's branch from the victim's, so the whole family goes.
     */
    public function testReplayingASpentRefreshTokenRevokesTheEntireFamily(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $first = $this->issueSession($email);

        // Legitimate rotation.
        $this->auth->refresh($this->request(cookie: $first['refresh_token']));

        // The stolen copy of the original.
        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->refresh($this->request(cookie: $first['refresh_token']));
    }

    public function testAfterReuseDetectionTheLegitimateBranchIsAlsoDead(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $first = $this->issueSession($email);

        $second = $this->auth->refresh($this->request(cookie: $first['refresh_token']));
        self::assertIsString($second['tokens']['access_token']);

        // Find the live token for this family, the one the honest client holds.
        $familyId = $this->currentFamilyId($email);
        $this->captureApiError(fn () => $this->auth->refresh($this->request(cookie: $first['refresh_token'])));

        $live = (int) $this->db->query(
            'SELECT COUNT(*) FROM refresh_tokens WHERE family_id = ' . $this->db->quote($familyId) . ' AND revoked_at IS NULL'
        )?->fetchColumn();

        self::assertSame(0, $live, 'Reuse detection must revoke every token in the family');
    }

    public function testRefreshFailsForADeactivatedAdmin(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $session = $this->issueSession($email);

        $this->db->exec('UPDATE admin_users SET is_active = 0 WHERE email = ' . $this->db->quote($email));

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->refresh($this->request(cookie: $session['refresh_token']));
    }

    public function testRefreshFailsWithNoCookieAtAll(): void
    {
        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->refresh($this->request());
    }

    public function testLogoutRevokesThePresentedToken(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $session = $this->issueSession($email);

        $this->auth->logout($this->request(cookie: $session['refresh_token']));

        $this->expectApiError(ErrorCode::UNAUTHENTICATED);
        $this->auth->refresh($this->request(cookie: $session['refresh_token']));
    }

    /**
     * RTPP-13's third exit criterion: a deactivated admin's token stops working
     * within one access-token lifetime.
     *
     * Two halves, because RequireAdmin does no database lookup (doc §7.2 accepts
     * that trade explicitly — see the middleware):
     *
     *   1. The session cannot be *extended*. Refresh fails the moment the
     *      account is deactivated, so no further access token is ever minted.
     *   2. The access token already in the client's hands dies on its own
     *      schedule, and that schedule is bounded by the configured lifetime.
     *
     * Together those bound the exposure at one access-token lifetime, which is
     * what the criterion asks for.
     */
    public function testDeactivationEndsTheSessionWithinOneAccessTokenLifetime(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $session = $this->issueSession($email);

        $this->db->exec('UPDATE admin_users SET is_active = 0 WHERE email = ' . $this->db->quote($email));

        $refused = $this->captureApiError(
            fn () => $this->auth->refresh($this->request(cookie: $session['refresh_token']))
        );
        self::assertSame(ErrorCode::UNAUTHENTICATED, $refused->errorCode(), 'The session must not be extendable');

        // Nothing live is left to extend it with, either.
        $live = (int) $this->db->query(
            'SELECT COUNT(*) FROM refresh_tokens WHERE admin_id = (
                 SELECT id FROM admin_users WHERE email = ' . $this->db->quote($email) . '
             ) AND revoked_at IS NULL'
        )?->fetchColumn();
        self::assertSame(0, $live);

        // And the outstanding access token expires within the documented window.
        $lifetime = JwtHelper::ttl('admin_access', 1200);
        self::assertLessThanOrEqual(1200, $lifetime, 'Doc §7.2 caps the admin access token at 20 minutes');

        $expired = JwtHelper::encode(
            JwtHelper::claims($this->adminId($email), 'admin', -($lifetime + 120), ['role' => 'SUPER_ADMIN']),
            (string) config('auth.jwt.access_secret'),
        );

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::TOKEN_EXPIRED->status());
        JwtHelper::decode($expired, (string) config('auth.jwt.access_secret'), 'admin');
    }

    // ---------------------------------------------------------------- invite

    public function testAcceptingAnInviteSetsAPasswordAndSignsTheInviteeIn(): void
    {
        $token = PasswordHelper::newToken();
        $email = $this->createAdmin(password: null, inviteToken: $token);

        $result = $this->auth->acceptInvite($token, self::PASSWORD, $this->request());

        self::assertSame($email, $result['admin']['email']);
        self::assertIsString($result['tokens']['access_token']);

        // And the account now works normally.
        $login = $this->auth->login($email, self::PASSWORD, $this->request());
        self::assertSame($email, $login['admin']['email']);
    }

    public function testAnInviteIsSingleUse(): void
    {
        $token = PasswordHelper::newToken();
        $this->createAdmin(password: null, inviteToken: $token);

        $this->auth->acceptInvite($token, self::PASSWORD, $this->request());

        $this->expectApiError(ErrorCode::NOT_FOUND);
        $this->auth->acceptInvite($token, 'Another#Password1', $this->request());
    }

    public function testAnExpiredInviteIsRefused(): void
    {
        $token = PasswordHelper::newToken();
        $this->createAdmin(password: null, inviteToken: $token, inviteExpiresInSeconds: -60);

        $this->expectApiError(ErrorCode::NOT_FOUND);
        $this->auth->acceptInvite($token, self::PASSWORD, $this->request());
    }

    public function testAnInviteWillNotAcceptAPasswordBelowPolicy(): void
    {
        $token = PasswordHelper::newToken();
        $this->createAdmin(password: null, inviteToken: $token);

        $this->expectApiError(ErrorCode::VALIDATION_ERROR);
        $this->auth->acceptInvite($token, 'short', $this->request());
    }

    // ------------------------------------------------------------- passwords

    public function testChangingThePasswordEndsEverySession(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $laptop = $this->issueSession($email);
        $phone = $this->issueSession($email);

        $adminId = $this->adminId($email);
        $this->auth->changePassword($adminId, self::PASSWORD, 'Rajdhani#Tea2027', $this->request());

        foreach ([$laptop, $phone] as $session) {
            $error = $this->captureApiError(
                fn () => $this->auth->refresh($this->request(cookie: $session['refresh_token']))
            );
            self::assertSame(ErrorCode::UNAUTHENTICATED, $error->errorCode());
        }

        $login = $this->auth->login($email, 'Rajdhani#Tea2027', $this->request());
        self::assertSame($email, $login['admin']['email']);
    }

    public function testChangingThePasswordRequiresTheCurrentOne(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);

        $this->expectApiError(ErrorCode::VALIDATION_ERROR);
        $this->auth->changePassword($this->adminId($email), 'Wrong#Password1', 'Rajdhani#Tea2027', $this->request());
    }

    public function testResetTokenIsSingleUseAndEndsEverySession(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD);
        $session = $this->issueSession($email);

        $this->auth->forgotPassword($email);

        $token = $this->db->query(
            'SELECT reset_token FROM admin_users WHERE email = ' . $this->db->quote($email)
        )?->fetchColumn();
        self::assertIsString($token);

        $this->auth->resetPassword($token, 'Rajdhani#Tea2028');

        $sessionDead = $this->captureApiError(
            fn () => $this->auth->refresh($this->request(cookie: $session['refresh_token']))
        );
        self::assertSame(ErrorCode::UNAUTHENTICATED, $sessionDead->errorCode());

        $replayed = $this->captureApiError(fn () => $this->auth->resetPassword($token, 'Rajdhani#Tea2029'));
        self::assertSame(ErrorCode::NOT_FOUND, $replayed->errorCode());
    }

    /** An unknown address must not be distinguishable from a known one. */
    public function testForgotPasswordSaysNothingAboutWhetherTheAccountExists(): void
    {
        $this->auth->forgotPassword('definitely-not-registered@nowhere.test');

        self::assertTrue(true, 'forgotPassword must not throw for an unknown address');
    }

    // --------------------------------------------------------------- profile

    public function testProfileUpdatesCannotChangeRoleOrEmail(): void
    {
        $email = $this->createAdmin(password: self::PASSWORD, role: 'SALES');
        $adminId = $this->adminId($email);

        $updated = $this->auth->updateProfile($adminId, [
            'name'      => 'Renamed',
            'role'      => 'SUPER_ADMIN',
            'email'     => 'attacker@evil.test',
            'is_active' => 1,
        ]);

        self::assertSame('Renamed', $updated['name']);
        self::assertSame('SALES', $updated['role']);
        self::assertSame($email, $updated['email']);
    }

    // ----------------------------------------------------------------- helpers

    private function createAdmin(
        ?string $password,
        string $role = 'SUPER_ADMIN',
        bool $active = true,
        ?string $inviteToken = null,
        int $inviteExpiresInSeconds = 172800,
    ): string {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@rajdhani.test';
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $inviteExpires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(($inviteExpiresInSeconds >= 0 ? '+' : '-') . abs($inviteExpiresInSeconds) . ' seconds')
            ->format('Y-m-d H:i:s.v');

        $statement = $this->db->prepare(
            'INSERT INTO admin_users
                (id, name, email, password_hash, role, is_active, invite_token, invite_expires_at,
                 created_at, updated_at)
             VALUES (:id, :name, :email, :hash, :role, :active, :invite, :invite_expires,
                     :created_at, :updated_at)'
        );

        $statement->execute([
            ':id'             => UlidHelper::generate(),
            ':name'           => 'Test Admin',
            ':email'          => $email,
            ':hash'           => $password === null ? null : PasswordHelper::hash($password),
            ':role'           => $role,
            ':active'         => $active ? 1 : 0,
            ':invite'         => $inviteToken,
            ':invite_expires' => $inviteToken === null ? null : $inviteExpires,

            // PDO::ATTR_EMULATE_PREPARES is false, so a named placeholder
            // cannot appear twice in one statement — hence two, not one :now.
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $email;
    }

    /** @return array<string,mixed> */
    private function refreshTokenRow(string $jti): array
    {
        $statement = $this->db->prepare(
            'SELECT jti, family_id, revoked_at, expires_at FROM refresh_tokens WHERE jti = :jti'
        );
        $statement->execute([':jti' => $jti]);
        $row = $statement->fetch();

        self::assertIsArray($row, "No refresh_tokens row for jti {$jti}");

        return $row;
    }

    private function adminId(string $email): string
    {
        $id = $this->db->query('SELECT id FROM admin_users WHERE email = ' . $this->db->quote($email))?->fetchColumn();

        self::assertIsString($id);

        return $id;
    }

    private function currentFamilyId(string $email): string
    {
        $familyId = $this->db->query(
            'SELECT family_id FROM refresh_tokens WHERE admin_id = (
                 SELECT id FROM admin_users WHERE email = ' . $this->db->quote($email) . '
             ) ORDER BY created_at DESC LIMIT 1'
        )?->fetchColumn();

        self::assertIsString($familyId);

        return $familyId;
    }

    /**
     * A session created through the token service, so the raw refresh token is
     * available to the test — the service strips it from anything a controller
     * would return.
     *
     * @return array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_at:int}
     */
    private function issueSession(string $email): array
    {
        return (new TokenService($this->refreshTokens))->issue(
            'admin',
            $this->adminId($email),
            ['role' => 'SUPER_ADMIN', 'email' => $email],
            $this->request(),
        );
    }

    private function request(?string $cookie = null): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/auth/admin/login';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $_GET = [];
        $_POST = [];
        $_COOKIE = $cookie === null ? [] : [Cookie::name('admin') => $cookie];

        return Request::capture();
    }

    private function expectApiError(ErrorCode $code): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode($code->status());
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
