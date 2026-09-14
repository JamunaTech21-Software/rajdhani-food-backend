<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\MediaRepository;
use Rajdhani\Services\MediaService;
use Rajdhani\Support\CloudinaryDestroyer;

/**
 * Signed direct-to-Cloudinary upload, registration, and deletion with a
 * cross-table reference check (doc §12, RTPP-21, RTPP-22), against a real
 * database and the real Cloudinary credentials in `.env`.
 *
 * Upload/registration is pure cryptography, not a network call, so every
 * signing-related DoD item is testable without ever reaching Cloudinary's
 * API. Deletion genuinely does call Cloudinary — `RecordingCloudinaryDestroyer`
 * below stands in for that one real network dependency, the same way
 * `JwkSource` gets faked for Google's keys elsewhere in this suite; the
 * "does this actually delete the file" proof comes from a live run instead
 * (see RTPP-22's Jira comment).
 */
final class MediaTest extends DatabaseTestCase
{
    private MediaService $media;
    private MediaRepository $repository;
    private RecordingCloudinaryDestroyer $destroyer;
    private string $apiSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new MediaRepository($this->db);
        $this->destroyer = new RecordingCloudinaryDestroyer();
        $this->media = new MediaService($this->repository, $this->destroyer, $this->db);
        $this->apiSecret = (string) config('cloudinary.api_secret');

