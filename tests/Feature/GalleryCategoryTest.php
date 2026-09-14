<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\GalleryCategoryRepository;
use Rajdhani\Services\GalleryCategoryService;

/**
 * Gallery categories — the tab bar (doc §8.7, §9.5, §9.9; RTPP-25), against a
 * real database.
 *
 * `testDeletingACategoryCascadesToItsImages()` is this ticket's one
 * behaviour that has no analogue anywhere else in the codebase: every other
 * module either soft-deletes or hard-deletes a row with nothing referencing
 * it. This table hard-deletes a row that *does* have dependents, on purpose
 * (`fk_gallery_images_category ... ON DELETE CASCADE`), and the test proves
 * the cascade actually fires, not just that the category row itself goes.
 */
final class GalleryCategoryTest extends DatabaseTestCase
{
    private GalleryCategoryService $categories;
    private GalleryCategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new GalleryCategoryRepository($this->db);
        $this->categories = new GalleryCategoryService($this->repository);
    }

    public function testCreatingACategory(): void
    {
        $category = $this->categories->create(['name' => 'Test Category One']);

        self::assertSame('Test Category One', $category['name']);
        self::assertSame('test-category-one', $category['slug']);
        self::assertTrue($category['is_active']);
    }

    public function testExplicitSlugCollisionIsRejected(): void
    {
        $this->categories->create(['name' => 'Test Category Two', 'slug' => 'test-gallery-slug']);

        $error = $this->captureApiError(
            fn () => $this->categories->create(['name' => 'Different Name', 'slug' => 'test-gallery-slug'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    /** Bypasses the service entirely — proves `uq_gallery_categories_slug` itself rejects the collision. */
    public function testTwoCategoriesCannotShareASlugAtTheDatabaseLevel(): void
    {
        $this->insertCategoryDirectly('duplicate-gallery-slug');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/uq_gallery_categories_slug/');

        $this->insertCategoryDirectly('duplicate-gallery-slug');
    }

    public function testUpdatingSlugChecksAgainstOthersNotItself(): void
    {
        $category = $this->categories->create(['name' => 'Test Category Three']);

        $unchanged = $this->categories->update($category['id'], ['slug' => 'test-category-three']);

        self::assertSame('test-category-three', $unchanged['slug']);
    }

    public function testCoverImageMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->categories->create([
            'name' => 'Test Category Four', 'cover_image_id' => UlidHelper::generate(),
        ]));

        self::assertSame('cover_image_id', $error->details()[0]['field']);
    }

    public function testReorderIsFlatAndUnscoped(): void
    {
        $a = $this->categories->create(['name' => 'Test Reorder A']);
        $b = $this->categories->create(['name' => 'Test Reorder B']);

        $this->categories->reorder(['ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->categories->find($b['id'])['sort_order']);
        self::assertSame(2, $this->categories->find($a['id'])['sort_order']);
    }

    public function testImageCountReflectsChildRows(): void
    {
        $category = $this->categories->create(['name' => 'Test Category With Images']);
        $media = $this->insertMediaAsset();

        $this->insertImageDirectly($category['id'], $media);
        $this->insertImageDirectly($category['id'], $media);

        self::assertSame(2, $this->categories->imageCount($category['id']));
    }

    public function testDeletingACategoryCascadesToItsImages(): void
    {
        $category = $this->categories->create(['name' => 'Test Category To Delete']);
        $media = $this->insertMediaAsset();
        $imageId = $this->insertImageDirectly($category['id'], $media);

        $this->categories->delete($category['id']);

        self::assertNull($this->repository->find($category['id']));

        $survivingImage = $this->db->prepare('SELECT id FROM gallery_images WHERE id = :id');
        $survivingImage->execute([':id' => $imageId]);
        self::assertFalse($survivingImage->fetch());
    }

    public function testDeletingAMissingCategoryIsIdempotent(): void
    {
        $this->categories->delete(UlidHelper::generate());
        self::assertTrue(true);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertCategoryDirectly(string $slug): void
    {
        $this->db->prepare(
            'INSERT INTO gallery_categories (id, name, slug, sort_order, is_active)
             VALUES (:id, :name, :slug, 0, 1)'
        )->execute([':id' => UlidHelper::generate(), ':name' => 'Direct Insert', ':slug' => $slug]);
    }

    private function insertImageDirectly(string $categoryId, string $mediaId): string
    {
        $id = UlidHelper::generate();
        $this->db->prepare(
            'INSERT INTO gallery_images (id, category_id, media_id, sort_order, is_active, created_at)
             VALUES (:id, :category_id, :media_id, 0, 1, :now)'
        )->execute([
            ':id' => $id, ':category_id' => $categoryId, ':media_id' => $mediaId, ':now' => $this->now(),
        ]);

        return $id;
    }

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
