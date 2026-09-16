<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Mail\ReviewApprovedMail;
use Rajdhani\Mail\ReviewSubmittedMail;
use Rajdhani\Repositories\AdminUserRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReviewRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Database;
use Rajdhani\Support\Mailer;

/**
 * Review submission, moderation, and the rating aggregates a product page
 * reads (doc §8.5, §8.6, §9.4, §9.7, §9.10, §11; RTPP-27).
 *
 * **One review per customer per product** — `uq_reviews_product_customer` —
 * enforced two layers deep like every other uniqueness constraint in this
 * codebase: a pre-check here for a clean `409` naming the existing review,
 * `uq_reviews_product_customer` for the race a pre-check cannot catch.
 *
 * **`rating_average`/`rating_count` are denormalised onto `products`** (doc
 * §8.5) and recomputed here, inside a transaction, on every action the doc
 * names as a trigger: approve, reject, and delete. Rejecting a review that
 * was never approved recomputes to the same numbers it already had — a
 * wasted query, not an incorrect one — which is a simpler and equally
 * correct rule than tracking "was this review's status previously
 * APPROVED" just to skip it.
 *
 * **Approve and reject are unconditional transitions**, not restricted to
 * reviews currently `PENDING` — doc §8.5 explicitly names "rejected *after
 * approval*" as one of the three recompute triggers, which only makes sense
 * if `APPROVED → REJECTED` is a real, supported transition, not a bug.
 *
 * **Bulk approve/reject apply what they can and report what they cannot**,
 * the same contract `CategoryRepository::reorder()`/`BannerRepository::reorder()`
 * use for a batch of independent existing rows: an id already gone (another
 * moderator handled it first) does not block the rest of the batch from
 * being applied.
 */
final class ReviewService
{
    use HandlesTransactions;
    use ValidatesInput;

    private readonly PDO $db;

    public function __construct(
        private readonly ReviewRepository $reviews = new ReviewRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly AdminUserRepository $admins = new AdminUserRepository(),
        private readonly Mailer $mailer = new Mailer(),
        ?PDO $connection = null,
    ) {
        $this->db = $connection ?? Database::connection();
    }

    // ─── admin ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $status = $this->optionalStatusFilter($query);
        $productId = $this->optionalUlid($query, 'productId');
        $rating = $this->optionalRatingFilter($query);

        $rows = $this->reviews->paginate($pagination->limit, $pagination->offset(), $status, $productId, $rating);
        $total = $this->reviews->count($status, $productId, $rating);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function approve(string $moderatorId, string $id): array
    {
        $review = $this->requireReview($id);

        $this->transaction(function () use ($moderatorId, $id, $review): void {
            $this->reviews->approve($id, $moderatorId);
            $this->recomputeAggregate((string) $review['product_id']);
        });

        $withContext = $this->requireReviewWithContext($id);
        $this->notifyReviewApproved($withContext);

        return $this->adminView($withContext);
    }

