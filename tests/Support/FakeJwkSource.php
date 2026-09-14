<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Support;

use OpenSSLAsymmetricKey;
use Rajdhani\Support\JwkSource;
use RuntimeException;

/**
 * An in-memory identity provider for tests.
 *
 * Generates a real 2048-bit RSA key pair and signs real RS256 tokens with it, so
 * the verifier under test does genuine signature verification — it is never told
 * that a token is valid, it works it out. Calling Google from the test suite
 * would be slow, offline-hostile, and could not produce the failure cases at all:
 * there is no way to ask Google for a token signed with the wrong key, or one
 * whose audience belongs to somebody else's application.
 */
final class FakeJwkSource implements JwkSource
{
    public string $keyId = 'test-key-1';

    /** How many times the verifier asked for keys — the rotation test reads this. */
    public int $fetchCount = 0;

    private OpenSSLAsymmetricKey $privateKey;

    /** @var array{n:string,e:string} */
    private array $publicNumbers;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a test key pair.');
        }

        $this->privateKey = $key;
        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Could not read the test key pair.');
        }

        /** @var array{n:string,e:string} $rsa */
        $rsa = $details['rsa'];

        $this->publicNumbers = ['n' => self::base64Url($rsa['n']), 'e' => self::base64Url($rsa['e'])];
    }

    /** @return list<array<string,mixed>> */
    public function keys(bool $forceRefresh = false): array
    {
        $this->fetchCount++;

        return [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->keyId,
            'n'   => $this->publicNumbers['n'],
            'e'   => $this->publicNumbers['e'],
        ]];
    }

    /**
     * Sign a token with this source's key.
     *
     * @param array<string,mixed> $claims
     */
    public function sign(array $claims, ?string $keyId = null, string $algorithm = 'RS256'): string
    {
        $header = self::base64Url(json_encode(
            ['alg' => $algorithm, 'kid' => $keyId ?? $this->keyId, 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));
        $payload = self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = '';
        openssl_sign("{$header}.{$payload}", $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}." . self::base64Url($signature);
    }

    /**
     * A token signed by a *different* key but advertising this source's key id —
     * a forgery with a perfectly valid shape.
     *
     * @param array<string,mixed> $claims
     */
    public function signWithForeignKey(array $claims): string
    {
        $foreign = new self();
        $foreign->keyId = $this->keyId;

        return $foreign->sign($claims);
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
