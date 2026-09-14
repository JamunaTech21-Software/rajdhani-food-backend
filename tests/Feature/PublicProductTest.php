<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Services\ProductService;

/**
 * The public catalogue — listing, filtering, detail, related (doc §9.4,
 * §10.2; RTPP-20), against a real database.
 *
 * Every test here shares one non-negotiable: a DRAFT or ARCHIVED product must
 * never appear in a public response, list or detail, no matter what filter is
 * applied — that is the whole reason this module exists as a separate,
 * narrower read path rather than reusing the admin listing with a fixed
 * `status` filter a caller could still override.
 */
final class PublicProductTest extends DatabaseTestCase
{
    private ProductService $products;
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProductRepository($this->db);
        $this->products = new ProductService($this->repository, $this->db);
    }

    // ─── published-only ─────────────────────────────────────────────────────

    public function testAnUnpublishedProduct404sOnThePublicDetailRoute(): void
    {
        $category = $this->insertCategory();
        $draft = $this->products->create(['name' => 'Still Drafting', 'category_id' => $category, 'status' => 'DRAFT']);

        $error = $this->captureApiError(fn () => $this->products->publicFindBySlug((string) $draft['slug']));

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testAnArchivedProduct404sOnThePublicDetailRouteTooNotJustDraft(): void
    {
        $category = $this->insertCategory();
        $archived = $this->products->create(['name' => 'Retired Blend', 'category_id' => $category, 'status' => 'ARCHIVED']);

        $error = $this->captureApiError(fn () => $this->products->publicFindBySlug((string) $archived['slug']));

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testANonExistentSlugAlsoJust404sIndistinguishablyFromAnUnpublishedOne(): void
    {
        $error = $this->captureApiError(fn () => $this->products->publicFindBySlug('no-such-product'));

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testListingNeverIncludesDraftOrArchivedProducts(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'Draft Tea', 'category_id' => $category, 'status' => 'DRAFT']);
        $this->products->create(['name' => 'Archived Tea', 'category_id' => $category, 'status' => 'ARCHIVED']);
        $this->products->create(['name' => 'Live Tea', 'category_id' => $category, 'status' => 'PUBLISHED']);

        $names = array_column($this->products->publicPaginate([])['data'], 'name');

        self::assertContains('Live Tea', $names);
        self::assertNotContains('Draft Tea', $names);
        self::assertNotContains('Archived Tea', $names);
    }

    // ─── filtering (doc §10.2 "filter state syncs to the URL") ─────────────

    public function testFilteringByCategorySlug(): void
    {
        $teaCategory = $this->insertCategory('Green Tea');
        $otherCategory = $this->insertCategory('Herbal');
        $this->products->create(['name' => 'Sencha', 'category_id' => $teaCategory, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Chamomile', 'category_id' => $otherCategory, 'status' => 'PUBLISHED']);

        $result = $this->products->publicPaginate(['category' => $this->categorySlug($teaCategory)]);
        $names = array_column($result['data'], 'name');

        self::assertSame(['Sencha'], $names);
    }

    /**
     * Scoped to a fresh category throughout — this database also carries
     * whatever demo products earlier manual testing left behind (real,
     * committed rows this test's own transaction still sees), so an
     * unscoped query cannot assume it is the only thing matching.
     */
    public function testFilteringBySearchTerm(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'Masala Chai', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Green Tea', 'category_id' => $category, 'status' => 'PUBLISHED']);

        $result = $this->products->publicPaginate(['category' => $this->categorySlug($category), 'search' => 'masala']);
        $names = array_column($result['data'], 'name');

        self::assertSame(['Masala Chai'], $names);
    }

    public function testFilteringByFeatured(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'Featured One', 'category_id' => $category, 'status' => 'PUBLISHED', 'is_featured' => true]);
        $this->products->create(['name' => 'Plain One', 'category_id' => $category, 'status' => 'PUBLISHED', 'is_featured' => false]);

        $result = $this->products->publicPaginate(['category' => $this->categorySlug($category), 'featured' => 'true']);
        $names = array_column($result['data'], 'name');

        self::assertSame(['Featured One'], $names);
    }

    public function testFilterStateIsFullyExpressibleTogetherInOneQuery(): void
    {
        $category = $this->insertCategory('Signature');
        $this->products->create([
            'name' => 'Signature Featured Chai', 'category_id' => $category,
            'status' => 'PUBLISHED', 'is_featured' => true,
        ]);
        $this->products->create([
            'name' => 'Signature Plain Chai', 'category_id' => $category,
            'status' => 'PUBLISHED', 'is_featured' => false,
        ]);

        $result = $this->products->publicPaginate([
            'category' => $this->categorySlug($category), 'search' => 'chai', 'featured' => 'true', 'sort' => 'name',
        ]);

        self::assertSame(['Signature Featured Chai'], array_column($result['data'], 'name'));
    }

    // ─── sorting ─────────────────────────────────────────────────────────────

    public function testSortByNameIsAlphabetical(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'Zesty Ginger', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Assam Gold', 'category_id' => $category, 'status' => 'PUBLISHED']);

        $names = array_column(
            $this->products->publicPaginate(['category' => $this->categorySlug($category), 'sort' => 'name'])['data'],
            'name',
        );

        self::assertSame(['Assam Gold', 'Zesty Ginger'], $names);
    }

    public function testSortByPriceAscendingUsesTheCardPrice(): void
    {
        $category = $this->insertCategory();
        $this->products->create([
            'name' => 'Pricier', 'category_id' => $category, 'status' => 'PUBLISHED',
            'pack_sizes' => [['label' => '250g', 'sku' => 'PR-250', 'price' => 900, 'is_default' => true]],
        ]);
        $this->products->create([
            'name' => 'Cheaper', 'category_id' => $category, 'status' => 'PUBLISHED',
            'pack_sizes' => [['label' => '250g', 'sku' => 'CH-250', 'price' => 200, 'is_default' => true]],
        ]);

        $names = array_column(
            $this->products->publicPaginate(['category' => $this->categorySlug($category), 'sort' => 'price_asc'])['data'],
            'name',
        );

        self::assertSame(['Cheaper', 'Pricier'], $names);
    }

    public function testSortByPriceDescendingReversesIt(): void
    {
        $category = $this->insertCategory();
        $this->products->create([
            'name' => 'Pricier', 'category_id' => $category, 'status' => 'PUBLISHED',
            'pack_sizes' => [['label' => '250g', 'sku' => 'PR2-250', 'price' => 900, 'is_default' => true]],
        ]);
        $this->products->create([
            'name' => 'Cheaper', 'category_id' => $category, 'status' => 'PUBLISHED',
            'pack_sizes' => [['label' => '250g', 'sku' => 'CH2-250', 'price' => 200, 'is_default' => true]],
        ]);

        $names = array_column(
            $this->products->publicPaginate(['category' => $this->categorySlug($category), 'sort' => 'price_desc'])['data'],
            'name',
        );

        self::assertSame(['Pricier', 'Cheaper'], $names);
    }

    /** A product with no pack sizes at all has a null card price and must not blow up either sort direction. */
    public function testAProductWithNoPackSizesSortsWithoutErrorAndHasANullPrice(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'No Pricing Yet', 'category_id' => $category, 'status' => 'PUBLISHED']);

        $result = $this->products->publicPaginate(['category' => $this->categorySlug($category), 'sort' => 'price_asc']);

        self::assertNull($result['data'][0]['price']);
    }

    public function testAnUnrecognisedSortDegradesToTheDefaultInsteadOfErroring(): void
    {
        $category = $this->insertCategory();
        $this->products->create(['name' => 'Whatever', 'category_id' => $category, 'status' => 'PUBLISHED']);

        $result = $this->products->publicPaginate(['category' => $this->categorySlug($category), 'sort' => 'not-a-real-sort']);

        self::assertCount(1, $result['data']);
    }

    // ─── pagination ──────────────────────────────────────────────────────────

    public function testPaginationMetaReflectsTheFilteredTotalNotTheGlobalOne(): void
    {
        $category = $this->insertCategory();
        $other = $this->insertCategory('Other');
        $this->products->create(['name' => 'In Scope', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Out Of Scope', 'category_id' => $other, 'status' => 'PUBLISHED']);

        $result = $this->products->publicPaginate(['category' => (string) $this->categorySlug($category)]);

        self::assertSame(1, $result['meta']['total']);
    }

    // ─── detail shape (doc §10.2) ────────────────────────────────────────────

    public function testDetailIncludesTabContentPackSizesHighlightsImagesRatingAndSeoMeta(): void
    {
        $category = $this->insertCategory('Premium Tea');
        $media = $this->insertMediaAsset();

        $product = $this->products->create([
            'name' => 'Everything Blend', 'category_id' => $category, 'status' => 'PUBLISHED',
            'description' => '<p>Rich and malty.</p>',
            'ingredients' => '<p>Black tea leaves</p>',
            'meta_title' => 'Everything Blend | Rajdhani Tea',
            'meta_description' => 'The full works.',
            'pack_sizes' => [['label' => '250g', 'sku' => 'EB-250', 'price' => 300, 'is_default' => true]],
            'highlights' => [['title' => '100% Natural', 'icon_name' => 'leaf']],
            'images' => [['media_id' => $media, 'is_primary' => true]],
        ]);

        $detail = $this->products->publicFindBySlug((string) $product['slug']);

        self::assertSame('<p>Rich and malty.</p>', $detail['description']);
        self::assertSame('<p>Black tea leaves</p>', $detail['ingredients']);
        self::assertSame('Everything Blend | Rajdhani Tea', $detail['meta_title']);
        self::assertSame('The full works.', $detail['meta_description']);
        self::assertSame(0.0, $detail['rating_average']);
        self::assertSame(0, $detail['rating_count']);
        self::assertCount(1, $detail['pack_sizes']);
        self::assertCount(1, $detail['highlights']);
        self::assertCount(1, $detail['images']);
        self::assertSame('Premium Tea', $detail['category']['name']);
        self::assertSame($this->categorySlug($category), $detail['category']['slug']);
        self::assertArrayNotHasKey('status', $detail);
        self::assertArrayNotHasKey('view_count', $detail);
    }

    // ─── related ─────────────────────────────────────────────────────────────

    public function testRelatedExcludesTheProductItselfAndScopesToItsCategory(): void
    {
        $category = $this->insertCategory('Black Tea');
        $other = $this->insertCategory('White Tea');

        $anchor = $this->products->create(['name' => 'Anchor Blend', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Sibling Blend', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Different Category', 'category_id' => $other, 'status' => 'PUBLISHED']);

        $related = $this->products->publicRelated((string) $anchor['slug'], []);
        $names = array_column($related, 'name');

        self::assertSame(['Sibling Blend'], $names);
    }

    public function testRelatedNeverIncludesUnpublishedSiblings(): void
    {
        $category = $this->insertCategory();
        $anchor = $this->products->create(['name' => 'Anchor', 'category_id' => $category, 'status' => 'PUBLISHED']);
        $this->products->create(['name' => 'Draft Sibling', 'category_id' => $category, 'status' => 'DRAFT']);

        $related = $this->products->publicRelated((string) $anchor['slug'], []);

        self::assertSame([], $related);
    }

    public function testRelatedOnAnUnpublishedAnchor404sJustLikeDetailDoes(): void
    {
        $category = $this->insertCategory();
        $draft = $this->products->create(['name' => 'Hidden Anchor', 'category_id' => $category, 'status' => 'DRAFT']);

        $error = $this->captureApiError(fn () => $this->products->publicRelated((string) $draft['slug'], []));

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    // ─── card shape ──────────────────────────────────────────────────────────

    public function testCardPriceIsTheDefaultPackSizeNotJustAnyOfThem(): void
    {
        $category = $this->insertCategory();
        $this->products->create([
            'name' => 'Multi Pack', 'category_id' => $category, 'status' => 'PUBLISHED',
            'pack_sizes' => [
                ['label' => '250g', 'sku' => 'MP-250', 'price' => 500],
                ['label' => '500g', 'sku' => 'MP-500', 'price' => 900, 'is_default' => true],
            ],
        ]);

        $card = $this->products->publicPaginate(['category' => $this->categorySlug($category)])['data'][0];

        self::assertSame(900.0, $card['price']);
    }

    public function testCardImageIsThePrimaryOneNotJustTheFirst(): void
    {
        $category = $this->insertCategory();
        $mediaFirst = $this->insertMediaAsset();
        $mediaPrimary = $this->insertMediaAsset();

        $this->products->create([
            'name' => 'Imaged Product', 'category_id' => $category, 'status' => 'PUBLISHED',
            'images' => [
                ['media_id' => $mediaFirst, 'sort_order' => 1],
                ['media_id' => $mediaPrimary, 'is_primary' => true, 'sort_order' => 2],
            ],
        ]);

        $card = $this->products->publicPaginate(['category' => $this->categorySlug($category)])['data'][0];

        self::assertNotNull($card['image']);
        self::assertSame('https://example.test/img.jpg', $card['image']['url']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * The dev database this suite runs against also carries real demo
     * categories seeded through the live API during earlier manual testing
     * (`Green Tea`, `Premium Tea`, ...) — this test's own transaction still
     * sees those committed rows, so a slug this predictable would collide.
     * A random suffix keeps every test's category unique regardless of what
     * else is sitting in the table.
     */
    private function insertCategory(string $name = 'Test Category'): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $slug = $this->slugify($name) . '-' . bin2hex(random_bytes(4));
        $statement = $this->db->prepare(
            'INSERT INTO categories (id, name, slug, sort_order, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, 0, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'         => $id,
            ':name'       => $name,
            ':slug'       => $slug,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $id;
    }

    private function categorySlug(string $categoryId): string
    {
        $statement = $this->db->prepare('SELECT slug FROM categories WHERE id = :id');
        $statement->execute([':id' => $categoryId]);

        return (string) $statement->fetchColumn();
    }

    private function slugify(string $name): string
    {
        return strtolower(str_replace(' ', '-', $name));
    }

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $statement = $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :now)'
        );
        $statement->execute([
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
