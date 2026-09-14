<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `reviews` (doc §8.6, §9.10, §11; RTPP-27).
 *
 * `uq_reviews_product_customer` enforces one review per customer per
 * product at the database level — a customer's second submission against
 * the same product must edit their existing row, never create a second one.
 * No `deleted_at`: a review's own delete (by its author or by an admin,
 * doc §9.10's "Super Admin — Hard delete") is a genuine hard `DELETE`,
 * nothing references a review's id.
 *
 * This repository never writes to `products` — `ReviewService` orchestrates
 * `aggregateForProduct()` here plus `ProductRepository::updateRatingAggregate()`
 * inside one transaction. Keeping each repository scoped to its own table is
 * what makes "is anything interpolating user input into SQL?" a bounded
 * question across the whole codebase (see `Repository`'s class doc).
 */
final class ReviewRepository extends Repository
{
    private const COLUMNS = 'id, product_id, customer_id, rating, title, comment, status,
                             moderated_by_id, moderated_at, rejection_reason, created_at, updated_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $status, ?string $productId, ?int $rating): array
    {
        [$where, $parameters] = $this->adminFilter($status, $productId, $rating);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            "SELECT r.id, r.product_id, p.name AS product_name, p.slug AS product_slug,
                    r.customer_id, c.name AS customer_name, c.email AS customer_email,
                    r.rating, r.title, r.comment, r.status,
                    r.moderated_by_id, r.moderated_at, r.rejection_reason, r.created_at, r.updated_at
               FROM reviews r
               INNER JOIN products  p ON p.id = r.product_id
               INNER JOIN customers c ON c.id = r.customer_id
              {$where}
              ORDER BY r.created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $status, ?string $productId, ?int $rating): int
    {
        [$where, $parameters] = $this->adminFilter($status, $productId, $rating);

        return (int) $this->scalar("SELECT COUNT(*) FROM reviews r {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM reviews WHERE id = :id', [':id' => $id]);
    }

    /**
     * The same joined shape `paginate()` returns, for exactly one review —
     * what `ReviewService::adminView()` actually needs after `approve()`/
     * `reject()`, as opposed to `find()`'s bare row (used only where the
     * caller needs `product_id` and nothing about its context).
     *
     * @return array<string,mixed>|null
     */
    public function findWithContext(string $id): ?array
    {
        return $this->one(
            'SELECT r.id, r.product_id, p.name AS product_name, p.slug AS product_slug,
                    r.customer_id, c.name AS customer_name, c.email AS customer_email,
                    r.rating, r.title, r.comment, r.status,
                    r.moderated_by_id, r.moderated_at, r.rejection_reason, r.created_at, r.updated_at
               FROM reviews r
               INNER JOIN products  p ON p.id = r.product_id
               INNER JOIN customers c ON c.id = r.customer_id
              WHERE r.id = :id',
            [':id' => $id],
        );
    }

    /**
     * A customer may only ever act on their own review — a mismatch is
     * reported as missing, never forbidden (doc §7's "never reveal which"
     * convention).
     *
     * @return array<string,mixed>|null
     */
    public function findOwn(string $customerId, string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM reviews WHERE id = :id AND customer_id = :customer_id',
            [':id' => $id, ':customer_id' => $customerId],
        );
    }

    /** @param string|null $excludingId the review being edited must not count as a collision with itself */
    public function existsForCustomerProduct(string $customerId, string $productId, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM reviews WHERE customer_id = :customer_id AND product_id = :product_id';
        $parameters = [':customer_id' => $customerId, ':product_id' => $productId];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    /**
     * Always `PENDING` on creation — see the class doc on why the caller
     * never gets to choose otherwise.
     *
     * @param array<string,scalar|null> $fields
     */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + [
            'id' => $id, 'status' => 'PENDING',
            'moderated_by_id' => null, 'moderated_at' => null, 'rejection_reason' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO reviews (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /**
     * The author's own edit: rating/title/comment change, and moderation
     * resets to `PENDING` unconditionally — an edited review is content an
     * admin has not seen yet, whatever it was before.
     *
     * @param array<string,scalar|null> $fields
     */
    public function updateOwnContent(string $id, array $fields): int
    {
        $fields += ['status' => 'PENDING', 'moderated_by_id' => null, 'moderated_at' => null, 'rejection_reason' => null];

        return $this->applyUpdate($id, $fields);
    }

    public function approve(string $id, string $moderatorId): int
    {
        return $this->applyUpdate($id, [
            'status' => 'APPROVED', 'moderated_by_id' => $moderatorId,
            'moderated_at' => $this->now(), 'rejection_reason' => null,
        ]);
    }

    public function reject(string $id, string $moderatorId, string $reason): int
    {
        return $this->applyUpdate($id, [
            'status' => 'REJECTED', 'moderated_by_id' => $moderatorId,
            'moderated_at' => $this->now(), 'rejection_reason' => $reason,
        ]);
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM reviews WHERE id = :id', [':id' => $id]);
    }

    /**
     * A customer's own reviews, every status, newest first — doc §9.7's
     * "own reviews with status".
     *
     * @return list<array<string,mixed>>
     */
    public function ownReviews(string $customerId): array
    {
        return $this->all(
            'SELECT r.id, r.product_id, p.name AS product_name, p.slug AS product_slug,
                    r.rating, r.title, r.comment, r.status, r.rejection_reason, r.created_at, r.updated_at
               FROM reviews r
               INNER JOIN products p ON p.id = r.product_id
              WHERE r.customer_id = :customer_id
              ORDER BY r.created_at DESC',
            [':customer_id' => $customerId],
        );
    }

    /**
     * `GET /public/products/:slug/reviews` (doc §9.4): approved only, newest
     * first.
     *
     * @return list<array<string,mixed>>
     */
    public function publicPaginate(string $productId, int $limit, int $offset): array
    {
        return $this->all(
            "SELECT r.id, r.rating, r.title, r.comment, r.created_at, c.name AS customer_name
               FROM reviews r
               INNER JOIN customers c ON c.id = r.customer_id
              WHERE r.product_id = :product_id AND r.status = 'APPROVED'
              ORDER BY r.created_at DESC
              LIMIT :limit OFFSET :offset",
            [':product_id' => $productId, ':limit' => $limit, ':offset' => $offset],
        );
    }

    public function publicCount(string $productId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM reviews WHERE product_id = :product_id AND status = 'APPROVED'",
            [':product_id' => $productId],
        );
    }

    /**
     * The two numbers denormalised onto `products` (doc §8.5) — computed
     * from approved reviews only, which is what makes the DoD's "JSON-LD
     * `AggregateRating` reflects approved reviews only" true by construction:
     * a PENDING or REJECTED review is never part of this query, so it can
     * never reach the column a public page reads.
     *
     * @return array{average:string,count:int}
     */
    public function aggregateForProduct(string $productId): array
    {
        $row = $this->one(
            "SELECT COALESCE(ROUND(AVG(rating), 1), 0.0) AS average, COUNT(*) AS count
               FROM reviews WHERE product_id = :product_id AND status = 'APPROVED'",
            [':product_id' => $productId],
        );

        return ['average' => (string) ($row['average'] ?? '0.0'), 'count' => (int) ($row['count'] ?? 0)];
    }

    /**
     * How many approved reviews landed at each star rating — "the
     * distribution used by the reviews tab" the ticket names explicitly.
     * Ratings with zero approved reviews are not returned by the `GROUP BY`
     * at all; the service fills every one of 1..5 in, zero or not, so the
     * tab can render a full five-bar chart without checking for missing keys.
     *
     * @return array<int,int> rating (1-5) => count, only ratings that have at least one approved review
     */
    public function distributionForProduct(string $productId): array
    {
        $rows = $this->all(
            "SELECT rating, COUNT(*) AS n FROM reviews
              WHERE product_id = :product_id AND status = 'APPROVED'
              GROUP BY rating",
            [':product_id' => $productId],
        );

        $distribution = [];

        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['n'];
        }

        return $distribution;
    }

    /** @param array<string,scalar|null> $fields */
    private function applyUpdate(string $id, array $fields): int
    {
        $assignments = [];
        $parameters = [':id' => $id, ':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE reviews SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = :id',
            $parameters,
        );
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function adminFilter(?string $status, ?string $productId, ?int $rating): array
    {
        $where = 'WHERE 1 = 1';
        $parameters = [];

        if ($status !== null) {
            $where .= ' AND r.status = :status';
            $parameters[':status'] = $status;
        }

        if ($productId !== null) {
            $where .= ' AND r.product_id = :product_id';
            $parameters[':product_id'] = $productId;
        }

        if ($rating !== null) {
            $where .= ' AND r.rating = :rating';
            $parameters[':rating'] = $rating;
        }

        return [$where, $parameters];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
