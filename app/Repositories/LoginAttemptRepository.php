<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `login_attempts` (doc 8.3) — the record the admin-login throttle is computed
 * from (doc 7.2, 14.2).
 *
 * Counted on two dimensions, because they stop different attacks and the schema
 * carries both columns:
 *
 *   - **per email** — someone grinding one known account. This is what doc 7.2
 *     specifies, and it is the one that matters for a targeted attack.
 *   - **per IP** — someone spraying one common password across many addresses.
 *     Per-email counting never sees this, because no single account passes the
 *     threshold. The Jira issue asks for this dimension.
 *
 * Only failures count. A successful login does not consume anyone's budget, and
 * successes are still recorded because "when did this account last log in, and
 * from where" is the first question asked after a compromise.
 */
final class LoginAttemptRepository extends Repository
{
    /** @param 'customer'|'admin' $audience */
    public function record(string $email, string $audience, ?string $ip, bool $successful): void
    {
        $this->run(
            'INSERT INTO login_attempts (id, email, audience, ip_address, successful, created_at)
             VALUES (:id, :email, :audience, :ip, :successful, :created_at)',
            [
                ':id'         => UlidHelper::generate(),
                ':email'      => mb_substr($email, 0, 255),
                ':audience'   => $audience,
                ':ip'         => $ip,
                ':successful' => $successful ? 1 : 0,
                ':created_at' => $this->now(),
            ],
        );
    }

    /** @param 'customer'|'admin' $audience */
    public function failuresForEmail(string $email, string $audience, int $windowSeconds): int
    {
        // Covered by ix_login_attempts_email_time (email, created_at).
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = :email AND audience = :audience
                AND successful = 0 AND created_at > :since',
            [':email' => $email, ':audience' => $audience, ':since' => $this->now(-$windowSeconds)],
        );
    }

    /** @param 'customer'|'admin' $audience */
    public function failuresForIp(string $ip, string $audience, int $windowSeconds): int
    {
        if ($ip === '') {
            return 0;
        }

        return (int) $this->scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = :ip AND audience = :audience
                AND successful = 0 AND created_at > :since',
            [':ip' => $ip, ':audience' => $audience, ':since' => $this->now(-$windowSeconds)],
        );
    }

    /**
     * When the lockout lifts: the newest failure in the window plus the lockout
     * period. Returned so the response can carry a concrete wait rather than a
     * bare "try again later".
     *
     * @param 'customer'|'admin' $audience
     */
    public function secondsUntilUnlock(string $email, string $audience, int $windowSeconds, int $lockoutSeconds): int
    {
        $latest = $this->scalar(
            'SELECT MAX(created_at) FROM login_attempts
              WHERE email = :email AND audience = :audience
                AND successful = 0 AND created_at > :since',
            [':email' => $email, ':audience' => $audience, ':since' => $this->now(-$windowSeconds)],
        );

        if (!is_string($latest)) {
            return 0;
        }

        $unlockAt = strtotime($latest . ' UTC') + $lockoutSeconds;

        return max(0, $unlockAt - time());
    }

    /** Nightly housekeeping; the table is write-heavy and read only over a short window. */
    public function deleteOlderThan(int $seconds): int
    {
        return $this->run(
            'DELETE FROM login_attempts WHERE created_at < :cutoff',
            [':cutoff' => $this->now(-$seconds)],
        );
    }
}