    /**
     * "Review approved" (§14.4) → confirmation to the reviewer. Sent after
     * the transaction commits — `Mailer::send()` never throws, so a mail
     * failure here can never undo the approval or the aggregate recompute
     * it just committed.
     *
     * @param array<string,mixed> $row the joined shape `requireReviewWithContext()` returns
     */
    private function notifyReviewApproved(array $row): void
    {
        $mail = new ReviewApprovedMail((string) $row['product_name']);
        $this->mailer->send([(string) $row['customer_email']], $mail->subject(), $mail->html());
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reject(string $moderatorId, string $id, array $input): array
    {
        $review = $this->requireReview($id);
        $reason = $this->requiredText($input, 'reason', 512);

        $this->transaction(function () use ($moderatorId, $id, $reason, $review): void {
            $this->reviews->reject($id, $moderatorId, $reason);
            $this->recomputeAggregate((string) $review['product_id']);
        });

        return $this->adminView($this->requireReviewWithContext($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function bulkApprove(string $moderatorId, array $input): array
    {
        return $this->bulkTransition($input, fn (string $id, array $review) => $this->reviews->approve($id, $moderatorId));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function bulkReject(string $moderatorId, array $input): array
    {
        $reason = $this->requiredText($input, 'reason', 512);

        return $this->bulkTransition(
            $input,
            fn (string $id, array $review) => $this->reviews->reject($id, $moderatorId, $reason),
        );
    }

    /** A hard delete — see the repository class doc. `DELETE /admin/reviews/:id` is Super-Admin-only at the route (`RequireSuperAdmin`), not enforced here. */
    public function adminDelete(string $id): void
    {
        $review = $this->reviews->find($id);

        if ($review === null) {
            return;
        }

        $this->transaction(function () use ($id, $review): void {
            $this->reviews->delete($id);
            $this->recomputeAggregate((string) $review['product_id']);
        });
    }

    // ─── customer-facing ────────────────────────────────────────────────────

    /**
     * `POST /public/products/:slug/reviews` (doc §9.4): always creates a
     * `PENDING` review — the DoD's "invisible until approved" starts here,
     * not as a special case in the public read path.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(string $customerId, string $productSlug, array $input): array
    {
        $product = $this->products->findPublishedBySlug($productSlug);

        if ($product === null) {
            throw ApiError::notFound('No such product');
        }

        $productId = (string) $product['id'];

        if ($this->reviews->existsForCustomerProduct($customerId, $productId)) {
            throw ApiError::conflict('You have already reviewed this product', [
                ['field' => '', 'message' => 'Edit your existing review instead of submitting a new one'],
            ]);
        }

        $fields = $this->coreFields($input, partial: false) + [
            'product_id' => $productId, 'customer_id' => $customerId,
        ];

        $id = $this->reviews->create($fields);
        $this->notifyReviewSubmitted((string) $product['name'], (int) $fields['rating'], (string) $fields['comment']);

        return $this->ownView($this->requireOwnByProduct($customerId, $id));
    }

    /**
     * "Review submitted" (§14.4) → the Editor list. Recipients are live
     * `admin_users` holding the Editor role, not a `settings` key — see
     * `AdminUserRepository::activeEmailsForRole()`'s doc. `Mailer::send()`
     * never throws, so a mail failure here can never fail the submission.
     */
    private function notifyReviewSubmitted(string $productName, int $rating, string $comment): void
    {
        $mail = new ReviewSubmittedMail($productName, $rating, $comment);
        $this->mailer->send($this->admins->activeEmailsForRole('EDITOR'), $mail->subject(), $mail->html());
    }

    /**
     * `GET /public/my/reviews` (doc §9.7).
     *
     * @return list<array<string,mixed>>
     */
    public function myReviews(string $customerId): array
    {
        return array_map($this->ownView(...), $this->reviews->ownReviews($customerId));
    }

    /**
     * `PATCH /public/my/reviews/:id` (doc §9.7): resets to `PENDING`
     * unconditionally — see the repository class doc.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updateOwn(string $customerId, string $id, array $input): array
    {
        $existing = $this->requireOwn($customerId, $id);
        $wasApproved = $existing['status'] === 'APPROVED';
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $productId = (string) $existing['product_id'];

        $this->transaction(function () use ($id, $fields, $wasApproved, $productId): void {
            $this->reviews->updateOwnContent($id, $fields);

            // Editing an approved review un-approves it (see the repository
            // class doc), which is itself one of the three recompute
            // triggers — an edited review can no longer count toward the
            // aggregate a public page is showing right now.
            if ($wasApproved) {
                $this->recomputeAggregate($productId);
            }
        });

        return $this->ownView($this->requireOwnByProduct($customerId, $id));
    }

    /** `DELETE /public/my/reviews/:id` (doc §9.7). Idempotent: deleting a review that is already gone is not an error. */
    public function deleteOwn(string $customerId, string $id): void
    {
        $existing = $this->reviews->findOwn($customerId, $id);

        if ($existing === null) {
            return;
        }

        $productId = (string) $existing['product_id'];

        $this->transaction(function () use ($id, $productId): void {
            $this->reviews->delete($id);
            $this->recomputeAggregate($productId);
        });
    }

    /**
     * `GET /public/products/:slug/reviews` (doc §9.4) — approved reviews,
     * plus the two aggregates and the star-rating distribution the ticket
     * names as "computed aggregates per product." They ride in `meta`
     * alongside pagination rather than duplicating onto every row, or onto
     * the separate product-detail payload that already carries
     * `rating_average`/`rating_count` — the reviews tab can load
     * independently of a full product re-fetch and still have every number
     * it needs.
     *
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function publicList(string $productSlug, array $query): array
    {
        $product = $this->products->findPublishedBySlug($productSlug);

        if ($product === null) {
            throw ApiError::notFound('No such product');
        }

        $productId = (string) $product['id'];
        $pagination = Pagination::fromQuery($query);

        $rows = $this->reviews->publicPaginate($productId, $pagination->limit, $pagination->offset());
        $total = $this->reviews->publicCount($productId);
        $aggregate = $this->reviews->aggregateForProduct($productId);

        $meta = $pagination->meta($total);
        $meta['rating_average'] = (float) $aggregate['average'];
        $meta['rating_count'] = $aggregate['count'];
        $meta['distribution'] = $this->fullDistribution($this->reviews->distributionForProduct($productId));

        return [
            'data' => array_map($this->publicView(...), $rows),
            'meta' => $meta,
        ];
    }

    // ─── shared field validation ────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('rating')) {
            $fields['rating'] = $this->requiredRating($input);
        }

        if ($has('title')) {
            $fields['title'] = $this->optionalText($input, 'title', 255);
        }

        if ($has('comment')) {
            $fields['comment'] = $this->requiredText($input, 'comment', 65535);
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function requiredRating(array $input): int
    {
        $value = $input['rating'] ?? null;

        if (!is_numeric($value) || (int) $value != $value) {
            throw $this->invalid('rating', 'This field is required and must be a whole number from 1 to 5');
        }

        $rating = (int) $value;

        if ($rating < 1 || $rating > 5) {
            throw $this->invalid('rating', 'Must be between 1 and 5');
        }

        return $rating;
    }

    /** @param array<string,mixed> $query */
    private function optionalStatusFilter(array $query): ?string
    {
        $value = $query['status'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
            throw $this->invalid('status', 'Must be one of: PENDING, APPROVED, REJECTED');
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalRatingFilter(array $query): ?int
    {
        $value = $query['rating'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 1 || (int) $value > 5) {
            throw $this->invalid('rating', 'Must be between 1 and 5');
        }

        return (int) $value;
    }

    /**
     * @param callable(string,array<string,mixed>):mixed $transition
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function bulkTransition(array $input, callable $transition): array
    {
        $ids = $this->validateIdList($input, 'ids');
        $missing = [];
        $affectedProducts = [];

        $this->transaction(function () use ($ids, $transition, &$missing, &$affectedProducts): void {
            foreach ($ids as $id) {
                $review = $this->reviews->find($id);

                if ($review === null) {
                    $missing[] = $id;

                    continue;
                }

                $transition($id, $review);
                $affectedProducts[(string) $review['product_id']] = true;
            }

            foreach (array_keys($affectedProducts) as $productId) {
                $this->recomputeAggregate($productId);
            }
        });

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not match an existing review', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such review: {$id}"],
                $missing,
            ));
        }

        return ['updated' => count($ids) - count($missing)];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return list<string>
     */
    private function validateIdList(array $input, string $field): array
    {
        $ids = $input[$field] ?? null;

        if (!is_array($ids) || $ids === []) {
            throw $this->invalid($field, 'This field is required and must be a non-empty list');
        }

        $normalised = [];

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw $this->invalid($field, 'Every entry must be a valid review id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /**
     * @param array<int,int> $counted
     *
     * @return array<int,int> every rating 1..5, zero-filled where there is no approved review at that rating
     */
    private function fullDistribution(array $counted): array
    {
        $full = [];

        for ($rating = 1; $rating <= 5; $rating++) {
            $full[$rating] = $counted[$rating] ?? 0;
        }

        return $full;
    }

    private function recomputeAggregate(string $productId): void
    {
        $aggregate = $this->reviews->aggregateForProduct($productId);
        $this->products->updateRatingAggregate($productId, $aggregate['average'], $aggregate['count']);
    }

    // ─── lookups ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireReview(string $id): array
    {
        $review = $this->reviews->find($id);

        if ($review === null) {
            throw ApiError::notFound('No such review');
        }

        return $review;
    }

    /**
     * The joined shape `adminView()` needs — see
     * `ReviewRepository::findWithContext()`'s class doc.
     *
     * @return array<string,mixed>
     */
    private function requireReviewWithContext(string $id): array
    {
        $review = $this->reviews->findWithContext($id);

        if ($review === null) {
            throw ApiError::notFound('No such review');
        }

        return $review;
    }

    /** @return array<string,mixed> */
    private function requireOwn(string $customerId, string $id): array
    {
        $review = $this->reviews->findOwn($customerId, $id);

        if ($review === null) {
            throw ApiError::notFound('No such review');
        }

        return $review;
    }

    /**
     * `ownReviews()`'s joined shape is what `ownView()` expects; a freshly
     * created or updated row is re-read through that same shape rather than
     * reusing the plain `find()` row, so the two code paths never drift.
     *
     * @return array<string,mixed>
     */
    private function requireOwnByProduct(string $customerId, string $id): array
    {
        foreach ($this->reviews->ownReviews($customerId) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        throw ApiError::internal('Review vanished immediately after being written');
    }

    // ─── output shaping ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $row as `ReviewRepository::paginate()` returns
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'product'          => ['id' => (string) $row['product_id'], 'name' => (string) $row['product_name'], 'slug' => (string) $row['product_slug']],
            'customer'         => ['id' => (string) $row['customer_id'], 'name' => (string) $row['customer_name'], 'email' => (string) $row['customer_email']],
            'rating'           => (int) $row['rating'],
            'title'            => $row['title'] === null ? null : (string) $row['title'],
            'comment'          => (string) $row['comment'],
            'status'           => (string) $row['status'],
            'moderated_by_id'  => $row['moderated_by_id'] === null ? null : (string) $row['moderated_by_id'],
            'moderated_at'     => $row['moderated_at'] === null ? null : DateHelper::iso((string) $row['moderated_at']),
            'rejection_reason' => $row['rejection_reason'] === null ? null : (string) $row['rejection_reason'],
            'created_at'       => DateHelper::iso((string) $row['created_at']),
            'updated_at'       => DateHelper::iso((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $row as `ReviewRepository::ownReviews()` returns
     *
     * @return array<string,mixed>
     */
    private function ownView(array $row): array
    {
        return [
            'id'                => (string) $row['id'],
            'product'           => ['id' => (string) $row['product_id'], 'name' => (string) $row['product_name'], 'slug' => (string) $row['product_slug']],
            'rating'            => (int) $row['rating'],
            'title'             => $row['title'] === null ? null : (string) $row['title'],
            'comment'           => (string) $row['comment'],
            'status'            => (string) $row['status'],
            'rejection_reason'  => $row['rejection_reason'] === null ? null : (string) $row['rejection_reason'],
            'created_at'        => DateHelper::iso((string) $row['created_at']),
            'updated_at'        => DateHelper::iso((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $row as `ReviewRepository::publicPaginate()` returns
     *
     * @return array<string,mixed>
     */
    private function publicView(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'rating'        => (int) $row['rating'],
            'title'         => $row['title'] === null ? null : (string) $row['title'],
            'comment'       => (string) $row['comment'],
            'customer_name' => (string) $row['customer_name'],
            'created_at'    => DateHelper::iso((string) $row['created_at']),
        ];
    }
}