        if ($this->apiSecret === '') {
            self::markTestSkipped('CLOUDINARY_API_SECRET is not configured.');
        }
    }

    // ─── signature ──────────────────────────────────────────────────────────

    public function testSignatureFoldersUnderTheConfiguredPrefixAndSanitisesTheResourceSegment(): void
    {
        $result = $this->media->signature(['resource' => 'Products!!', 'resource_type' => 'image']);

        self::assertSame('rajdhani/products', $result['folder']);
        self::assertSame('image', $result['resource_type']);
        self::assertSame((string) config('cloudinary.cloud_name'), $result['cloud_name']);
        self::assertStringContainsString('/image/upload', $result['upload_url']);
    }

    /** The signature is genuinely verifiable independently — not just "the code returns something". */
    public function testTheReturnedSignatureIsTheRealCloudinarySignatureOfFolderAndTimestamp(): void
    {
        $result = $this->media->signature(['resource' => 'banners', 'resource_type' => 'image']);

        $expected = sha1("folder={$result['folder']}&timestamp={$result['timestamp']}" . $this->apiSecret);

        self::assertSame($expected, $result['signature']);
    }

    public function testAnInvalidResourceTypeIsRejected(): void
    {
        $error = $this->captureApiError(
            fn () => $this->media->signature(['resource' => 'products', 'resource_type' => 'video'])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    // ─── registration: the happy paths ──────────────────────────────────────

    public function testRegisteringAValidImageStoresIt(): void
    {
        $publicId = $this->publicId('products');
        $version = (string) time();

        $asset = $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 1_200_000,
            'width'        => 800,
            'height'       => 600,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
            'alt_text'     => 'A bag of loose leaf tea',
        ]);

        self::assertSame('IMAGE', $asset['type']);
        self::assertSame('jpg', $asset['format']);
        self::assertSame('rajdhani/products', $asset['folder']);
        self::assertSame('A bag of loose leaf tea', $asset['alt_text']);
        self::assertSame(800, $asset['width']);
    }

    public function testRegisteringAValidPdfDocumentStoresItAsDocumentNotImage(): void
    {
        $publicId = $this->publicId('documents');
        $version = (string) time();

        $asset = $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'raw',
            'format'       => 'pdf',
            'bytes'        => 3_000_000,
            'secure_url'   => "https://res.cloudinary.com/test/raw/upload/{$publicId}.pdf",
        ]);

        self::assertSame('DOCUMENT', $asset['type']);
        self::assertNull($asset['width']);
    }

    // ─── registration: the rejections the DoD names ─────────────────────────

    public function testASixMegabyteImageIsRejectedAtRegistration(): void
    {
        $publicId = $this->publicId('products');
        $version = (string) time();

        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 6 * 1024 * 1024,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
        self::assertStringContainsString('5 MB', $error->getMessage());
    }

    public function testATwentyOneMegabyteDocumentIsRejected(): void
    {
        $publicId = $this->publicId('documents');
        $version = (string) time();

        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'raw',
            'format'       => 'pdf',
            'bytes'        => 21 * 1024 * 1024,
            'secure_url'   => "https://res.cloudinary.com/test/raw/upload/{$publicId}.pdf",
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
    }

    /**
     * The DoD's other named rejection: a signature the caller could not
     * genuinely have produced without the account's api_secret.
     */
    public function testAForgedSignatureIsRejected(): void
    {
        $publicId = $this->publicId('products');
        $version = (string) time();

        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => sha1('not-the-real-secret'),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 100_000,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
    }

    /** A signature valid for a *different* public_id must not authorise this one. */
    public function testASignatureThatDoesNotMatchThisPublicIdIsRejected(): void
    {
        $publicId = $this->publicId('products');
        $otherPublicId = $this->publicId('products');
        $version = (string) time();

        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($otherPublicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 100_000,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
    }

    public function testAPublicIdOutsideTheConfiguredFolderPrefixIsRejected(): void
    {
        $publicId = 'not-rajdhani/products/' . bin2hex(random_bytes(6));
        $version = (string) time();

        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 100_000,
            'secure_url'   => 'https://res.cloudinary.com/test/image/upload/x.jpg',
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
    }

    public function testAFormatNotAllowedForTheDeclaredKindIsRejected(): void
    {
        $publicId = $this->publicId('products');
        $version = (string) time();

        // A PDF's MIME type is not on the image allowlist, even though the
        // format itself is one this account can receive.
        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'pdf',
            'bytes'        => 100_000,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.pdf",
        ]));

        self::assertSame(ErrorCode::UPLOAD_FAILED, $error->errorCode());
    }

    public function testRegisteringTheSamePublicIdTwiceIsAConflictNotADuplicateRow(): void
    {
        $publicId = $this->publicId('products');
        $version = (string) time();
        $payload = [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 100_000,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
        ];

        $this->media->register(UlidHelper::generate(), $payload);
        $error = $this->captureApiError(fn () => $this->media->register(UlidHelper::generate(), $payload));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    // ─── deletion (RTPP-22) ──────────────────────────────────────────────────

    public function testDeletingANonExistentAssetIsANoOpNotAnError(): void
    {
        $this->media->delete(UlidHelper::generate(), UlidHelper::generate(), 'all');

        self::assertSame([], $this->destroyer->calls);
    }

    public function testAnEditorMayNotDeleteMediaSomeoneElseUploaded(): void
    {
        $uploader = UlidHelper::generate();
        $asset = $this->registerAsset($uploader);

        $error = $this->captureApiError(
            fn () => $this->media->delete($asset['id'], UlidHelper::generate(), 'own')
        );

        self::assertSame(ErrorCode::FORBIDDEN, $error->errorCode());
        self::assertSame([], $this->destroyer->calls);
    }

    public function testAnEditorMayDeleteMediaTheyUploadedThemselves(): void
    {
        $uploader = UlidHelper::generate();
        $asset = $this->registerAsset($uploader);

        $this->media->delete($asset['id'], $uploader, 'own');

        self::assertNull($this->repository->find($asset['id']));
        self::assertSame([['publicId' => $asset['public_id'], 'resourceType' => 'image']], $this->destroyer->calls);
    }

    public function testASuperAdminMayDeleteAnyonesMedia(): void
    {
        $asset = $this->registerAsset(UlidHelper::generate());

        $this->media->delete($asset['id'], UlidHelper::generate(), 'all');

        self::assertNull($this->repository->find($asset['id']));
    }

    public function testDeletingCallsCloudinaryWithRawForADocumentNotImage(): void
    {
        $publicId = $this->publicId('documents');
        $version = (string) time();
        $asset = $this->media->register(UlidHelper::generate(), [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'raw',
            'format'       => 'pdf',
            'bytes'        => 500_000,
            'secure_url'   => "https://res.cloudinary.com/test/raw/upload/{$publicId}.pdf",
        ]);

        $this->media->delete($asset['id'], UlidHelper::generate(), 'all');

        self::assertSame('raw', $this->destroyer->calls[0]['resourceType']);
    }

    /**
     * The DoD's headline case: an in-use asset is refused, and the caller is
     * told what uses it — not just "no".
     */
    public function testDeletingAnAssetReferencedByACategoryIsAConflictNamingCategories(): void
    {
        $asset = $this->registerAsset(UlidHelper::generate());
        $this->insertCategoryReferencing($asset['id']);

        $error = $this->captureApiError(
            fn () => $this->media->delete($asset['id'], UlidHelper::generate(), 'all')
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
        $fields = array_column($error->details(), 'field');
        self::assertContains('categories', $fields);
        self::assertNotNull($this->repository->find($asset['id']));
        self::assertSame([], $this->destroyer->calls);
    }

    /**
     * A second reference table, structurally different from `categories`
     * (a config singleton rather than an insertable row) — proof the
     * data-driven check in `MediaRepository::references()` is not only
     * exercised for the one table with its own dedicated test above.
     */
    public function testDeletingAnAssetReferencedBySiteProfileNamesSiteProfile(): void
    {
        $existing = $this->db->query('SELECT id FROM site_profile LIMIT 1')->fetchColumn();

        if ($existing === false) {
            self::markTestSkipped('Site profile not seeded. Run: php bin/seed.php');
        }

        $asset = $this->registerAsset(UlidHelper::generate());
        $this->db->prepare('UPDATE site_profile SET logo_light_id = :id WHERE id = 1')
            ->execute([':id' => $asset['id']]);

        $error = $this->captureApiError(
            fn () => $this->media->delete($asset['id'], UlidHelper::generate(), 'all')
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
        self::assertContains('site_profile', array_column($error->details(), 'field'));
    }

    /**
     * Regression-shaped by construction, not by history: proves the ordering
     * decision in `MediaService::delete()`'s class doc actually holds. If the
     * DB delete were not rolled back on a Cloudinary failure, this would
     * leave a registry row with nothing behind it — the exact "broken image
     * reachable" outcome the ticket exists to prevent.
     */
    public function testACloudinaryFailureRollsBackTheRegistryDeletion(): void
    {
        $asset = $this->registerAsset(UlidHelper::generate());
        $this->destroyer->shouldFail = true;

        $this->captureApiError(fn () => $this->media->delete($asset['id'], UlidHelper::generate(), 'all'));

        self::assertNotNull($this->repository->find($asset['id']));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function registerAsset(string $uploaderAdminId): array
    {
        $publicId = $this->publicId('products');
        $version = (string) time();

        return $this->media->register($uploaderAdminId, [
            'public_id'    => $publicId,
            'version'      => $version,
            'signature'    => $this->validSignature($publicId, $version),
            'resource_type' => 'image',
            'format'       => 'jpg',
            'bytes'        => 100_000,
            'secure_url'   => "https://res.cloudinary.com/test/image/upload/{$publicId}.jpg",
        ]);
    }

    private function insertCategoryReferencing(string $mediaId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $this->db->prepare(
            'INSERT INTO categories (id, name, slug, image_id, sort_order, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, :image_id, 0, 1, :created_at, :updated_at)'
        )->execute([
            ':id'         => UlidHelper::generate(),
            ':name'       => 'References Test',
            ':slug'       => 'references-test-' . bin2hex(random_bytes(4)),
            ':image_id'   => $mediaId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function publicId(string $resource): string
    {
        return 'rajdhani/' . $resource . '/' . bin2hex(random_bytes(8));
    }

    private function validSignature(string $publicId, string $version): string
    {
        return sha1("public_id={$publicId}&version={$version}" . $this->apiSecret);
    }

    private function captureApiError(callable $action): ApiError
    {
        try {
            $action();
        } catch (ApiError $e) {
            return $e;
        }

        self::fail('Expected an ApiError, none was thrown.');
    }
}

/**
 * Stands in for the one real network dependency `MediaService::delete()`
 * has — see this file's class doc.
 */
final class RecordingCloudinaryDestroyer implements CloudinaryDestroyer
{
    public bool $shouldFail = false;

    /** @var list<array{publicId:string,resourceType:string}> */
    public array $calls = [];

    public function destroy(string $publicId, string $resourceType): void
    {
        if ($this->shouldFail) {
            throw ApiError::internal('Simulated Cloudinary failure');
        }

        $this->calls[] = ['publicId' => $publicId, 'resourceType' => $resourceType];
    }
}
