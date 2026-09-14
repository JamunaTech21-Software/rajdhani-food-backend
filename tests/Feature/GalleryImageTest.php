<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\GalleryCategoryRepository;
use Rajdhani\Repositories\GalleryImageRepository;
use Rajdhani\Services\GalleryCategoryService;
use Rajdhani\Services\GalleryImageService;

/**
 * Gallery images — category-scoped ordering and bulk registration (doc §8.7,
 * §9.5, §9.9; RTPP-25), against a real database.
 *
 * `testBulkCreateValidatesEveryEntryBeforeWritingAny()` is this ticket's
 * headline bulk-upload behaviour: the class doc on `GalleryImageService`
 * explains why a half-registered batch is worse than a single clean
 * rejection, and this test proves the transaction actually rolls back rather
 * than merely asserting the method throws.
 */
final class GalleryImageTest extends DatabaseTestCase
{
    private GalleryImageService $images;
    private GalleryImageRepository $repository;
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new GalleryImageRepository($this->db);
        $this->images = new GalleryImageService($this->repository, $this->db);

        $categories = new GalleryCategoryService(new GalleryCategoryRepository($this->db));
        $this->categoryId = $categories->create(['name' => 'Test Images Category'])['id'];
    }

    public function testCreatingAnImage(): void
    {
        $media = $this->insertMediaAsset();

        $image = $this->images->create([
            'category_id' => $this->categoryId, 'media_id' => $media, 'title' => 'Morning light',
        ]);

        self::assertSame($this->categoryId, $image['category_id']);
        self::assertSame('Morning light', $image['title']);
        self::assertTrue($image['is_active']);
    }

    public function testMediaIdIsRequired(): void
    {
        $error = $this->captureApiError(fn () => $this->images->create(['category_id' => $this->categoryId]));

        self::assertSame('media_id', $error->details()[0]['field']);
    }

    public function testMediaIdMustReferenceARealAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->images->create([
            'category_id' => $this->categoryId, 'media_id' => UlidHelper::generate(),
        ]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testCategoryIdMustReferenceARealCategory(): void
    {
        $media = $this->insertMediaAsset();

        $error = $this->captureApiError(fn () => $this->images->create([
            'category_id' => UlidHelper::generate(), 'media_id' => $media,
        ]));

        self::assertSame('category_id', $error->details()[0]['field']);
    }

    public function testListingFiltersByCategoryId(): void
    {
        $media = $this->insertMediaAsset();
        $otherCategory = (new GalleryCategoryService(new GalleryCategoryRepository($this->db)))
            ->create(['name' => 'Test Other Category'])['id'];

        $this->images->create(['category_id' => $this->categoryId, 'media_id' => $media]);
        $this->images->create(['category_id' => $otherCategory, 'media_id' => $media]);

        $result = $this->images->paginate(['category_id' => $this->categoryId]);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame($this->categoryId, $result['data'][0]['category_id']);
    }

    public function testReorderIsScopedToOneCategory(): void
    {
        $media = $this->insertMediaAsset();
        $a = $this->images->create(['category_id' => $this->categoryId, 'media_id' => $media]);
        $b = $this->images->create(['category_id' => $this->categoryId, 'media_id' => $media]);

        $this->images->reorder(['category_id' => $this->categoryId, 'ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->images->find($b['id'])['sort_order']);
        self::assertSame(2, $this->images->find($a['id'])['sort_order']);
    }

    public function testReorderReportsIdsFromAnotherCategoryAsMissing(): void
    {
        $media = $this->insertMediaAsset();
        $otherCategory = (new GalleryCategoryService(new GalleryCategoryRepository($this->db)))
            ->create(['name' => 'Test Reorder Other Category'])['id'];

        $foreign = $this->images->create(['category_id' => $otherCategory, 'media_id' => $media]);

        $error = $this->captureApiError(
            fn () => $this->images->reorder(['category_id' => $this->categoryId, 'ids' => [$foreign['id']]])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testDeletingActuallyRemovesTheRow(): void
    {
        $media = $this->insertMediaAsset();
        $image = $this->images->create(['category_id' => $this->categoryId, 'media_id' => $media]);

        $this->images->delete($image['id']);

        $error = $this->captureApiError(fn () => $this->images->find($image['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testBulkCreateRegistersMultipleImagesAppendedAfterExisting(): void
    {
        $media = $this->insertMediaAsset();
        $existing = $this->images->create(['category_id' => $this->categoryId, 'media_id' => $media, 'sort_order' => 5]);

        $mediaTwo = $this->insertMediaAsset();
        $mediaThree = $this->insertMediaAsset();

        $result = $this->images->bulkCreate([
            'category_id' => $this->categoryId,
            'images'      => [
                ['media_id' => $mediaTwo, 'title' => 'Second'],
                ['media_id' => $mediaThree, 'title' => 'Third'],
            ],
        ]);

        self::assertCount(2, $result['data']);
        self::assertSame(6, $result['data'][0]['sort_order']);
        self::assertSame(7, $result['data'][1]['sort_order']);
        self::assertSame(5, $this->images->find($existing['id'])['sort_order']);
    }

    public function testBulkCreateValidatesEveryEntryBeforeWritingAny(): void
    {
        $media = $this->insertMediaAsset();

        $error = $this->captureApiError(fn () => $this->images->bulkCreate([
            'category_id' => $this->categoryId,
            'images'      => [
                ['media_id' => $media],
                ['media_id' => UlidHelper::generate()],
            ],
        ]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());

        $result = $this->images->paginate(['category_id' => $this->categoryId]);
        self::assertSame(0, $result['meta']['total']);
    }

    public function testBulkCreateRequiresANonEmptyImagesList(): void
    {
        $error = $this->captureApiError(fn () => $this->images->bulkCreate([
            'category_id' => $this->categoryId, 'images' => [],
        ]));

        self::assertSame('images', $error->details()[0]['field']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :now)'
        )->execute([
            ':id'        => $id,
            ':public_id' => 'test/' . bin2hex(random_bytes(6)),
            ':url'       => 'https://example.test/img.jpg',
            ':now'       => $this->now(),
        ]);

        return $id;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
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
