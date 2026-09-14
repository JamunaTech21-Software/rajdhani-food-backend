<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Http\Request;
use Rajdhani\Repositories\RefreshTokenRepository;
use Rajdhani\Support\Cookie;

/**
 * Issues and rotates the access/refresh pair (doc 7.1, 7.2).
 *
 * Shared by both audiences: the admin and customer systems differ in how a
 * caller proves who they are, not in what happens afterwards, and duplicating
 * rotation logic per audience is how the two halves drift apart.
 *
 * **The refresh token is not a JWT.** It is 32 bytes of `random_bytes` paired
 * with a `jti`, and the database row is the authority on whether it is still
 * live. A self-contained signed token cannot be revoked before it expires, which
 * would make both rotation and the family revocation below meaningless. The
 * access token *is* a JWT, because it is short-lived and must be verifiable
 * without a database round trip on every request.
 *
 * The two are carried differently on purpose: the access token in a header the
 * client holds in memory, the refresh token in an HttpOnly cookie script cannot
 * read (see Cookie).
 */
final class TokenService
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
    ) {
    }

    /**
     * Mint a fresh pair and start a new rotation family.
     *
     * The returned `refresh_token` is the value that was just written to the
     * cookie. It is returned so that a caller which is not a browser — a test,
     * or a future device-flow — can hold it; AdminAuthService strips it before
     * anything reaches a response body.
     *
     * @param array<string,mixed> $accessClaims role, and anything else the client needs without a round trip
     *
     * @return array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_at:int}
     */
    public function issue(
        string $audience,
        string $subjectId,
        array $accessClaims,
        Request $request,
        ?string $familyId = null,
    ): array {
        $accessTtl = JwtHelper::ttl("{$audience}_access", $audience === 'admin' ? 1200 : 900);
        $refreshTtl = JwtHelper::ttl("{$audience}_refresh", $audience === 'admin' ? 604800 : 2592000);

        $accessToken = JwtHelper::encode(
            JwtHelper::claims($subjectId, $audience, $accessTtl, $accessClaims),
            (string) config('auth.jwt.access_secret'),
        );

        $jti = self::uuid4();
        $refreshToken = bin2hex(random_bytes(32));

        $this->refreshTokens->store(
            jti: $jti,
            token: $refreshToken,
            audience: $audience === 'admin' ? 'admin' : 'customer',
            adminId: $audience === 'admin' ? $subjectId : null,
            customerId: $audience === 'admin' ? null : $subjectId,

            // A new login starts a new family; a rotation carries the old one
            // forward, which is what lets reuse be detected across a chain.
            familyId: $familyId ?? self::uuid4(),
            ttlSeconds: $refreshTtl,
            userAgent: $request->userAgent === '' ? null : $request->userAgent,
            ip: $request->ip === '' ? null : $request->ip,
        );

        $refreshExpiresAt = time() + $refreshTtl;

        // The cookie value carries the jti so the server can find the row
        // without a table scan, and the secret half so it can verify it.
        $cookieValue = "{$jti}.{$refreshToken}";
        Cookie::setRefresh($audience, $cookieValue, $refreshExpiresAt);

        return [
            'access_token'       => $accessToken,
            'expires_in'         => $accessTtl,
            'refresh_token'      => $cookieValue,
            'refresh_expires_at' => $refreshExpiresAt,
        ];
    }

    /**
     * Verify the presented refresh cookie and return the row behind it.
     *
     * This is where breach detection lives. A refresh token is single-use: the
     * rotation that consumes it marks it revoked. So a *revoked* token arriving
     * later means two parties hold the same token — the legitimate client and
     * whoever copied it — and there is no way to tell which one is calling.
     * Revoking the entire family logs both out and forces a password-backed
     * login, which the attacker cannot complete.
     *
     * @return array{row:array<string,mixed>,family_id:string}
     *
     * @throws ApiError UNAUTHENTICATED, always with the same message
     */
    public function consumeRefresh(string $audience, Request $request): array
    {
        $presented = $request->cookie(Cookie::name($audience));

        if ($presented === null || !str_contains($presented, '.')) {
            throw ApiError::unauthenticated('No valid session');
        }

        [$jti, $secret] = explode('.', $presented, 2);
        $row = $this->refreshTokens->findByJti($jti);

        if ($row === null || $row['audience'] !== $audience) {
            throw ApiError::unauthenticated('No valid session');
        }

        // Constant-time, so the stored hash cannot be recovered a byte at a time.
        if (!hash_equals((string) $row['token_hash'], RefreshTokenRepository::hash($secret))) {
            throw ApiError::unauthenticated('No valid session');
        }

        $familyId = (string) $row['family_id'];

        if ($row['revoked_at'] !== null) {
            $this->refreshTokens->revokeFamily($familyId);
            Cookie::clearRefresh($audience);

            throw ApiError::unauthenticated('No valid session');
        }

        if (strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
            throw ApiError::unauthenticated('No valid session');
        }

        // Single use. Marked spent before the replacement is issued, so a
        // failure half way leaves the old token dead rather than both live.
        $this->refreshTokens->revokeByJti($jti);

        return ['row' => $row, 'family_id' => $familyId];
    }

    public function revokePresented(string $audience, Request $request): void
    {
        $presented = $request->cookie(Cookie::name($audience));

        if ($presented !== null && str_contains($presented, '.')) {
            $this->refreshTokens->revokeByJti(explode('.', $presented, 2)[0]);
        }

        // Cleared regardless. Logout must leave the browser in a signed-out
        // state even when the token was already gone, or a user who "logged
        // out" keeps a cookie that looks like a session.
        Cookie::clearRefresh($audience);
    }

    /** RFC 4122 v4, to fill the CHAR(36) jti and family_id columns. */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
