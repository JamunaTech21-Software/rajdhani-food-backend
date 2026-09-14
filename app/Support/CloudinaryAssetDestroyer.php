<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\CloudinarySigner;
use RuntimeException;

/**
 * The real `CloudinaryDestroyer` — Cloudinary's signed `destroy` admin
 * endpoint (doc §12, RTPP-22), over the shared `HttpClient`.
 *
 * Signed the same way `CloudinarySigner::sign()` signs an upload: `public_id`
 * and `timestamp`, sorted, hashed with the account's api_secret. Cloudinary
 * reports `result: "not found"` rather than an error when the asset is
 * already gone — deletion is idempotent everywhere else in this codebase, and
 * treating that response as success rather than a failure is what keeps this
 * call consistent with that.
 */
final class CloudinaryAssetDestroyer implements CloudinaryDestroyer
{
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    public function destroy(string $publicId, string $resourceType): void
    {
        $cloudName = (string) config('cloudinary.cloud_name');
        $apiKey = (string) config('cloudinary.api_key');
        $apiSecret = (string) config('cloudinary.api_secret');
        $timestamp = time();

        $signature = CloudinarySigner::sign(['public_id' => $publicId, 'timestamp' => $timestamp], $apiSecret);

        try {
            $response = $this->http->post(
                "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/destroy",
                [
                    'public_id' => $publicId,
                    'api_key'   => $apiKey,
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                ],
            );
        } catch (RuntimeException $e) {
            throw ApiError::internal('Could not reach Cloudinary to delete this asset', $e);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true);
        $result = is_array($decoded) ? ($decoded['result'] ?? null) : null;

        if ($result !== 'ok' && $result !== 'not found') {
            throw ApiError::internal("Cloudinary refused to delete this asset (HTTP {$response['status']})");
        }
    }
}
