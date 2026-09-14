<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Auth\Role;
use Rajdhani\Auth\RolePolicy;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Http\Request;
use Rajdhani\Kernel;
use Rajdhani\Mail\AdminPasswordResetMail;
use Rajdhani\Repositories\AdminUserRepository;
use Rajdhani\Repositories\LoginAttemptRepository;
use Rajdhani\Repositories\RefreshTokenRepository;
use Rajdhani\Support\Mailer;

/**
 * Admin credentials, sessions and password lifecycle (doc 7.2).
 *
 * The rule that shapes almost every method here: **an authentication failure
 * reveals nothing about why.** Wrong password, no such account, account
 * deactivated, invite never accepted — all produce the same message and the same
 * status. An attacker who can distinguish them gets a free account-enumeration
 * oracle, and "this email is registered here" is itself a disclosure.
 *
 * The exception is the throttle, which has to say it is a throttle so a locked
 * out admin knows to wait rather than to keep guessing.
 */
final class AdminAuthService
{
    private const AUDIENCE = 'admin';

    public function __construct(
        private readonly AdminUserRepository $admins = new AdminUserRepository(),
        private readonly LoginAttemptRepository $attempts = new LoginAttemptRepository(),
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
        private readonly TokenService $tokens = new TokenService(),
        private readonly Mailer $mailer = new Mailer(),
    ) {
    }

    /**
     * @return array{admin:array<string,mixed>,tokens:array{access_token:string,expires_in:int,refresh_expires_at:int}}
     */
    public function login(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');

        $this->assertNotThrottled($email, $request->ip);

        $admin = $this->admins->findByEmail($email);
        $passwordHash = is_string($admin['password_hash'] ?? null) ? (string) $admin['password_hash'] : null;

        // Verification runs even when there is no such account, so the response
        // time does not distinguish the two. PasswordHelper::verify() burns
        // equivalent work against a decoy hash for the null case.
        $verified = PasswordHelper::verify($password, $passwordHash);

        // An account with no password has an unaccepted invite (doc 7.2). It is
        // refused here, not merely by the hash check, and it is refused with the
        // same error — the invite link is the only way in, and telling a caller
        // that an unclaimed account exists is exactly the disclosure to avoid.
        $active = $admin !== null && (int) $admin['is_active'] === 1;

        if ($admin === null || !$verified || !$active) {
            $this->attempts->record($email, self::AUDIENCE, $request->ip, false);

            throw ApiError::unauthenticated('Email or password is incorrect');
        }

        $this->attempts->record($email, self::AUDIENCE, $request->ip, true);

        $adminId = (string) $admin['id'];

        // Opportunistic upgrade: if config now asks for stronger parameters than
        // this hash was made with, this login is the only moment the plaintext
        // is available to redo it.
        if ($passwordHash !== null && PasswordHelper::needsRehash($passwordHash)) {
            $this->admins->setPassword($adminId, PasswordHelper::hash($password));
        }

        $this->admins->touchLastLogin($adminId);

        return [
            'admin'  => $this->publicProfile($admin),
            'tokens' => $this->publicTokens($this->tokens->issue(
                self::AUDIENCE,
                $adminId,
                ['role' => (string) $admin['role'], 'email' => (string) $admin['email']],
                $request,
            )),
        ];
    }

    /**
     * @return array{admin:array<string,mixed>,tokens:array{access_token:string,expires_in:int,refresh_expires_at:int}}
     */
    public function refresh(Request $request): array
    {
        $consumed = $this->tokens->consumeRefresh(self::AUDIENCE, $request);
        $adminId = (string) $consumed['row']['admin_id'];
        $admin = $this->admins->findById($adminId);

        // Deactivation must take effect at the next refresh rather than
        // whenever the access token happens to expire (doc 7.2).
        if ($admin === null || (int) $admin['is_active'] !== 1) {
            $this->refreshTokens->revokeFamily($consumed['family_id']);

            throw ApiError::unauthenticated('No valid session');
        }

        return [
            'admin'  => $this->publicProfile($admin),
            'tokens' => $this->publicTokens($this->tokens->issue(
                self::AUDIENCE,
                $adminId,
                ['role' => (string) $admin['role'], 'email' => (string) $admin['email']],
                $request,

                // Same family: this is a continuation of one login, and reuse
                // detection needs the whole chain to share an identifier.
                familyId: $consumed['family_id'],
            )),
        ];
    }

    public function logout(Request $request): void
    {
        $this->tokens->revokePresented(self::AUDIENCE, $request);
    }

    /** @return array<string,mixed> */
    public function inviteDetails(string $token): array
    {
        $admin = $this->admins->findByValidInviteToken($token);

        if ($admin === null) {
            throw ApiError::notFound('This invitation is not valid or has already been used');
        }

        // Enough for the set-password screen to greet the invitee, and no more.
        return [
            'name'  => (string) $admin['name'],
            'email' => (string) $admin['email'],
            'role'  => (string) $admin['role'],
        ];
    }

