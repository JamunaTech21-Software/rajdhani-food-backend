<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use Rajdhani\Support\Duration;
use RuntimeException;

/**
 * HS256 JSON Web Tokens (doc 7.1, 7.2).
 *
 * Hand-written rather than pulled from Composer, for the same reason the router
 * and the migration runner are: this is a shared-hosting deployment with no
 * build step, and a signature check is eighty lines. What it is *not* is a
 * general-purpose JWT library — it verifies exactly the token shape this project
 * issues and refuses everything else.
 *
 * Three attacks this has to defeat, each of which has broken real JWT code:
 *
 *   - **`alg: none`.** A token whose header says the signature is optional.
 *     The algorithm is not read from the token at all; a header that does not
 *     say HS256 is rejected before anything else happens.
 *   - **Algorithm confusion.** An RS256 verifier tricked into treating the
 *     public key as an HMAC secret. There is only one algorithm here, so there
 *     is nothing to confuse.
 *   - **Timing analysis of the signature.** `hash_equals`, never `===`.
 *
 * And one that is not an attack but breaks sessions in production: the access
 * and refresh secrets are different values, so a refresh token cannot be
 * presented as an access token even though both are HS256.
 */
final class JwtHelper
{
    private const ALGORITHM = 'HS256';

    /**
     * @param array<string,mixed> $claims
     */
    public static function encode(array $claims, string $secret): string
    {
        if ($secret === '') {
            throw new RuntimeException('Refusing to sign a token with an empty secret.');
        }

        $header = self::base64UrlEncode(self::json(['alg' => self::ALGORITHM, 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(self::json($claims));
        $signature = self::sign("{$header}.{$payload}", $secret);

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Verify and decode, or throw the section 9.1 error the client should see.
     *
     * `$expectedAudience` is not optional on purpose. The whole separation
     * between the admin and customer systems rests on this claim (doc 7), and an
     * optional argument is one forgotten parameter away from an admin token
     * working on a customer route.
     *
     * @return array<string,mixed>
     *
     * @throws ApiError UNAUTHENTICATED for a malformed or wrongly-scoped token,
     *                  TOKEN_EXPIRED only when the signature was valid and the
     *                  token has simply aged out — the client retries the first
     *                  and refreshes on the second
     */
    public static function decode(string $token, string $secret, string $expectedAudience): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw ApiError::unauthenticated('Malformed token');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = self::decodeSegment($header64);

        // Read before the signature check, so a token asking for `alg: none`
        // never reaches code that could honour it.
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw ApiError::unauthenticated('Unsupported token algorithm');
        }

        if (!hash_equals(self::sign("{$header64}.{$payload64}", $secret), $signature64)) {
            throw ApiError::unauthenticated('Invalid token signature');
        }

        $claims = self::decodeSegment($payload64);
        $leeway = (int) config('auth.jwt.leeway_seconds', 0);
        $now = time();

        // Expiry is checked only after the signature has been proved, so an
        // attacker cannot learn anything from the difference between the two
        // failures.
        if (isset($claims['exp']) && is_numeric($claims['exp']) && $now > (int) $claims['exp'] + $leeway) {
            throw ApiError::tokenExpired();
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && $now + $leeway < (int) $claims['nbf']) {
            throw ApiError::unauthenticated('Token is not valid yet');
        }

        if (($claims['aud'] ?? null) !== $expectedAudience) {
            // Deliberately vague. A caller presenting a customer token to an
            // admin route learns that it was refused, not that the route belongs
            // to a different audience.
            throw ApiError::unauthenticated('Token is not valid for this endpoint');
        }

        $issuer = config('auth.jwt.issuer');

        if (is_string($issuer) && $issuer !== '' && ($claims['iss'] ?? null) !== $issuer) {
            throw ApiError::unauthenticated('Token was issued by a different service');
        }

        return $claims;
    }

    /**
     * The claim set shared by every token this project issues.
     *
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    public static function claims(string $subject, string $audience, int $ttlSeconds, array $extra = []): array
    {
        $now = time();

        return [
            'iss' => config('auth.jwt.issuer'),
            'sub' => $subject,
            'aud' => $audience,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
        ] + $extra;
    }

    /** Reads a configured lifetime, accepting either `900` or `15m`. */
    public static function ttl(string $configKey, int $fallbackSeconds): int
    {
        $value = config("auth.ttl.{$configKey}");

        return is_string($value) ? Duration::seconds($value, $fallbackSeconds) : $fallbackSeconds;
    }

    private static function sign(string $message, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $message, $secret, true));
    }

    /** @return array<string,mixed> */
    private static function decodeSegment(string $segment): array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            throw ApiError::unauthenticated('Malformed token');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::unauthenticated('Malformed token');
        }

        if (!is_array($decoded)) {
            throw ApiError::unauthenticated('Malformed token');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        // strict mode: a segment containing characters outside the alphabet is a
        // tampered token, not something to silently ignore.
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
