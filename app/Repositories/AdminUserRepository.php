<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `admin_users` (doc 8.3).
 *
 * Note what is *not* here: no `create()`. Admin accounts are issued by a Super
 * Admin from the dashboard (doc 7.2, RTPP-13 onward) or by the seeder, never as
 * a side effect of authentication. A repository method to create one, sitting in
 * the auth layer, is how self-registration accidentally ships.
 */
final class AdminUserRepository extends Repository
{
    private const COLUMNS = 'id, name, email, password_hash, role, avatar_id, phone, is_active,
                             last_login_at, invite_token, invite_expires_at, reset_token,
                             reset_expires_at, created_by_id, created_at, updated_at';

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        // Email is stored as entered but compared case-insensitively: the column
        // collation is utf8mb4_unicode_ci, so 'Admin@x.com' matches
        // 'admin@x.com' here and the UNIQUE index treats them as one account.
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM admin_users WHERE email = :email LIMIT 1',
            [':email' => $email],
        );
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM admin_users WHERE id = :id LIMIT 1',
            [':id' => $id],
        );
    }

    /**
     * An invite is only valid while it is unexpired *and* unclaimed. The
     * `password_hash IS NULL` test is the second half of that: once a password
     * exists the account has been claimed, and a token that somehow survived
     * must not re-open it.
     *
     * @return array<string,mixed>|null
     */
    public function findByValidInviteToken(string $token): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM admin_users
              WHERE invite_token = :token
                AND invite_expires_at > :now
                AND password_hash IS NULL
              LIMIT 1',
            [':token' => $token, ':now' => $this->now()],
        );
    }

    /** @return array<string,mixed>|null */
    public function findByValidResetToken(string $token): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM admin_users
              WHERE reset_token = :token AND reset_expires_at > :now
              LIMIT 1',
            [':token' => $token, ':now' => $this->now()],
        );
    }

    /**
     * Set a password and burn whichever single-use token authorised it.
     *
     * Both token pairs are cleared regardless of which one was used. An account
     * that held a live invite *and* a reset token would otherwise keep the
     * unused one as a second way in.
     */
    public function setPassword(string $id, string $passwordHash): void
    {
        $this->run(
            'UPDATE admin_users
                SET password_hash = :hash,
                    invite_token = NULL, invite_expires_at = NULL,
                    reset_token = NULL,  reset_expires_at = NULL,
                    updated_at = :now
              WHERE id = :id',
            [':hash' => $passwordHash, ':now' => $this->now(), ':id' => $id],
        );
    }

    public function setResetToken(string $id, string $token, int $ttlSeconds): void
    {
        $this->run(
            'UPDATE admin_users
                SET reset_token = :token, reset_expires_at = :expires, updated_at = :now
              WHERE id = :id',
            [
                ':token'   => $token,
                ':expires' => $this->now($ttlSeconds),
                ':now'     => $this->now(),
                ':id'      => $id,
            ],
        );
    }

    /**
     * The live "Editor list" doc §14.4's "review submitted" notification
     * names — deliberately not a `settings` key like the other notify
     * lists: it should always be whoever currently holds the role, not a
     * separately maintained address list that can drift from reality.
     *
     * @return list<string>
     */
    public function activeEmailsForRole(string $role): array
    {
        $rows = $this->all(
            'SELECT email FROM admin_users WHERE role = :role AND is_active = 1',
            [':role' => $role],
        );

        return array_map(static fn (array $row): string => (string) $row['email'], $rows);
    }

    public function touchLastLogin(string $id): void
    {
        $this->run(
            'UPDATE admin_users SET last_login_at = :now WHERE id = :id',
            [':now' => $this->now(), ':id' => $id],
        );
    }

    /** @param array<string,scalar|null> $fields name, phone, avatar_id */
    public function updateProfile(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        // Column names come from a fixed allowlist in the service, never from
        // the request body — this is the one place a dynamic column list is
        // built, and it must not be reachable from user input.
        $assignments = [];
        $parameters = [':id' => $id, ':now' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = "`{$column}` = :{$column}";
            $parameters[":{$column}"] = $value;
        }

        $this->run(
            'UPDATE admin_users SET ' . implode(', ', $assignments) . ', updated_at = :now WHERE id = :id',
            $parameters,
        );
    }
}
