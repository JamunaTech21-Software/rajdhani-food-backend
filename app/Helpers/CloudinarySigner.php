<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * Cloudinary's signing algorithm (doc §12, RTPP-21), both directions.
 *
 * **Requests** (`sign()`): the parameters the browser will upload with — here,
 * only `folder` and `timestamp` — are joined `key=value` pairs, sorted by key,
 * concatenated with `&`, and hashed with the account's api_secret appended
 * (never sent, only ever used server-side). Cloudinary recomputes the same
 * hash over whatever parameters the browser actually sends; if a caller adds
 * an unsigned parameter Cloudinary rejects the upload outright, which is what
 * keeps a browser from asking Cloudinary to write outside the folder this
 * class was asked to sign for.
 *
 * **Responses** (`verifyResponseSignature()`): Cloudinary's own upload API
 * response carries a `signature` computed the same way over exactly
 * `public_id` and `version` — not the whole response body. This is
 * deliberately the same two fields Cloudinary's own SDKs check
 * (`verifyApiResponseSignature`): it proves the asset genuinely exists in
 * this account at that version, which is what makes a public_id trustworthy
 * enough to derive a folder from. It is not a signature over `bytes`,
 * `format`, `width` or `height` — Cloudinary's protocol was never designed to
 * make those tamper-proof against the account's own authenticated admins, and
 * neither is this.
 */
final class CloudinarySigner
{
    /** @param array<string,int|string> $params */
    public static function sign(array $params, string $apiSecret): string
    {
        ksort($params);
        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return sha1(implode('&', $pairs) . $apiSecret);
    }

    public static function verifyResponseSignature(string $publicId, string $version, string $signature, string $apiSecret): bool
    {
        $expected = sha1("public_id={$publicId}&version={$version}" . $apiSecret);

        return hash_equals($expected, $signature);
    }
}
