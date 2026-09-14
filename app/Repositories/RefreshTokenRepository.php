<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `refresh_tokens` (doc 8.3), and the storage half of rotation.
 *
 * **The token itself is never stored.** Only a SHA-256 of it is, for the same
 * reason a password is hashed: a leaked database backup must not hand over live
 * sessions. Unlike a password there is no need for a slow hash — the token is 32
 * bytes of `random_bytes`, so there is no dictionary to run against it, and
 * refresh happens on every page load where a slow hash would be felt.
 *
 * `family_id` is what makes reuse detection work. Every rotation carries the
 * family forward, so a token that has already been rotated away is a signal that
 * someone kept a copy — and the response is to revoke the whole family, not just
 * that one token, because there is no way to tell the thief's branch from the
 * victim's.
 */
final class RefreshTokenRepository extends Repository
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param 'customer'|'admin' $audience
     */
    public function store(
        string $jti,
        string $token,
        string $audience,
        ?string $adminId,
        ?string $customerId,
        string $familyId,
        int $ttlSeconds,
        ?string $userAgent,
        ?string $ip,
    ): void {
        $this->run(
            'INSERT INTO refresh_tokens
                (id, jti, token_hash, audience, admin_id, customer_id, family_id,
                 user_agent, ip_address, expires_at, revoked_at, created_at)
             VALUES
                (:id, :jti, :hash, :audience, :admin_id, :customer_id, :family_id,
                 :user_agent, :ip, :expires_at, NULL, :created_at)',
            [
                ':id'          => UlidHelper::generate(),
                ':jti'         => $jti,
                ':hash'        => self::hash($token),
                ':audience'    => $audience,
                ':admin_id'    => $adminId,
                ':customer_id' => $customerId,
                ':family_id'   => $familyId,

                // Recorded for the "your sessions" screen and for forensics
                // after a family revocation, not for authorisation — a
                // user-agent string is client-controlled.
                ':user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                ':ip'         => $ip,
                ':expires_at' => $this->now($ttlSeconds),
                ':created_at' => $this->now(),
            ],
        );
    }

    /** @return array<string,mixed>|null */
    public function findByJti(string $jti): ?array
    {
        return $this->one(
            'SELECT id, jti, token_hash, audience, admin_id, customer_id, family_id,
                    expires_at, revoked_at, created_at
               FROM refresh_tokens WHERE jti = :jti LIMIT 1',
            [':jti' => $jti],
        );
    }

    public function revokeByJti(string $jti): void
    {
        $this->run(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE jti = :jti AND revoked_at IS NULL',
            [':now' => $this->now(), ':jti' => $jti],
        );
    }

    /**
     * Revoke every token in a rotation family. Called on reuse detection, which
     * means one of the holders is an attacker and there is no way to tell which.
     *
     * @return int tokens revoked, for the audit record
     */
    public function revokeFamily(string $familyId): int
    {
        return $this->run(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE family_id = :family AND revoked_at IS NULL',
            [':now' => $this->now(), ':family' => $familyId],
        );
    }

    /** Used on password change and on deactivation (doc 7.2): every session ends. */
    public function revokeAllForAdmin(string $adminId): int
    {
        return $this->run(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE admin_id = :admin AND revoked_at IS NULL',
            [':now' => $this->now(), ':admin' => $adminId],
        );
    }

    /**
     * Housekeeping for the nightly cron (doc 16.5). Expired rows are dead weight
     * — but only after a grace period, because a recently expired token is still
     * evidence when investigating a family revocation.
     */
    public function deleteExpiredBefore(int $graceSeconds = 604800): int
    {
        return $this->run(
            'DELETE FROM refresh_tokens WHERE expires_at < :cutoff',
            [':cutoff' => $this->now(-$graceSeconds)],
        );
    }
}
