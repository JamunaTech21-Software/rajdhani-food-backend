<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `wishlist_items` (doc §8.6, §9.7; RTPP-28).
 *
 * `uq_wishlist_customer_product` makes "is this already wishlisted" a
 * genuine uniqueness constraint, not just an application convention — `add()`
 * is idempotent against it the same two-layer way every other uniqueness
 * constraint in this codebase is handled (see `WishlistService`'s class doc).
 *
 * No `deleted_at`: `fk_wishlist_product` is `ON DELETE CASCADE`, so a
 * genuine hard `DELETE` of a product takes its wishlist rows with it at the
 * database level. Products in this codebase are soft-deleted in practice
 * (`ProductRepository::softDelete()`), which the cascade cannot see — that
 * case is handled on the read side instead, in
 * `ProductRepository::publicCardsForIds()`.
 */
final class WishlistRepository extends Repository
{
    /**
     * Newest-added first — a wishlist reads most naturally as "what I most
     * recently saved", not alphabetical or by product id. `id DESC` breaks
     * a tie on `created_at`'s millisecond precision (two adds landing in
     * the same millisecond are not a hypothetical — a test proved it):
     * a ULID sorts lexicographically by creation time, so this agrees with
     * true insertion order even when the timestamp alone cannot.
     *
     * @return list<array<string,mixed>>
     */
    public function listForCustomer(string $customerId): array
    {
        return $this->all(
            'SELECT id, product_id, created_at FROM wishlist_items
              WHERE customer_id = :customer_id
              ORDER BY created_at DESC, id DESC',
            [':customer_id' => $customerId],
        );
    }

    public function exists(string $customerId, string $productId): bool
    {
        return $this->scalar(
            'SELECT id FROM wishlist_items WHERE customer_id = :customer_id AND product_id = :product_id',
            [':customer_id' => $customerId, ':product_id' => $productId],
        ) !== null;
    }

    public function add(string $customerId, string $productId): string
    {
        $id = UlidHelper::generate();

        $this->run(
            'INSERT INTO wishlist_items (id, customer_id, product_id, created_at)
             VALUES (:id, :customer_id, :product_id, :created_at)',
            [':id' => $id, ':customer_id' => $customerId, ':product_id' => $productId, ':created_at' => $this->now()],
        );

        return $id;
    }

    /** Idempotent: removing an entry that never existed affects zero rows, not an error. */
    public function remove(string $customerId, string $productId): int
    {
        return $this->run(
            'DELETE FROM wishlist_items WHERE customer_id = :customer_id AND product_id = :product_id',
            [':customer_id' => $customerId, ':product_id' => $productId],
        );
    }
}