    /**
     * @return array{admin:array<string,mixed>,tokens:array{access_token:string,expires_in:int,refresh_expires_at:int}}
     */
    public function acceptInvite(string $token, string $password, Request $request): array
    {
        $admin = $this->admins->findByValidInviteToken($token);

        if ($admin === null) {
            throw ApiError::notFound('This invitation is not valid or has already been used');
        }

        $this->assertPasswordAcceptable($password);

        $adminId = (string) $admin['id'];
        $this->admins->setPassword($adminId, PasswordHelper::hash($password));

        // Signed in immediately. The invitee has just proved possession of the
        // emailed token and chosen a password; making them log in again would
        // only add a step.
        $fresh = $this->admins->findById($adminId) ?? $admin;

        return [
            'admin'  => $this->publicProfile($fresh),
            'tokens' => $this->publicTokens($this->tokens->issue(
                self::AUDIENCE,
                $adminId,
                ['role' => (string) $fresh['role'], 'email' => (string) $fresh['email']],
                $request,
            )),
        ];
    }

    public function changePassword(string $adminId, string $current, string $new, Request $request): void
    {
        $admin = $this->admins->findById($adminId);

        if ($admin === null) {
            throw ApiError::unauthenticated('No valid session');
        }

        $hash = is_string($admin['password_hash'] ?? null) ? (string) $admin['password_hash'] : null;

        if (!PasswordHelper::verify($current, $hash)) {
            throw ApiError::validation('Current password is incorrect', [
                ['field' => 'current_password', 'message' => 'Current password is incorrect'],
            ]);
        }

        if (hash_equals($current, $new)) {
            throw ApiError::validation('Choose a password you have not used here before', [
                ['field' => 'new_password', 'message' => 'The new password must differ from the current one'],
            ]);
        }

        $this->assertPasswordAcceptable($new, 'new_password');
        $this->admins->setPassword($adminId, PasswordHelper::hash($new));

        // Every session ends (doc 7.2) — including this one. A password change
        // is the response to a suspected compromise, and leaving other devices
        // signed in would defeat it.
        $this->refreshTokens->revokeAllForAdmin($adminId);
        $this->tokens->revokePresented(self::AUDIENCE, $request);
    }

    /**
     * Begin a password reset. Always succeeds from the caller's point of view,
     * whether or not the address belongs to an account — otherwise this endpoint
     * is an account-existence oracle that needs no credentials at all.
     */
    public function forgotPassword(string $email): void
    {
        $admin = $this->admins->findByEmail(mb_strtolower(trim($email), 'UTF-8'));

        if ($admin === null || (int) $admin['is_active'] !== 1) {
            return;
        }

        $token = PasswordHelper::newToken();
        $ttlHours = (int) config('auth.reset_ttl_hours', 1);

        $this->admins->setResetToken((string) $admin['id'], $token, $ttlHours * 3600);

        // Always logged, mail or no mail — the token is deliberately never
        // returned in the response body: that would make this endpoint an
        // account takeover for anyone who can guess an address.
        Kernel::logger()->info('Admin password reset requested', [
            'admin_id' => (string) $admin['id'],
            'email'    => (string) $admin['email'],
            'token'    => $token,
            'expires'  => "{$ttlHours}h",
        ]);

        // Mail delivery, wired up with the notification work (RTPP-33,
        // §14.4) — this is the "mail delivery is wired up with the
        // notification work" this method's own comment used to promise.
        // `Mailer::send()` never throws, and this method's own contract is
        // "always succeeds from the caller's point of view" regardless, so
        // no try/catch is added here beyond what Mailer already provides.
        $resetUrl = rtrim((string) config('app.admin_url'), '/') . '/reset-password?token=' . $token;
        $mail = new AdminPasswordResetMail($resetUrl, $ttlHours);
        $this->mailer->send([(string) $admin['email']], $mail->subject(), $mail->html());
    }

    public function resetPassword(string $token, string $password): void
    {
        $admin = $this->admins->findByValidResetToken($token);

        if ($admin === null) {
            throw ApiError::notFound('This reset link is not valid or has expired');
        }

        $this->assertPasswordAcceptable($password);

        $adminId = (string) $admin['id'];
        $this->admins->setPassword($adminId, PasswordHelper::hash($password));

        // The token is single-use because setPassword() clears it, and every
        // existing session dies for the same reason as changePassword().
        $this->refreshTokens->revokeAllForAdmin($adminId);
    }

