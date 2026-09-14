<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * The first Super Admin (doc 7.2, 8.3).
 *
 * Deliberately created **without a password**. `admin_users.password_hash` is
 * nullable precisely so that this row can exist before anyone has chosen
 * credentials: the account holds a single-use invite token, and the login
 * endpoint must reject it until that invite has been accepted. Seeding a known
 * default password instead — the usual shortcut — would put a working
 * `admin@…` / `admin123` on a public host the moment the site is deployed, and
 * nothing later in the project would notice it was still there.
 *
 * The invite token is printed by the runner rather than emailed, because SMTP
 * is not configured yet (RTPP-17). Accepting it is RTPP-11's endpoint; until
 * then the token is simply the record that this account is unclaimed.
 *
 * The address comes from SEED_ADMIN_EMAIL so that staging and production do not
 * share an account, and the invite is given a long window because the gap
 * between seeding and someone actually claiming it is measured in days here.
 */
final class AdminSeeder extends Seeder
{
    private const INVITE_VALID_DAYS = 30;

    public function tables(): array
    {
        return ['admin_users'];
    }

    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL', 'admin@rajdhanifood.com') ?? 'admin@rajdhanifood.com';
        $name = env('SEED_ADMIN_NAME', 'Super Admin') ?? 'Super Admin';
        $now = $this->now();

        $this->insertIfAbsent(
            'admin_users',
            ['email' => $email],
            [
                'name' => $name,

                // Not "no password yet, we will set one" — the column stays NULL
                // until the invite is accepted, and that NULL is what the login
                // check keys off.
                'password_hash'     => null,
                'role'              => 'SUPER_ADMIN',
                'avatar_id'         => null,
                'phone'             => null,
                'is_active'         => 1,
                'last_login_at'     => null,
                'invite_token'      => bin2hex(random_bytes(32)),
                'invite_expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->modify('+' . self::INVITE_VALID_DAYS . ' days')
                    ->format('Y-m-d H:i:s.v'),
                'reset_token'      => null,
                'reset_expires_at' => null,

                // No creator: this account precedes every other one.
                'created_by_id' => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        );
    }

    /**
     * The unclaimed invite, for the runner to print. Null once the invite has
     * been accepted, which is the signal that the account is live.
     *
     * @return array{email:string,token:string|null}|null
     */
    public function pendingInvite(): ?array
    {
        $email = env('SEED_ADMIN_EMAIL', 'admin@rajdhanifood.com') ?? 'admin@rajdhanifood.com';

        $row = $this->execute(
            'SELECT email, invite_token, password_hash FROM admin_users WHERE email = :email',
            [':email' => $email],
        )->fetch();

        if (!is_array($row) || $row['password_hash'] !== null) {
            return null;
        }

        return [
            'email' => (string) $row['email'],
            'token' => is_string($row['invite_token']) ? $row['invite_token'] : null,
        ];
    }
}
