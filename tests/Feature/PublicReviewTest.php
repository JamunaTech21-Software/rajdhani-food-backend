<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReviewRepository;
use Rajdhani\Services\ReviewService;

/**
 * Customer-facing review submission, own-review management, and the public
 * reviews tab (doc §8.6, §9.4, §9.7; RTPP-27), against a real database.
 *
 * `testASubmittedReviewIsInvisibleUntilApproved()` is this ticket's first
 * DoD item.
 */
final class PublicReviewTest extends DatabaseTestCase
{
    private ReviewService $reviews;
    private ReviewRepository $repository;
    private ProductRepository $products;
    private string $moderatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ReviewRepository($this->db);
        $this->products = new ProductRepository($this->db);
        $this->reviews = new ReviewService($this->repository, $this->products, connection: $this->db);
        $this->moderatorId = (string) $this->db->query('SELECT id FROM admin_users LIMIT 1')->fetchColumn();
    }

    public function testASubmittedReviewIsInvisibleUntilApproved(): void
    {
        [$slug, $productId] = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();

        $this->reviews->create($customer, $slug, ['rating' => 5, 'comment' => 'Wonderful tea.']);

        $listing = $this->reviews->publicList($slug, []);
        self::assertSame(0, $listing['meta']['total']);

        $this->assertProductAggregateIs($productId, 0.0, 0);
    }

    public function testSubmittingTwiceForTheSameProductIsRejected(): void
    {
        [$slug] = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();

        $this->reviews->create($customer, $slug, ['rating' => 4, 'comment' => 'Good.']);

        $error = $this->captureApiError(
            fn () => $this->reviews->create($customer, $slug, ['rating' => 2, 'comment' => 'Changed my mind.'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    public function testSubmittingAgainstAnUnknownSlugIs404(): void
    {
        $error = $this->captureApiError(
            fn () => $this->reviews->create($this->insertCustomer(), 'no-such-product', ['rating' => 3, 'comment' => 'x'])
        );

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testRatingMustBeBetweenOneAndFive(): void
    {
        [$slug] = $this->insertPublishedProduct();

        $error = $this->captureApiError(
            fn () => $this->reviews->create($this->insertCustomer(), $slug, ['rating' => 6, 'comment' => 'x'])
        );

        self::assertSame('rating', $error->details()[0]['field']);
    }

    public function testEditingOwnApprovedReviewResetsItToPendingAndUnapprovesTheAggregate(): void
    {
        [$slug, $productId] = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();
        $review = $this->reviews->create($customer, $slug, ['rating' => 5, 'comment' => 'Great.']);
        $this->reviews->approve($this->moderatorId, $review['id']);

        $this->assertProductAggregateIs($productId, 5.0, 1);

        $updated = $this->reviews->updateOwn($customer, $review['id'], ['rating' => 5, 'comment' => 'Still great, minor edit.']);

        self::assertSame('PENDING', $updated['status']);
        $this->assertProductAggregateIs($productId, 0.0, 0);
    }

    public function testEditingSomeoneElsesReviewIs404(): void
    {
        [$slug] = $this->insertPublishedProduct();
        $owner = $this->insertCustomer();
        $intruder = $this->insertCustomer();
        $review = $this->reviews->create($owner, $slug, ['rating' => 4, 'comment' => 'Mine.']);

        $error = $this->captureApiError(
            fn () => $this->reviews->updateOwn($intruder, $review['id'], ['rating' => 1, 'comment' => 'Not yours.'])
        );

        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testDeletingOwnApprovedReviewRecomputesTheAggregate(): void
    {
        [$slug, $productId] = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();
        $review = $this->reviews->create($customer, $slug, ['rating' => 5, 'comment' => 'x']);
        $this->reviews->approve($this->moderatorId, $review['id']);

        $this->reviews->deleteOwn($customer, $review['id']);

        $this->assertProductAggregateIs($productId, 0.0, 0);
    }

    public function testDeletingOwnReviewIsIdempotent(): void
    {
        $customer = $this->insertCustomer();
        $this->reviews->deleteOwn($customer, UlidHelper::generate());
        self::assertTrue(true);
    }

    public function testMyReviewsListsOwnAcrossEveryStatus(): void
    {
        [$slugA] = $this->insertPublishedProduct();
        [$slugB] = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();
        $approved = $this->reviews->create($customer, $slugA, ['rating' => 5, 'comment' => 'x']);
        $this->reviews->create($customer, $slugB, ['rating' => 2, 'comment' => 'y']);
        $this->reviews->approve($this->moderatorId, $approved['id']);

        $mine = $this->reviews->myReviews($customer);

        self::assertCount(2, $mine);
        $statuses = array_column($mine, 'status');
        sort($statuses);
        self::assertSame(['APPROVED', 'PENDING'], $statuses);
    }

    public function testPublicListingReturnsOnlyApprovedReviews(): void
    {
        [$slug] = $this->insertPublishedProduct();
        $approved = $this->reviews->create($this->insertCustomer(), $slug, ['rating' => 5, 'comment' => 'Approved one.']);
        $this->reviews->create($this->insertCustomer(), $slug, ['rating' => 1, 'comment' => 'Still pending.']);
        $this->reviews->approve($this->moderatorId, $approved['id']);

        $listing = $this->reviews->publicList($slug, []);

        self::assertSame(1, $listing['meta']['total']);
        self::assertSame('Approved one.', $listing['data'][0]['comment']);
    }

    public function testPublicListingIncludesAggregateAndDistributionInMeta(): void
    {
        [$slug] = $this->insertPublishedProduct();
        $five = $this->reviews->create($this->insertCustomer(), $slug, ['rating' => 5, 'comment' => 'x']);
        $three = $this->reviews->create($this->insertCustomer(), $slug, ['rating' => 3, 'comment' => 'y']);
        $this->reviews->approve($this->moderatorId, $five['id']);
        $this->reviews->approve($this->moderatorId, $three['id']);

        $listing = $this->reviews->publicList($slug, []);

        self::assertSame(4.0, $listing['meta']['rating_average']);
        self::assertSame(2, $listing['meta']['rating_count']);
        self::assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 1], $listing['meta']['distribution']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function assertProductAggregateIs(string $productId, float $average, int $count): void
    {
        $product = $this->products->find($productId);

        self::assertSame($average, (float) $product['rating_average']);
        self::assertSame($count, (int) $product['rating_count']);
    }

    /** @return array{0:string,1:string} [slug, productId] */
    private function insertPublishedProduct(): array
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $categoryId = $this->insertCategory();
        $slug = 'test-product-' . bin2hex(random_bytes(6));

        $this->db->prepare(
            'INSERT INTO products (id, category_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, \'PUBLISHED\', :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':category_id' => $categoryId, ':name' => 'Test Product',
            ':slug' => $slug, ':created_at' => $now, ':updated_at' => $now,
        ]);

        return [$slug, $id];
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
