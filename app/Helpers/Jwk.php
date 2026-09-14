<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use RuntimeException;

/**
 * Turns a JSON Web Key into a PEM public key OpenSSL can verify with.
 *
 * Google publishes its signing keys as JWKs — an RSA modulus and exponent in
 * base64url — and PHP's `openssl_verify()` wants a PEM. Nothing in the standard
 * library bridges the two, so the DER structure is assembled by hand here.
 *
 * What is being built is an X.509 SubjectPublicKeyInfo:
 *
 *   SEQUENCE {
 *     SEQUENCE { OID 1.2.840.113549.1.1.1 (rsaEncryption), NULL }
 *     BIT STRING { SEQUENCE { INTEGER modulus, INTEGER exponent } }
 *   }
 *
 * base64-armoured, which is what "-----BEGIN PUBLIC KEY-----" contains.
 *
 * The one subtlety worth knowing: DER INTEGERs are signed. A modulus whose
 * leading byte has its high bit set would be read as a negative number, so a
 * zero byte is prepended. Getting that wrong produces a key that parses but
 * fails every signature check.
 */
final class Jwk
{
    /** rsaEncryption, DER-encoded: 06 09 2a 86 48 86 f7 0d 01 01 01 */
    private const OID_RSA_ENCRYPTION = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

    /**
     * @param array<string,mixed> $jwk a single key from a JWKS `keys` array
     */
    public static function toPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            throw new RuntimeException('Only RSA JWKs are supported.');
        }

        $modulus = self::base64UrlDecode(is_string($jwk['n'] ?? null) ? $jwk['n'] : '');
        $exponent = self::base64UrlDecode(is_string($jwk['e'] ?? null) ? $jwk['e'] : '');

        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            throw new RuntimeException('JWK is missing a usable modulus or exponent.');
        }

        $rsaPublicKey = self::sequence(self::integer($modulus) . self::integer($exponent));

        // The leading 0x00 is the BIT STRING's "unused bits" count, always zero
        // for a whole number of bytes.
        $bitString = "\x03" . self::length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $algorithm = self::sequence(self::OID_RSA_ENCRYPTION . "\x05\x00");
        $der = self::sequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function integer(string $raw): string
    {
        $raw = ltrim($raw, "\x00");

        if ($raw === '') {
            $raw = "\x00";
        }

        // DER INTEGERs are two's-complement. Without this a modulus beginning
        // 0x80 or above reads as negative and every verification fails.
        if ((ord($raw[0]) & 0x80) !== 0) {
            $raw = "\x00" . $raw;
        }

        return "\x02" . self::length(strlen($raw)) . $raw;
    }

    private static function sequence(string $contents): string
    {
        return "\x30" . self::length(strlen($contents)) . $contents;
    }

    /**
     * Short form below 128 bytes, long form above — the usual DER rule.
     *
     * @param int<0, max> $length every caller passes a strlen(), and a negative
     *                            length has no meaning in DER
     */
    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        // pack('N') yields exactly four bytes, and reaching here means
        // $length >= 0x80, so at least one survives the trim: the count is 1..4
        // and the header byte is 0x81..0x84. Bounding it explicitly states that
        // invariant for a reader and a type checker alike.
        $count = min(strlen($bytes), 4);

        return chr(0x80 + $count) . substr($bytes, -$count);
    }
}
