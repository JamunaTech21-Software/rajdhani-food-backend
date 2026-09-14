<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\WishlistRepository;
use Rajdhani\Services\WishlistService;

/**
 * Server-persisted wishlist — add, remove, list, and guest-list merge (doc
 * §8.6, §9.7, §18.6; RTPP-28), against a real database.
 *
 * `testASoftDeletedProductDoesNotAppearAndIsNotAnError()` and
 * `testAnUnpublishedProductDoesNotAppearAndIsNotAnError()` are this
 * ticket's second DoD item: "removing a product from the catalogue does not
 * leave a dangling wishlist row" — read literally as "does not break the
 * read path", since products in this codebase are soft-deleted, not hard
 * deleted (the schema's `ON DELETE CASCADE` on `fk_wishlist_product` covers
 * the hard-delete case the ordinary admin flow never actually takes).
 */
final class WishlistTest extends DatabaseTestCase
{
    private WishlistService $wishlists;
    private WishlistRepository $repository;
    private ProductRepository $products;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new WishlistRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->wishlists = new WishlistService($this->repository, $this->products);
        $this->customerId = $this->insertCustomer();
    }

    public function testAddingAProductReturnsItInTheList(): void
    {
        $product = $this->insertPublishedProduct('Test Wishlist Product');

        $result = $this->wishlists->add($this->customerId, ['productId' => $product]);

        self::assertCount(1, $result['data']);
        self::assertSame($product, $result['data'][0]['id']);
    }

    public function testAddingTheSameProductTwiceIsIdempotent(): void
    {
        $product = $this->insertPublishedProduct('Test Idempotent Add');

        $this->wishlists->add($this->customerId, ['productId' => $product]);
        $result = $this->wishlists->add($this->customerId, ['productId' => $product]);

        self::assertCount(1, $result['data']);
    }

    public function testAddingAnUnknownProductIs404(): void
    {
        $error = $this->captureApiError(
            fn () => $this->wishlists->add($this->customerId, ['productId' => UlidHelper::generate()])
        );

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testAddingAnUnpublishedProductIs404(): void
    {
        $product = $this->insertDraftProduct();

        $error = $this->captureApiError(fn () => $this->wishlists->add($this->customerId, ['productId' => $product]));

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testListingIsScopedToTheCallingCustomer(): void
    {
        $product = $this->insertPublishedProduct('Test Scoped Product');
        $otherCustomer = $this->insertCustomer();
        $this->wishlists->add($otherCustomer, ['productId' => $product]);

        $result = $this->wishlists->list($this->customerId);

        self::assertSame([], $result['data']);
    }

    public function testListingIsNewestAddedFirst(): void
    {
        $first = $this->insertPublishedProduct('Test Order First');
        $second = $this->insertPublishedProduct('Test Order Second');
        $this->wishlists->add($this->customerId, ['productId' => $first]);
        $this->wishlists->add($this->customerId, ['productId' => $second]);

        $result = $this->wishlists->list($this->customerId);

        self::assertSame([$second, $first], array_column($result['data'], 'id'));
    }

    public function testRemovingAProductRemovesItFromTheList(): void
    {
        $product = $this->insertPublishedProduct('Test Remove Product');
        $this->wishlists->add($this->customerId, ['productId' => $product]);

        $result = $this->wishlists->remove($this->customerId, $product);

        self::assertSame([], $result['data']);
    }

    public function testRemovingAProductNeverOnTheWishlistIsIdempotent(): void
    {
        $result = $this->wishlists->remove($this->customerId, UlidHelper::generate());
        self::assertSame([], $result['data']);
    }

    public function testASoftDeletedProductDoesNotAppearAndIsNotAnError(): void
    {
        $product = $this->insertPublishedProduct('Test Soft Deleted Product');
        $this->wishlists->add($this->customerId, ['productId' => $product]);

        $this->products->softDelete($product);

        $result = $this->wishlists->list($this->customerId);

        self::assertSame([], $result['data']);
    }

    public function testAnUnpublishedProductDoesNotAppearAndIsNotAnError(): void
    {
        $product = $this->insertPublishedProduct('Test Unpublish Product');
        $this->wishlists->add($this->customerId, ['productId' => $product]);

        $this->db->prepare("UPDATE products SET status = 'DRAFT' WHERE id = :id")->execute([':id' => $product]);

        $result = $this->wishlists->list($this->customerId);

        self::assertSame([], $result['data']);
    }

    public function testMergeAddsEveryValidId(): void
    {
        $a = $this->insertPublishedProduct('Test Merge A');
        $b = $this->insertPublishedProduct('Test Merge B');

        $result = $this->wishlists->merge($this->customerId, ['productIds' => [$a, $b]]);

        self::assertCount(2, $result['data']);
    }

    public function testMergeSkipsAnUnknownIdRatherThanFailingTheWholeCall(): void
    {
        $a = $this->insertPublishedProduct('Test Merge Skip Known');
        $unknown = UlidHelper::generate();

        $result = $this->wishlists->merge($this->customerId, ['productIds' => [$a, $unknown]]);

        self::assertCount(1, $result['data']);
        self::assertSame($a, $result['data'][0]['id']);
    }

    public function testMergeIsIdempotentAgainstAnExistingEntry(): void
    {
        $product = $this->insertPublishedProduct('Test Merge Idempotent');
        $this->wishlists->add($this->customerId, ['productId' => $product]);

        $result = $this->wishlists->merge($this->customerId, ['productIds' => [$product]]);

        self::assertCount(1, $result['data']);
    }

    public function testMergeWithAnEmptyListIsANoOp(): void
    {
        $product = $this->insertPublishedProduct('Test Merge Empty');
        $this->wishlists->add($this->customerId, ['productId' => $product]);

        $result = $this->wishlists->merge($this->customerId, ['productIds' => []]);

        self::assertCount(1, $result['data']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertPublishedProduct(string $name): string
    {
        return $this->insertProduct($name, 'PUBLISHED');
    }

    private function insertDraftProduct(): string
    {
        return $this->insertProduct('Test Draft Product', 'DRAFT');
    }

    private function insertProduct(string $name, string $status): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $categoryId = $this->insertCategory();

        $this->db->prepare(
            'INSERT INTO products (id, category_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, :status, :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':category_id' => $categoryId, ':name' => $name,
            ':slug' => 'test-product-' . bin2hex(random_bytes(6)), ':status' => $status,
            ':created_at' => $now, ':updated_at' => $now,
        ]);

        return $id;
    }

    private function insertCategory(): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $this->db->prepare(
            'INSERT INTO categories (id, name, slug, sort_order, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, 0, 1, :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':name' => 'Test Category', ':slug' => 'test-category-' . bin2hex(random_bytes(6)),
            ':created_at' => $now, ':updated_at' => $now,
        ]);

        return $id;
    }

    private function insertCustomer(): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $unique = bin2hex(random_bytes(6));

        $this->db->prepare(
            'INSERT INTO customers (id, email, name, created_at, updated_at)
             VALUES (:id, :email, :name, :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':email' => "test-{$unique}@example.test", ':name' => 'Test Customer',
            ':created_at' => $now, ':updated_at' => $now,
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
