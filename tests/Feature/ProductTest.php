<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Services\ProductService;

/**
 * Products and their three child collections, against a real database
 * (doc §8.5, §9.9; RTPP-19).
 *
 * `testAPartialPackSizeUpdateRecomputesTheDiscountAgainstTheStoredSibling()`
 * exists because it failed during review, not in production: the first draft
 * routed the "read the other price back" case through a stub that always
 * returned null, so updating only `price` on an existing pack size would have
 * silently dropped its discount to zero. Caught before the API ever saw it,
 * and kept as a regression test regardless.
 */
final class ProductTest extends DatabaseTestCase
{
    private ProductService $products;
    private ProductRepository $repository;
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProductRepository($this->db);
        $this->products = new ProductService($this->repository, $this->db);
        $this->categoryId = $this->insertCategory();
    }

    // ─── create ─────────────────────────────────────────────────────────────

    public function testCreatingAProductDerivesASlugAndDefaultStatus(): void
    {
        $product = $this->products->create(['name' => 'Assam Gold', 'category_id' => $this->categoryId]);

        self::assertSame('assam-gold', $product['slug']);
        self::assertSame('DRAFT', $product['status']);
        self::assertFalse($product['is_featured']);
        self::assertSame(0, $product['view_count']);
        self::assertSame([], $product['pack_sizes']);
    }

    public function testACategoryThatDoesNotExistIsRejected(): void
    {
        $error = $this->captureApiError(
            fn () => $this->products->create(['name' => 'X', 'category_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertSame('category_id', $error->details()[0]['field']);
    }

    /**
     * The DoD's central claim: a product and every one of its children exist
     * or none of them do, because it was one transaction.
     */
    public function testCreateWritesTheProductAndAllChildrenInOneCall(): void
    {
        $product = $this->products->create([
            'name'        => 'Full Product',
            'category_id' => $this->categoryId,
            'pack_sizes'  => [
                ['label' => '250g', 'sku' => 'FULL-250', 'price' => 200, 'compare_price' => 250, 'is_default' => true],
                ['label' => '500g', 'sku' => 'FULL-500', 'price' => 380],
            ],
            'highlights' => [['title' => '100% Natural', 'icon_name' => 'leaf']],
        ]);

        self::assertCount(2, $product['pack_sizes']);
        self::assertCount(1, $product['highlights']);
        self::assertSame([], $product['images']);
    }

    /**
     * If any child fails validation, the product row itself must not exist
     * either — a create is all-or-nothing, not "product saved, pack sizes
     * silently dropped."
     */
    public function testAFailingChildRollsBackTheWholeCreate(): void
    {
        $this->captureApiError(fn () => $this->products->create([
            'name'        => 'Should Not Exist',
            'category_id' => $this->categoryId,
            'pack_sizes'  => [['label' => 'Bad', 'sku' => 'BAD-1']], // missing required price
        ]));

        self::assertSame(0, $this->countProductsNamed('Should Not Exist'));
    }

    // ─── rich text sanitisation (doc §14.2) ─────────────────────────────────

    public function testRichTextIsSanitisedBeforeStorage(): void
    {
        $product = $this->products->create([
            'name'        => 'Sanitised',
            'category_id' => $this->categoryId,
            'description' => '<p>Good tea</p><script>alert(1)</script>',
        ]);

        self::assertSame('<p>Good tea</p>', $product['description']);
    }

    public function testAnEmptyOrWhitespaceOnlyTabIsStoredAsNullNotEmptyString(): void
    {
        $product = $this->products->create([
            'name'        => 'Blank Tabs',
            'category_id' => $this->categoryId,
            'ingredients' => '   ',
        ]);

        // Never sent at all, and sent-but-blank, must be indistinguishable —
        // both mean "nothing to show", which is the doc §10.2 contract.
        self::assertNull($product['ingredients']);
        self::assertNull($product['brewing_guide']);
    }

    // ─── slug and SKU uniqueness ─────────────────────────────────────────────

    public function testAnExplicitDuplicateSlugOnCreateIsRejected(): void
    {
        $this->products->create(['name' => 'First', 'category_id' => $this->categoryId, 'slug' => 'shared']);

        $error = $this->captureApiError(
            fn () => $this->products->create(['name' => 'Second', 'category_id' => $this->categoryId, 'slug' => 'shared'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    public function testTheSlugUniqueConstraintItselfRejectsARaceCondition(): void
    {
        $this->insertProductDirectly('taken-slug');

        $this->expectException(\PDOException::class);
        $this->insertProductDirectly('taken-slug');
    }

    public function testADuplicateSkuAcrossDifferentProductsIsRejected(): void
    {
        $first = $this->products->create([
            'name' => 'A', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'X', 'sku' => 'DUP-SKU', 'price' => 100]],
        ]);

        $error = $this->captureApiError(fn () => $this->products->create([
            'name' => 'B', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'Y', 'sku' => 'DUP-SKU', 'price' => 200]],
        ]));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
        self::assertSame(1, $this->countProductsNamed('A'));
        // The failed create's product row must not have been left behind either.
        self::assertSame(0, $this->countProductsNamed('B'));
    }

    /** `sku` is unique across the whole table, not scoped to one product. */
    public function testSkuUniquenessIsGlobalNotPerProduct(): void
    {
        $product = $this->products->create(['name' => 'A', 'category_id' => $this->categoryId]);

        $this->products->createPackSize($product['id'], ['label' => 'X', 'sku' => 'GLOBAL-SKU', 'price' => 100]);

        $other = $this->products->create(['name' => 'B', 'category_id' => $this->categoryId]);
        $error = $this->captureApiError(
            fn () => $this->products->createPackSize($other['id'], ['label' => 'Y', 'sku' => 'GLOBAL-SKU', 'price' => 200])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    // ─── update ─────────────────────────────────────────────────────────────

    public function testUpdatingNameDoesNotReDeriveTheSlug(): void
    {
        $product = $this->products->create(['name' => 'Original Name', 'category_id' => $this->categoryId]);

        $updated = $this->products->update($product['id'], ['name' => 'Completely Different Name']);

        self::assertSame('original-name', $updated['slug']);
    }

    public function testAnExplicitSlugOnUpdateDoesChangeIt(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $updated = $this->products->update($product['id'], ['slug' => 'deliberately-chosen']);

        self::assertSame('deliberately-chosen', $updated['slug']);
    }

    public function testOmittingAChildKeyOnUpdateLeavesItUntouched(): void
    {
        $product = $this->products->create([
            'name' => 'X', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'A', 'sku' => 'KEEP-1', 'price' => 100]],
        ]);

        $updated = $this->products->update($product['id'], ['name' => 'Renamed']);

        self::assertCount(1, $updated['pack_sizes']);
        self::assertSame('KEEP-1', $updated['pack_sizes'][0]['sku']);
    }

    public function testAnEmptyArrayOnUpdateDoesClearTheChildren(): void
    {
        $product = $this->products->create([
            'name' => 'X', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'A', 'sku' => 'CLEAR-1', 'price' => 100]],
        ]);

        $updated = $this->products->update($product['id'], ['pack_sizes' => []]);

        self::assertSame([], $updated['pack_sizes']);
    }

    public function testUpdatingReplacesTheWholeChildSetNotAMerge(): void
    {
        $product = $this->products->create([
            'name' => 'X', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'Old', 'sku' => 'OLD-1', 'price' => 100]],
        ]);

        $updated = $this->products->update($product['id'], [
            'pack_sizes' => [['label' => 'New', 'sku' => 'NEW-1', 'price' => 200]],
        ]);

        self::assertCount(1, $updated['pack_sizes']);
        self::assertSame('NEW-1', $updated['pack_sizes'][0]['sku']);
        self::assertSame(0, $this->countPackSizesWithSku('OLD-1'), 'The old row must actually be gone, not merely unlisted');
    }

    public function testUpdatingAMissingProductIs404(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::NOT_FOUND->status());
        $this->products->update('01ZZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'X']);
    }

    public function testSendingNothingEditableOnUpdateIsRejected(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->products->update($product['id'], []);
    }

    // ─── delete ─────────────────────────────────────────────────────────────

    public function testDeletingAProductDoesNotCascadeToItsChildren(): void
    {
        $product = $this->products->create([
            'name' => 'X', 'category_id' => $this->categoryId,
            'pack_sizes' => [['label' => 'A', 'sku' => 'SURVIVES-1', 'price' => 100]],
        ]);

        $this->products->delete($product['id']);

        self::assertSame(1, $this->countPackSizesWithSku('SURVIVES-1'), "Deleting a product must not touch its children's rows");
    }

    public function testDeletingIsIdempotent(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $this->products->delete($product['id']);
        $this->products->delete($product['id']);

        self::assertTrue(true);
    }

    // ─── pack sizes: discount derivation ─────────────────────────────────────

    public function testDiscountIsDerivedFromPriceAndComparePrice(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $packSize = $this->products->createPackSize($product['id'], [
            'label' => 'A', 'sku' => 'DISC-1', 'price' => 80, 'compare_price' => 100,
        ]);

        self::assertSame(20, $packSize['discount_percent']);
    }

    public function testNoDiscountWhenComparePriceIsNotHigher(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $packSize = $this->products->createPackSize($product['id'], [
            'label' => 'A', 'sku' => 'DISC-2', 'price' => 100, 'compare_price' => 100,
        ]);

        self::assertNull($packSize['discount_percent']);
    }

    public function testDiscountPercentCannotBeSetDirectlyByTheClient(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        // A client-supplied percentage that disagrees with the two prices is
        // silently ignored — the derived value is the only one that can ever
        // be stored.
        $packSize = $this->products->createPackSize($product['id'], [
            'label' => 'A', 'sku' => 'DISC-3', 'price' => 100, 'discount_percent' => 90,
        ]);

        self::assertNull($packSize['discount_percent']);
    }

    /**
     * Caught during review before this ever ran: the first draft's "read the
     * sibling price back" path was a stub that always returned null, so a
     * price-only update silently zeroed out an existing discount instead of
     * recomputing it.
     */
    public function testAPartialPackSizeUpdateRecomputesTheDiscountAgainstTheStoredSibling(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);
        $packSize = $this->products->createPackSize($product['id'], [
            'label' => 'A', 'sku' => 'DISC-4', 'price' => 500, 'compare_price' => 600,
        ]);
        self::assertSame(17, $packSize['discount_percent']);

        $updated = $this->products->updatePackSize($product['id'], $packSize['id'], ['price' => 480]);

        self::assertSame(480.0, $updated['price']);
        self::assertSame(600.0, $updated['compare_price'], 'The untouched side must survive the partial update');
        self::assertSame(20, $updated['discount_percent'], 'Recomputed against the stored compare_price, not treated as absent');
    }

    /** The same, from the other direction: updating only compare_price. */
    public function testAPartialUpdateOfComparePriceAloneAlsoRecomputesCorrectly(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);
        $packSize = $this->products->createPackSize($product['id'], [
            'label' => 'A', 'sku' => 'DISC-5', 'price' => 400, 'compare_price' => null,
        ]);
        self::assertNull($packSize['discount_percent']);

        $updated = $this->products->updatePackSize($product['id'], $packSize['id'], ['compare_price' => 500]);

        self::assertSame(400.0, $updated['price']);
        self::assertSame(20, $updated['discount_percent']);
    }

    public function testOnlyOnePackSizeCanBeDefault(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);
        $first = $this->products->createPackSize($product['id'], ['label' => 'A', 'sku' => 'DEF-1', 'price' => 100, 'is_default' => true]);
        $second = $this->products->createPackSize($product['id'], ['label' => 'B', 'sku' => 'DEF-2', 'price' => 200, 'is_default' => true]);

        $firstNow = $this->products->findPackSize($product['id'], $first['id']);

        self::assertFalse($firstNow['is_default']);
        self::assertTrue($second['is_default']);
    }

    // ─── images: existence check and primary exclusivity ────────────────────

    public function testAttachingAnImageWithAnUnknownMediaIdIsRejected(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);

        $error = $this->captureApiError(
            fn () => $this->products->createImage($product['id'], ['media_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testOnlyOneImageCanBePrimary(): void
    {
        $product = $this->products->create(['name' => 'X', 'category_id' => $this->categoryId]);
        $mediaA = $this->insertMediaAsset();
        $mediaB = $this->insertMediaAsset();

        $first = $this->products->createImage($product['id'], ['media_id' => $mediaA, 'is_primary' => true]);
        $this->products->createImage($product['id'], ['media_id' => $mediaB, 'is_primary' => true]);

        $firstNow = $this->products->findImage($product['id'], $first['id']);

        self::assertFalse($firstNow['is_primary']);
    }

    // ─── reorder ──────────────────────────────────────────────────────────────

    public function testReorderingProductsPersists(): void
    {
        $a = $this->products->create(['name' => 'A', 'category_id' => $this->categoryId]);
        $b = $this->products->create(['name' => 'B', 'category_id' => $this->categoryId]);

        $this->products->reorder(['ids' => [$b['id'], $a['id']]]);

        $list = $this->products->paginate([]);
        $bySlug = array_column($list['data'], 'sort_order', 'slug');

        self::assertLessThan($bySlug[$a['slug']], $bySlug[$b['slug']]);
    }

    public function testReorderingPackSizesIsScopedToOneProduct(): void
    {
        $productA = $this->products->create(['name' => 'A', 'category_id' => $this->categoryId]);
        $productB = $this->products->create(['name' => 'B', 'category_id' => $this->categoryId]);
        $foreign = $this->products->createPackSize($productB['id'], ['label' => 'Foreign', 'sku' => 'FOREIGN-1', 'price' => 100]);

        $error = $this->captureApiError(
            fn () => $this->products->reorderPackSizes($productA['id'], ['ids' => [$foreign['id']]])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    // ─── pagination / filtering ───────────────────────────────────────────────

    public function testPaginationFiltersByStatus(): void
    {
        $this->products->create(['name' => 'Draft One', 'category_id' => $this->categoryId, 'status' => 'DRAFT']);
        $this->products->create(['name' => 'Published One', 'category_id' => $this->categoryId, 'status' => 'PUBLISHED']);

        $result = $this->products->paginate(['status' => 'PUBLISHED']);
        $names = array_column($result['data'], 'name');

        self::assertContains('Published One', $names);
        self::assertNotContains('Draft One', $names);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertCategory(): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $statement = $this->db->prepare(
            'INSERT INTO categories (id, name, slug, sort_order, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, 0, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'         => $id,
            ':name'       => 'Test Category',
            ':slug'       => 'test-category-' . bin2hex(random_bytes(4)),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $id;
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

    private function insertProductDirectly(string $slug): void
    {
        $now = $this->now();
        $statement = $this->db->prepare(
            'INSERT INTO products (id, category_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, \'DRAFT\', :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'          => UlidHelper::generate(),
            ':category_id' => $this->categoryId,
            ':name'        => 'Direct Insert',
            ':slug'        => $slug,
            ':created_at'  => $now,
            ':updated_at'  => $now,
        ]);
    }

    private function countProductsNamed(string $name): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM products WHERE name = :name');
        $statement->execute([':name' => $name]);

        return (int) $statement->fetchColumn();
    }

    private function countPackSizesWithSku(string $sku): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM product_pack_sizes WHERE sku = :sku');
        $statement->execute([':sku' => $sku]);

        return (int) $statement->fetchColumn();
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
