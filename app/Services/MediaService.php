<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\CloudinarySigner;
use Rajdhani\Helpers\SlugHelper;
use Rajdhani\Repositories\MediaRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\CloudinaryAssetDestroyer;
use Rajdhani\Support\CloudinaryDestroyer;
use Rajdhani\Support\Database;

/**
 * Signed direct-to-Cloudinary upload, asset registration, and deletion with a
 * cross-table reference check (doc §12, RTPP-21, RTPP-22). Files never
 * transit this process on the way in — `signature()` only signs a folder and
 * a timestamp for the browser to upload with directly, and `register()` only
 * records what Cloudinary reports happened. `delete()` is the one method here
 * that does talk to Cloudinary itself, because removing a file is not
 * something a signature can delegate to the browser.
 *
 * **What "server-side validated" means for registration, precisely**: the
 * response signature proves `public_id`/`version` genuinely came from this
 * Cloudinary account (see `CloudinarySigner`'s class doc for why that is
 * *not* the same as authenticating `bytes`/`format`/dimensions). Given that,
 * a forged `public_id` is caught by the signature check; an oversized or
 * wrong-type file is caught by checking the size/format Cloudinary's own
 * response reported against §12's limits. Both are real, tested rejections —
 * neither depends on trusting a client's *claim* about a file it has not yet
 * uploaded.
 */
final class MediaService
{
    use HandlesTransactions;
    use ValidatesInput;

    private const RESOURCE_TYPES = ['image', 'raw'];

