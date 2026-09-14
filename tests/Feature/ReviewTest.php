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
 * Admin review moderation and the rating aggregate it recomputes on
 * approve/reject/delete (doc §8.5, §8.6, §9.10, §11; RTPP-27), against a
 * real database.
 *
 * `testAggregateMatchesAManualCountAfterApproveRejectDelete()` is this
 * ticket's headline DoD item, written exactly as the ticket states it: build
 * a sequence of approve/reject/delete, then compare the recomputed
 * `products.rating_average`/`rating_count` against a manual calculation over
 * whatever survives — not against a value the test assumes in advance.
 */
final class ReviewTest extends DatabaseTestCase
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

    public function testAdminFiltersByStatus(): void
    {
        $product = $this->insertPublishedProduct();
        $pendingReview = $this->submit($this->insertCustomer(), $product, 3);
        $approvedReview = $this->submit($this->insertCustomer(), $product, 5);
        $this->reviews->approve($this->moderatorId, $approvedReview['id']);

        $pending = $this->reviews->paginate(['status' => 'PENDING']);
        $approved = $this->reviews->paginate(['status' => 'APPROVED']);

        self::assertSame(1, $pending['meta']['total']);
        self::assertSame($pendingReview['id'], $pending['data'][0]['id']);
        self::assertSame(1, $approved['meta']['total']);
        self::assertSame('APPROVED', $approved['data'][0]['status']);
    }

    public function testAdminFiltersByProductId(): void
    {
        $productA = $this->insertPublishedProduct();
        $productB = $this->insertPublishedProduct();
        $customer = $this->insertCustomer();
        $this->submit($customer, $productA, 4);
        $this->submit($this->insertCustomer(), $productB, 3);

        $result = $this->reviews->paginate(['productId' => $productA]);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame($productA, $result['data'][0]['product']['id']);
    }

    public function testAdminFiltersByRating(): void
    {
        $product = $this->insertPublishedProduct();
        $this->submit($this->insertCustomer(), $product, 5);
        $this->submit($this->insertCustomer(), $product, 2);

        $result = $this->reviews->paginate(['productId' => $product, 'rating' => 5]);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame(5, $result['data'][0]['rating']);
    }

    public function testApproveSetsStatusAndModerator(): void
    {
        $product = $this->insertPublishedProduct();
        $review = $this->submit($this->insertCustomer(), $product, 4);

        $approved = $this->reviews->approve($this->moderatorId, $review['id']);

        self::assertSame('APPROVED', $approved['status']);
        self::assertSame($this->moderatorId, $approved['moderated_by_id']);
        self::assertNotNull($approved['moderated_at']);
    }

    public function testRejectRequiresAReason(): void
    {
        $product = $this->insertPublishedProduct();
        $review = $this->submit($this->insertCustomer(), $product, 1);

        $error = $this->captureApiError(fn () => $this->reviews->reject($this->moderatorId, $review['id'], []));

        self::assertSame('reason', $error->details()[0]['field']);
    }

    public function testRejectAfterApprovalRemovesItFromTheAggregate(): void
    {
        $product = $this->insertPublishedProduct();
        $a = $this->submit($this->insertCustomer(), $product, 5);
        $b = $this->submit($this->insertCustomer(), $product, 3);
        $this->reviews->approve($this->moderatorId, $a['id']);
        $this->reviews->approve($this->moderatorId, $b['id']);

        self::assertSame(2, $this->productRatingCount($product));

        $this->reviews->reject($this->moderatorId, $a['id'], ['reason' => 'No longer accurate']);

        self::assertSame(1, $this->productRatingCount($product));
        self::assertSame(3.0, $this->productRatingAverage($product));
    }

    public function testBulkApproveAppliesValidIdsAndReportsMissing(): void
    {
        $product = $this->insertPublishedProduct();
        $a = $this->submit($this->insertCustomer(), $product, 4);
        $missingId = UlidHelper::generate();

        $error = $this->captureApiError(
            fn () => $this->reviews->bulkApprove($this->moderatorId, ['ids' => [$a['id'], $missingId]])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertSame('APPROVED', $this->reviews->paginate(['productId' => $product])['data'][0]['status']);
    }

    public function testBulkRejectRequiresAReason(): void
    {
        $product = $this->insertPublishedProduct();
        $a = $this->submit($this->insertCustomer(), $product, 4);

        $error = $this->captureApiError(fn () => $this->reviews->bulkReject($this->moderatorId, ['ids' => [$a['id']]]));

        self::assertSame('reason', $error->details()[0]['field']);
    }

    public function testAdminHardDeleteRecomputesAggregate(): void
    {
        $product = $this->insertPublishedProduct();
        $review = $this->submit($this->insertCustomer(), $product, 5);
        $this->reviews->approve($this->moderatorId, $review['id']);

        self::assertSame(1, $this->productRatingCount($product));

        $this->reviews->adminDelete($review['id']);

        self::assertSame(0, $this->productRatingCount($product));
        self::assertSame(0.0, $this->productRatingAverage($product));
    }

    public function testAdminDeleteIsIdempotent(): void
    {
        $this->reviews->adminDelete(UlidHelper::generate());
        self::assertTrue(true);
    }

    /**
     * The DoD, literally: "The aggregate matches a manual count after a
     * sequence of approve/reject/delete."
     */
    public function testAggregateMatchesAManualCountAfterApproveRejectDelete(): void
    {
        $product = $this->insertPublishedProduct();

        $ratings = [5, 4, 3, 2, 1];
        $reviewIds = [];

        foreach ($ratings as $rating) {
            $reviewIds[] = $this->submit($this->insertCustomer(), $product, $rating)['id'];
        }

        // Approve everything, then reject one already-approved review and
        // delete another — a real sequence touching all three triggers.
        foreach ($reviewIds as $id) {
            $this->reviews->approve($this->moderatorId, $id);
        }

        $this->reviews->reject($this->moderatorId, $reviewIds[0], ['reason' => 'Reconsidered']);
        $this->reviews->adminDelete($reviewIds[1]);

        // Manually: ratings[2..4] = [3, 2, 1] remain approved.
        $survivingRatings = [3, 2, 1];
        $expectedAverage = round(array_sum($survivingRatings) / count($survivingRatings), 1);

        self::assertSame(count($survivingRatings), $this->productRatingCount($product));
        self::assertSame($expectedAverage, $this->productRatingAverage($product));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function submit(string $customerId, string $productId, int $rating): array
    {
        $product = $this->products->find($productId);

        return $this->reviews->create($customerId, (string) $product['slug'], [
            'rating' => $rating, 'comment' => 'A perfectly ordinary review.',
        ]);
    }

    private function productRatingCount(string $productId): int
    {
        return (int) $this->products->find($productId)['rating_count'];
    }

    private function productRatingAverage(string $productId): float
    {
        return (float) $this->products->find($productId)['rating_average'];
    }

    private function insertPublishedProduct(): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();
        $categoryId = $this->insertCategory();

        $this->db->prepare(
            'INSERT INTO products (id, category_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, \'PUBLISHED\', :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':category_id' => $categoryId, ':name' => 'Test Product',
            ':slug' => 'test-product-' . bin2hex(random_bytes(6)),
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
