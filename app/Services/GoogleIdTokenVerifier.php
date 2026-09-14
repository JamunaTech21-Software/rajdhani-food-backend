<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\Jwk;
use Rajdhani\Support\GoogleJwkSource;
use Rajdhani\Support\JwkSource;
use RuntimeException;

/**
 * Verifies a Google ID token (doc §7.1, step 4).
 *
 * The browser gets this token from Google Identity Services and posts it here.
 * **It is attacker-controlled input until every check below has passed** — a
 * token is just a string, and anyone can post one. The checks are what make it
 * evidence of a Google sign-in rather than a claim of one.
 *
 * The Node implementation used `google-auth-library`; there is no PHP equivalent
 * worth a dependency here, so this is written out. Each check maps to an attack:
 *
 *   signature (RS256, Google's key)  a token someone simply wrote themselves
 *   `alg` from our list, not theirs   `alg: none`, and HS256 confusion where the
 *                                     public key is used as an HMAC secret
 *   `iss` is Google                   a token from another issuer entirely
 *   **`aud` is our client id**         a valid Google token minted for a
 *                                     *different application* — the one check
 *                                     people leave out, and the one that turns
 *                                     any other site's login into ours
 *   `exp` / `iat`                     replay of an old token
 *   `email_verified`                  a Google account whose address was never
 *                                     confirmed, which would let someone claim
 *                                     an email they do not own
 */
final class GoogleIdTokenVerifier
{
    /** Google issues with and without the scheme; both are legitimate. */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const ALGORITHM = 'RS256';
    private const LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly JwkSource $keys = new GoogleJwkSource(),
        private readonly ?string $clientId = null,
    ) {
    }

    /**
     * @return array{sub:string,email:string,name:string,picture:string|null}
     *
     * @throws ApiError UNAUTHENTICATED for anything that fails verification
     */
    public function verify(string $idToken): array
    {
        $clientId = $this->clientId ?? (string) config('auth.google_client_id');

        if ($clientId === '') {
            // A configuration failure, not the caller's fault: without a client
            // id the audience check cannot run, and skipping it would accept
            // any Google token from any application.
            throw new RuntimeException(
                'GOOGLE_CLIENT_ID is not configured; refusing to verify an ID token without an audience to check.'
            );
        }

        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = $this->decodeSegment($header64);

        // Read before any signature work, so a token proposing `none` cannot
        // reach code that might honour it.
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        $keyId = is_string($header['kid'] ?? null) ? $header['kid'] : null;

        if ($keyId === null) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        $signature = Jwk::base64UrlDecode($signature64);

        if ($signature === null) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        $this->verifySignature("{$header64}.{$payload64}", $signature, $keyId);

        $claims = $this->decodeSegment($payload64);
        $now = time();

        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        // The audience check. Google's signature proves the token is real; only
        // this proves it was issued for *us*.
        if (!$this->audienceMatches($claims['aud'] ?? null, $clientId)) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || $now > (int) $claims['exp'] + self::LEEWAY_SECONDS) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] > $now + self::LEEWAY_SECONDS) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        $subject = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        $email = is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email']), 'UTF-8') : '';

        if ($subject === '' || $email === '') {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        // Google sends this as a boolean or the string "true" depending on the
        // flow. An unverified address must not be accepted: the account would
        // otherwise be able to claim an email it does not control, and this
        // schema treats email as a unique identity.
        $verified = $claims['email_verified'] ?? false;

        if ($verified !== true && $verified !== 'true') {
            throw ApiError::unauthenticated('Your Google account email is not verified');
        }

        $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
            ? trim($claims['name'])
            : explode('@', $email)[0];

        $picture = is_string($claims['picture'] ?? null) && $claims['picture'] !== ''
            ? $claims['picture']
            : null;

        return ['sub' => $subject, 'email' => $email, 'name' => $name, 'picture' => $picture];
    }

    private function verifySignature(string $signedPart, string $signature, string $keyId): void
    {
        $key = $this->findKey($keyId, forceRefresh: false);

        if ($key === null) {
            // Not a forgery yet — far more likely Google rotated its keys and
            // the cache is stale. One forced refetch, then give up.
            $key = $this->findKey($keyId, forceRefresh: true);
        }

        if ($key === null) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        $publicKey = openssl_pkey_get_public(Jwk::toPem($key));

        if ($publicKey === false) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        if (openssl_verify($signedPart, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }
    }

    /** @return array<string,mixed>|null */
    private function findKey(string $keyId, bool $forceRefresh): ?array
    {
        foreach ($this->keys->keys($forceRefresh) as $key) {
            if (($key['kid'] ?? null) === $keyId) {
                return $key;
            }
        }

        return null;
    }

    /** `aud` is a string for an ID token, but the spec allows an array. */
    private function audienceMatches(mixed $audience, string $clientId): bool
    {
        if (is_string($audience)) {
            return hash_equals($clientId, $audience);
        }

        if (is_array($audience)) {
            foreach ($audience as $candidate) {
                if (is_string($candidate) && hash_equals($clientId, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function decodeSegment(string $segment): array
    {
        $json = Jwk::base64UrlDecode($segment);

        if ($json === null) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        if (!is_array($decoded)) {
            throw ApiError::unauthenticated('Google sign-in failed');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