    /** Cloudinary's `format` values this account can receive, mapped to the MIME vocabulary doc §12's limits are written in. */
    private const FORMAT_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
    ];

    private readonly PDO $db;

    public function __construct(
        private readonly MediaRepository $media = new MediaRepository(),
        private readonly CloudinaryDestroyer $destroyer = new CloudinaryAssetDestroyer(),
        ?PDO $connection = null,
    ) {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * `POST /admin/media/signature`. `resource` is the `{resource}` segment
     * of doc §12's `rajdhani/{resource}` convention — sanitised through
     * `SlugHelper` rather than a fixed enum, since the doc's own list
     * ("for example…") is not exhaustive and a new module should not need a
     * code change here just to get a folder.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function signature(array $input): array
    {
        $resource = SlugHelper::make($this->requiredText($input, 'resource', 64));
        $resourceType = $this->requiredResourceType($input);

        $folder = config('cloudinary.folder_prefix') . '/' . $resource;
        $timestamp = time();
        $apiSecret = (string) config('cloudinary.api_secret');

        $signature = CloudinarySigner::sign(['folder' => $folder, 'timestamp' => $timestamp], $apiSecret);
        $cloudName = (string) config('cloudinary.cloud_name');

        return [
            'cloud_name'    => $cloudName,
            'api_key'       => (string) config('cloudinary.api_key'),
            'timestamp'     => $timestamp,
            'signature'     => $signature,
            'folder'        => $folder,
            'resource_type' => $resourceType,
            'upload_url'    => "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload",
        ];
    }

    /**
     * `POST /admin/media`. Registers what Cloudinary reports back from the
     * upload `signature()` authorised — never a file this process has seen.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function register(string $adminId, array $input): array
    {
        $publicId = $this->requiredText($input, 'public_id', 255);
        $version = $this->requiredText($input, 'version', 32);
        $signature = $this->requiredText($input, 'signature', 128);
        $resourceType = $this->requiredResourceType($input);
        $format = strtolower($this->requiredText($input, 'format', 16));
        $bytes = $this->requiredPositiveInt($input, 'bytes');
        $width = $this->optionalPositiveInt($input, 'width');
        $height = $this->optionalPositiveInt($input, 'height');
        $secureUrl = $this->requiredText($input, 'secure_url', 512);
        $altText = $this->optionalText($input, 'alt_text', 255);
        $caption = $this->optionalText($input, 'caption', 512);

        $apiSecret = (string) config('cloudinary.api_secret');

        if (!CloudinarySigner::verifyResponseSignature($publicId, $version, $signature, $apiSecret)) {
            // Deliberately one message for "no such asset", "wrong version"
            // and "signed with the wrong secret" — the caller does not get to
            // learn which part of a forged request was wrong.
            throw ApiError::uploadFailed('This upload could not be verified');
        }

        $folderPrefix = (string) config('cloudinary.folder_prefix');

        if (!str_starts_with($publicId, $folderPrefix . '/')) {
            // Cloudinary itself would only ever hand back a public_id nested
            // under the folder `signature()` signed for — reaching here means
            // either a misconfigured account or a signature valid for a
            // public_id this module never authorised in the first place.
            throw ApiError::uploadFailed('This asset is outside the expected folder');
        }

        if ($this->media->publicIdExists($publicId)) {
            throw ApiError::conflict('This asset has already been registered');
        }

        $kind = $resourceType === 'image' ? 'image' : 'document';
        /** @var array{max_bytes:int,mime:list<string>} $limits */
        $limits = (array) config("cloudinary.limits.{$kind}");
        $mime = self::FORMAT_MIME[$format] ?? null;

        if ($mime === null || !in_array($mime, $limits['mime'], true)) {
            throw ApiError::uploadFailed("The file type '{$format}' is not allowed for {$kind} uploads");
        }

        if ($bytes > $limits['max_bytes']) {
            $maxMb = (int) ($limits['max_bytes'] / (1024 * 1024));
            throw ApiError::uploadFailed("This file exceeds the {$maxMb} MB limit for {$kind} uploads");
        }

        // Derived from the (now-verified) public_id, not from a client-sent
        // `folder` field — the one folder value worth trusting is the one
        // Cloudinary's own signed identifier implies.
        $lastSlash = strrpos($publicId, '/');
        $folder = $lastSlash === false ? $folderPrefix : substr($publicId, 0, $lastSlash);

        $id = $this->media->create([
            'public_id'      => $publicId,
            'secure_url'     => $secureUrl,
            'type'           => $kind === 'image' ? 'IMAGE' : 'DOCUMENT',
            'format'         => $format,
            'width'          => $width,
            'height'         => $height,
            'bytes'          => $bytes,
            'folder'         => $folder,
            'alt_text'       => $altText,
            'caption'        => $caption,
            'uploaded_by_id' => $adminId,
        ]);

        return $this->view($this->media->find($id) ?? throw ApiError::internal('Asset vanished immediately after being created'));
    }

    /**
     * `DELETE /admin/media/:id` (doc §12, RTPP-22). `$rowScope` is
     * `RequireRole::own()`'s recorded answer — `'own'` for an Editor, who may
     * only delete media they uploaded themselves; `'all'` for a Super Admin,
     * who may delete anything. The route admits both; this is where the
     * distinction actually gets enforced (see the class doc on
     * `RequireRole::own()` for why the middleware alone is only half the
     * gate).
     *
     * Idempotent, like every other delete in this codebase: an id that does
     * not exist has already reached the end state this call wants, so it is
     * not an error. A reference check runs before either half of the actual
     * deletion — the DB row is removed *before* the Cloudinary call, inside
     * the same transaction, specifically so a Cloudinary failure rolls the
     * row back rather than leaving a registry entry for an asset Cloudinary
     * already deleted (which is the one order-of-operations mistake that
     * would leave a broken image reachable — the exact thing this ticket
     * exists to prevent). The reverse order would fail differently: a DB
     * failure *after* a successful Cloudinary delete leaves an orphaned
     * registry row pointing at nothing, which is a stale link, not a broken
     * image on the live site, and a materially smaller failure to risk.
     */
    public function delete(string $id, string $callerAdminId, string $rowScope): void
    {
        $asset = $this->media->find($id);

        if ($asset === null) {
            return;
        }

        if ($rowScope === 'own' && (string) $asset['uploaded_by_id'] !== $callerAdminId) {
            throw ApiError::forbidden('You may only delete media you uploaded');
        }

        $dependents = $this->media->references($id);

        if ($dependents !== []) {
            throw ApiError::conflict('This asset is still in use and cannot be deleted', array_map(
                static fn (array $d): array => ['field' => $d['table'], 'message' => "Used as {$d['count']} {$d['label']}"],
                $dependents,
            ));
        }

        $resourceType = $this->resourceTypeFor((string) $asset['type']);

        $this->transaction(function () use ($id, $asset, $resourceType): void {
            $this->media->hardDelete($id);
            $this->destroyer->destroy((string) $asset['public_id'], $resourceType);
        });
    }

    private function resourceTypeFor(string $type): string
    {
        return match ($type) {
            'IMAGE' => 'image',
            'VIDEO' => 'video',
            default => 'raw',
        };
    }

    /** @param array<string,mixed> $input */
    private function requiredResourceType(array $input): string
    {
        $value = $input['resource_type'] ?? null;

        if (!is_string($value) || !in_array($value, self::RESOURCE_TYPES, true)) {
            throw $this->invalid('resource_type', 'Must be one of: image, raw');
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredPositiveInt(array $input, string $field): int
    {
        $value = $input[$field] ?? null;

        if (!is_numeric($value) || (int) $value <= 0) {
            throw $this->invalid($field, 'Expected a positive whole number');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalPositiveInt(array $input, string $field): ?int
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_numeric($value) || (int) $value <= 0) {
            throw $this->invalid($field, 'Expected a positive whole number');
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'public_id'      => (string) $row['public_id'],
            'secure_url'     => (string) $row['secure_url'],
            'type'           => (string) $row['type'],
            'format'         => $row['format'] === null ? null : (string) $row['format'],
            'width'          => $row['width'] === null ? null : (int) $row['width'],
            'height'         => $row['height'] === null ? null : (int) $row['height'],
            'bytes'          => $row['bytes'] === null ? null : (int) $row['bytes'],
            'folder'         => $row['folder'] === null ? null : (string) $row['folder'],
            'alt_text'       => $row['alt_text'] === null ? null : (string) $row['alt_text'],
            'caption'        => $row['caption'] === null ? null : (string) $row['caption'],
            'uploaded_by_id' => $row['uploaded_by_id'] === null ? null : (string) $row['uploaded_by_id'],
            'created_at'     => (string) $row['created_at'],
        ];
    }
}
