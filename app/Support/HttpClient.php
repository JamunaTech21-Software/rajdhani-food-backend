<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use RuntimeException;

/**
 * The minimum HTTP client this project needs for outbound calls.
 *
 * cURL rather than `file_get_contents()`: shared hosts routinely disable
 * `allow_url_fopen`, and a stream wrapper gives no way to read response headers
 * without extra parsing — the Cache-Control header on Google's JWKS is the
 * whole reason the key cache knows when to expire.
 *
 * Certificate verification is on and is not configurable. Turning it off is the
 * standard fix for a host with a stale CA bundle, and it silently converts every
 * outbound call into something a network attacker can rewrite — for the JWKS
 * endpoint that means forged signing keys and forged logins.
 *
 * **No `curl_close()` call** (RTPP-34, found via a real outbound call this
 * ticket's `GoogleRecaptchaClient` made — the first thing in this codebase
 * to actually exercise this class's network path outside a faked test
 * double). PHP 8.5 deprecates it ("has no effect since PHP 8.0", where
 * handles became garbage-collected objects), and `Kernel::boot()`'s error
 * handler turns every deprecation into a thrown `ErrorException` — so on
 * PHP 8.5 the old `curl_close($handle)` line made *every* call through
 * this class throw on success. Removing it is behaviour-neutral on every
 * PHP version this project supports (`^8.2`).
 */
final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $connectTimeoutSeconds = 3,
    ) {
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws RuntimeException on a transport failure — never on an HTTP status
     */
    public function get(string $url): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise an HTTP request.');
        }

        /** @var array<string,string> $headers */
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'rajdhani-api/1.0',
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
                $length = strlen($line);

                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if (!is_string($body)) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * Form-encoded POST — everything this project's outbound POSTs need so
     * far (Cloudinary's signed admin endpoints) is scalar fields, never a
     * file, so `application/x-www-form-urlencoded` is sent explicitly rather
     * than leaving cURL to guess multipart from an array.
     *
     * @param array<string,int|string> $fields
     *
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws RuntimeException on a transport failure — never on an HTTP status
     */
    public function post(string $url, array $fields): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise an HTTP request.');
        }

        /** @var array<string,string> $headers */
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'rajdhani-api/1.0',
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
                $length = strlen($line);

                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if (!is_string($body)) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}
