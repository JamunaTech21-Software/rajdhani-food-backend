<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\GalleryCategoryRepository;
use Rajdhani\Repositories\GalleryImageRepository;
use Rajdhani\Services\GalleryCategoryService;
use Rajdhani\Services\GalleryImageService;

/**
 * `GET /public/gallery/categories` and `GET /public/gallery` (doc §9.5;
 * RTPP-25), against a real database. Every fixture here uses a freshly
 * generated slug/name so an already-seeded `Tea Gardens`/`Events`/etc. row
 * (see `GallerySeeder`) never leaks into an exact-count assertion.
 */
final class PublicGalleryTest extends DatabaseTestCase
{
    private GalleryCategoryService $categories;
    private GalleryImageService $images;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categories = new GalleryCategoryService(new GalleryCategoryRepository($this->db));
        $this->images = new GalleryImageService(new GalleryImageRepository($this->db), $this->db);
    }

    public function testCategoriesTabBarListsOnlyActiveCategories(): void
    {
        $this->categories->create(['name' => 'Public Test Active', 'slug' => 'public-test-active']);
        $inactive = $this->categories->create(['name' => 'Public Test Inactive', 'slug' => 'public-test-inactive']);
        $this->categories->update($inactive['id'], ['is_active' => false]);

        $slugs = array_column($this->categories->publicList(), 'slug');

        self::assertContains('public-test-active', $slugs);
        self::assertNotContains('public-test-inactive', $slugs);
    }

    public function testCategoriesTabBarReportsImageCount(): void
    {
        $category = $this->categories->create(['name' => 'Public Test Counted', 'slug' => 'public-test-counted']);
        $media = $this->insertMediaAsset();
        $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);
        $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);

        $row = $this->findPublicCategory('public-test-counted');

        self::assertSame(2, $row['image_count']);
    }

    public function testImagesFeedReturnsOnlyActiveImagesInActiveCategories(): void
    {
        $category = $this->categories->create(['name' => 'Public Test Feed', 'slug' => 'public-test-feed']);
        $media = $this->insertMediaAsset();

        $visible = $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);
        $hidden = $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);
        $this->images->update($hidden['id'], ['is_active' => false]);

        $result = $this->images->publicList(['category' => 'public-test-feed']);
        $ids = array_column($result['data'], 'id');

        self::assertContains($visible['id'], $ids);
        self::assertNotContains($hidden['id'], $ids);
    }

    public function testImagesFeedFiltersByCategorySlug(): void
    {
        $categoryA = $this->categories->create(['name' => 'Public Test Filter A', 'slug' => 'public-test-filter-a']);
        $categoryB = $this->categories->create(['name' => 'Public Test Filter B', 'slug' => 'public-test-filter-b']);
        $media = $this->insertMediaAsset();

        $this->images->create(['category_id' => $categoryA['id'], 'media_id' => $media]);
        $this->images->create(['category_id' => $categoryB['id'], 'media_id' => $media]);

        $result = $this->images->publicList(['category' => 'public-test-filter-a']);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame('public-test-filter-a', $result['data'][0]['category']['slug']);
    }

    public function testImagesFeedIsPaginated(): void
    {
        $category = $this->categories->create(['name' => 'Public Test Page', 'slug' => 'public-test-page']);
        $media = $this->insertMediaAsset();

        for ($i = 0; $i < 3; $i++) {
            $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);
        }

        $result = $this->images->publicList(['category' => 'public-test-page', 'limit' => 2]);

        self::assertCount(2, $result['data']);
        self::assertSame(3, $result['meta']['total']);
        self::assertSame(2, $result['meta']['totalPages']);
    }

    /**
     * The layout-shift gap: a masonry grid needs an aspect ratio before the
     * image loads, and `media_assets.width`/`height` (Cloudinary reports
     * both at upload) were never carried through to this response.
     */
    public function testImagesFeedExposesTheOriginalDimensions(): void
    {
        $category = $this->categories->create(['name' => 'Public Test Dimensions', 'slug' => 'public-test-dimensions']);
        $media = $this->insertMediaAsset(width: 1600, height: 900);
        $this->images->create(['category_id' => $category['id'], 'media_id' => $media]);

        $result = $this->images->publicList(['category' => 'public-test-dimensions']);

        self::assertSame(1600, $result['data'][0]['image']['width']);
        self::assertSame(900, $result['data'][0]['image']['height']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function findPublicCategory(string $slug): array
    {
        foreach ($this->categories->publicList() as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        self::fail("No public category with slug {$slug}");
    }

    private function insertMediaAsset(?int $width = null, ?int $height = null): string
    {
        $id = UlidHelper::generate();
        $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, width, height, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :width, :height, :now)'
        )->execute([
            ':id'        => $id,
            ':public_id' => 'test/' . bin2hex(random_bytes(6)),
            ':url'       => 'https://example.test/img.jpg',
            ':width'     => $width,
            ':height'    => $height,
            ':now'       => $this->now(),
        ]);

        return $id;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
