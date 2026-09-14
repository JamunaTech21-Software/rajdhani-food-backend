<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use RuntimeException;

/**
 * argon2id hashing and the section 7.2 password policy.
 *
 * argon2id is not bcrypt with a longer name: it is memory-hard, which is what
 * makes a GPU cracking rig expensive rather than merely slow. The cost
 * parameters live in `config/auth.php` and are stated explicitly there so that a
 * PHP upgrade changing its defaults is a visible diff rather than a silent
 * weakening.
 *
 * That memory-hardness is also the deployment risk. 64 MiB per hash is fine on a
 * dedicated box and can exhaust a shared cPanel account's memory limit under
 * concurrent logins. If that turns out to be the case (RTPP-90), the answer is
 * to lower `memory_cost` deliberately in config — not to fall back to bcrypt.
 */
final class PasswordHelper
{
    public static function hash(string $plain): string
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            // Refuse rather than quietly downgrade to bcrypt. A host without
            // argon2id is a hosting problem to fix (RTPP-90), and a mixed
            // hash estate is far worse than a failed deploy.
            throw new RuntimeException(
                'This PHP build has no argon2id support. Section 7.2 requires it; see RTPP-90.'
            );
        }

        // password_hash() throws on failure in PHP 8; there is no false or
        // empty return left to guard against.
        return password_hash($plain, PASSWORD_ARGON2ID, self::options());
    }

    /**
     * Verify a candidate against a stored hash.
     *
     * A null hash means the account exists but has never been claimed — the
     * state AdminSeeder leaves the first Super Admin in (doc 7.2). It must not
     * be treated as "no password required", so it fails here, and it still burns
     * the same work as a real verification so the response time does not reveal
     * which accounts are unclaimed.
     */
    public static function verify(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            self::burnTime();

            return false;
        }

        return password_verify($plain, $hash);
    }

    /** True when the stored hash was made with weaker parameters than config now asks for. */
    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID') && password_needs_rehash($hash, PASSWORD_ARGON2ID, self::options());
    }

    /**
     * Check a new password against the section 7.2 policy.
     *
     * Returns the envelope's `details` shape directly, so a controller can hand
     * the whole thing to ApiError::validation() and the front-end can render
     * every failed rule at once instead of one per submission.
     *
     * @return array<int,array<string,string>> empty when the password is acceptable
     */
    public static function policyViolations(string $plain, string $field = 'password'): array
    {
        /** @var array<string,mixed> $policy */
        $policy = config('auth.password_policy', []);
        $minimum = is_numeric($policy['min_length'] ?? null) ? (int) $policy['min_length'] : 10;
        $violations = [];

        // mb_strlen, not strlen: a password of ten Bangla characters is ten
        // characters, not thirty bytes.
        if (mb_strlen($plain, 'UTF-8') < $minimum) {
            $violations[] = "must be at least {$minimum} characters";
        }

        if (($policy['require_upper'] ?? false) === true && preg_match('/\p{Lu}/u', $plain) !== 1) {
            $violations[] = 'must contain an uppercase letter';
        }

        if (($policy['require_lower'] ?? false) === true && preg_match('/\p{Ll}/u', $plain) !== 1) {
            $violations[] = 'must contain a lowercase letter';
        }

        if (($policy['require_digit'] ?? false) === true && preg_match('/\d/', $plain) !== 1) {
            $violations[] = 'must contain a digit';
        }

        if (($policy['require_symbol'] ?? false) === true && preg_match('/[^\p{L}\p{N}]/u', $plain) !== 1) {
            $violations[] = 'must contain a symbol';
        }

        return array_map(
            static fn (string $rule): array => ['field' => $field, 'message' => 'Password ' . $rule],
            $violations,
        );
    }

    /** A single-use, URL-safe token for an invite or a reset. CHAR(64) in the schema. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array<string,int> */
    private static function options(): array
    {
        /** @var array<string,mixed> $configured */
        $configured = config('auth.argon2id', []);

        return [
            'memory_cost' => is_numeric($configured['memory_cost'] ?? null) ? (int) $configured['memory_cost'] : 65536,
            'time_cost'   => is_numeric($configured['time_cost'] ?? null) ? (int) $configured['time_cost'] : 4,
            'threads'     => is_numeric($configured['threads'] ?? null) ? (int) $configured['threads'] : 1,
        ];
    }

    /**
     * Spend roughly the time a real verification would, so that "no such
     * account" and "wrong password" are not distinguishable by a stopwatch.
     * Timing is the only channel here — both paths return the same error.
     */
    private static function burnTime(): void
    {
        static $decoy = null;

        if ($decoy === null && defined('PASSWORD_ARGON2ID')) {
            $decoy = password_hash('timing-equalisation-decoy', PASSWORD_ARGON2ID, self::options());
        }

        if (is_string($decoy)) {
            password_verify('timing-equalisation-decoy', $decoy);
        }
    }
}