    /** @return array<string,mixed> */
    public function profile(string $adminId): array
    {
        $admin = $this->admins->findById($adminId);

        if ($admin === null) {
            throw ApiError::unauthenticated('No valid session');
        }

        return $this->publicProfile($admin);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updateProfile(string $adminId, array $input): array
    {
        // Allowlist, not a filter on the request body. Role, email and
        // is_active are all present on the row and none of them may be changed
        // by their owner — an admin who could edit their own role would need no
        // other privilege escalation.
        $allowed = ['name', 'phone', 'avatar_id'];
        $fields = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $input)) {
                continue;
            }

            $value = $input[$column];

            if ($value !== null && !is_scalar($value)) {
                throw ApiError::validation("{$column} must be a string", [
                    ['field' => $column, 'message' => 'Expected a string'],
                ]);
            }

            $fields[$column] = $value === null ? null : trim((string) $value);
        }

        if (isset($fields['name']) && $fields['name'] === '') {
            throw ApiError::validation('Name cannot be empty', [
                ['field' => 'name', 'message' => 'Name is required'],
            ]);
        }

        $this->admins->updateProfile($adminId, $fields);

        return $this->profile($adminId);
    }

    /**
     * Doc 7.2: five failures per fifteen minutes, then a thirty-minute lockout.
     *
     * Counted per email *and* per IP — see LoginAttemptRepository for why both.
     * The IP limit is deliberately looser: an office behind one NAT address is a
     * legitimate reason for several admins to fail at once, and locking a whole
     * building out of the dashboard is its own outage.
     */
    private function assertNotThrottled(string $email, string $ip): void
    {
        /** @var array<string,mixed> $limits */
        $limits = config('auth.rate_limits.admin_login', []);
        $max = is_numeric($limits['attempts'] ?? null) ? (int) $limits['attempts'] : 5;
        $window = is_numeric($limits['per_seconds'] ?? null) ? (int) $limits['per_seconds'] : 900;
        $lockout = is_numeric($limits['lockout_seconds'] ?? null) ? (int) $limits['lockout_seconds'] : 1800;

        if ($this->attempts->failuresForEmail($email, self::AUDIENCE, $window) >= $max) {
            $wait = $this->attempts->secondsUntilUnlock($email, self::AUDIENCE, $window, $lockout);

            throw ApiError::rateLimited(
                $wait > 0
                    ? sprintf('Too many failed attempts. Try again in %d minutes.', (int) ceil($wait / 60))
                    : 'Too many failed attempts. Try again shortly.',
            );
        }

        if ($ip !== '' && $this->attempts->failuresForIp($ip, self::AUDIENCE, $window) >= $max * 4) {
            throw ApiError::rateLimited('Too many failed attempts from this address. Try again later.');
        }
    }

    private function assertPasswordAcceptable(string $password, string $field = 'password'): void
    {
        $violations = PasswordHelper::policyViolations($password, $field);

        if ($violations !== []) {
            throw ApiError::validation('Password does not meet the requirements', $violations);
        }
    }

    /**
     * What the response body may contain.
     *
     * The refresh token is removed here and nowhere else. It travels in an
     * HttpOnly cookie precisely so that script cannot read it; echoing it into
     * the JSON body as well would hand it straight back to the JavaScript the
     * cookie was protecting it from.
     *
     * @param array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_at:int} $issued
     *
     * @return array{access_token:string,expires_in:int,refresh_expires_at:int}
     */
    private function publicTokens(array $issued): array
    {
        return [
            'access_token'       => $issued['access_token'],
            'expires_in'         => $issued['expires_in'],
            'refresh_expires_at' => $issued['refresh_expires_at'],
        ];
    }

    /**
     * The shape returned to the dashboard. Everything sensitive is dropped here
     * rather than at the controller, so no route can leak a hash or a live
     * invite token by forgetting to filter.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function publicProfile(array $row): array
    {
        $role = Role::tryFromClaim($row['role'] ?? null);

        return [
            'id'            => (string) $row['id'],
            'name'          => (string) $row['name'],
            'email'         => (string) $row['email'],
            'role'          => (string) $row['role'],
            'phone'         => $row['phone'] === null ? null : (string) $row['phone'],
            'avatar_id'     => $row['avatar_id'] === null ? null : (string) $row['avatar_id'],
            'is_active'     => (int) $row['is_active'] === 1,
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'created_at'    => (string) $row['created_at'],

            // The §7.3 matrix row for this admin, served from the same constant
            // RequireRole enforces. §7.3 says the dashboard hides unavailable
            // navigation but the API is the source of truth — sending the
            // effective permissions is what stops a hidden button and a 403
            // from ever disagreeing. It is a convenience for the UI, never a
            // substitute for the server-side check.
            'permissions' => $role === null ? [] : RolePolicy::permissionsFor($role),
        ];
    }
}
