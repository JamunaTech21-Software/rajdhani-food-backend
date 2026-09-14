<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\WishlistRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * A customer's server-persisted wishlist (doc §8.6, §9.7, §18.6; RTPP-28).
 *
 * **`add()`, `remove()`, and `merge()` all return the fresh full list**,
 * not just the entry that changed — doc §8.6 says `POST /public/wishlist/merge`
 * "returns the merged server list" explicitly, and giving every mutation the
 * same contract means the client can always just replace its wishlist state
 * wholesale after any one of the three calls, rather than three different
 * response shapes to reconcile against local state.
 *
 * **`add()` is idempotent**, the same two-layer pattern as every other
 * uniqueness constraint in this codebase: a pre-check against
 * `WishlistRepository::exists()` for the common case, `uq_wishlist_customer_product`
 * for the race a pre-check cannot catch — either way the end state ("this
 * product is on this customer's wishlist") already holds, so neither path
 * is an error.
 *
 * **A product must be currently `PUBLISHED` to be added** — a customer can
 * only ever click "add to wishlist" on a product page they can see, so a
 * hand-crafted request naming a `DRAFT`/`ARCHIVED`/soft-deleted product id is
 * rejected the same way `ProductService::requirePublishedProduct()` rejects
 * one for a review. `merge()` relaxes this to "skip rather than reject the
 * whole batch" — a guest's local wishlist is client-controlled and can
 * easily carry a stale id for a product removed since it was saved, and
 * failing an entire login-time merge over one stale entry would be a worse
 * experience than silently dropping it.
 */
final class WishlistService
{
    use ValidatesInput;

    public function __construct(
        private readonly WishlistRepository $wishlist = new WishlistRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /** @return array{data:list<array<string,mixed>>} */
    public function list(string $customerId): array
    {
        return ['data' => $this->cardsFor($customerId)];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{data:list<array<string,mixed>>}
     */
    public function add(string $customerId, array $input): array
    {
        $productId = $this->requiredUlid($input, 'productId');

        if (!$this->products->isPublished($productId)) {
            throw ApiError::notFound('No such product');
        }

        $this->addIdempotently($customerId, $productId);

        return $this->list($customerId);
    }

    /** @return array{data:list<array<string,mixed>>} */
    public function remove(string $customerId, string $productId): array
    {
        $this->wishlist->remove($customerId, $productId);

        return $this->list($customerId);
    }

    /**
     * `POST /public/wishlist/merge` (doc §8.6) — see the class doc on why
     * invalid entries are skipped rather than failing the whole call.
     *
     * @param array<string,mixed> $input
     *
     * @return array{data:list<array<string,mixed>>}
     */
    public function merge(string $customerId, array $input): array
    {
        foreach ($this->validProductIds($input) as $productId) {
            if ($this->products->isPublished($productId)) {
                $this->addIdempotently($customerId, $productId);
            }
        }

        return $this->list($customerId);
    }

    private function addIdempotently(string $customerId, string $productId): void
    {
        if ($this->wishlist->exists($customerId, $productId)) {
            return;
        }

        try {
            $this->wishlist->add($customerId, $productId);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000' || !str_contains($e->getMessage(), 'uq_wishlist_customer_product')) {
                throw $e;
            }

            // Another request added the same pair between the check above
            // and this insert — the desired end state already holds.
        }
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return list<string>
     */
    private function validProductIds(array $input): array
    {
        $ids = $input['productIds'] ?? null;

        if (!is_array($ids)) {
            throw $this->invalid('productIds', 'This field is required and must be a list');
        }

        $valid = [];

        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $valid[] = $id;
            }
        }

        return array_values(array_unique($valid));
    }

    /** @return list<array<string,mixed>> */
    private function cardsFor(string $customerId): array
    {
        $rows = $this->wishlist->listForCustomer($customerId);
        $productIds = array_column($rows, 'product_id');

        if ($productIds === []) {
            return [];
        }

        $cardsById = [];

        foreach ($this->products->publicCardsForIds($productIds) as $card) {
            $cardsById[(string) $card['id']] = $card;
        }

        $cards = [];

        foreach ($rows as $row) {
            $productId = (string) $row['product_id'];

            // A wishlisted product since unpublished or soft-deleted has no
            // card here — dropped silently, not surfaced as a gap or an
            // error. See `ProductRepository::publicCardsForIds()`'s class doc.
            if (isset($cardsById[$productId])) {
                $cards[] = $this->view($cardsById[$productId]);
            }
        }

        return $cards;
    }

    /**
     * @param array<string,mixed> $row as `ProductRepository::publicCardsForIds()` returns
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        $hasImage = is_string($row['image_url'] ?? null);

        return [
            'id'                 => (string) $row['id'],
            'category'           => ['id' => (string) $row['category_id'], 'name' => (string) $row['category_name'], 'slug' => (string) $row['category_slug']],
            'name'               => (string) $row['name'],
            'slug'               => (string) $row['slug'],
            'short_description'  => $row['short_description'] === null ? null : (string) $row['short_description'],
            'tagline'            => $row['tagline'] === null ? null : (string) $row['tagline'],
            'badge_text'         => $row['badge_text'] === null ? null : (string) $row['badge_text'],
            'badge_color'        => $row['badge_color'] === null ? null : (string) $row['badge_color'],
            'is_featured'        => (int) $row['is_featured'] === 1,
            'rating_average'     => (float) $row['rating_average'],
            'rating_count'       => (int) $row['rating_count'],
            'image'              => $hasImage ? [
                'url' => (string) $row['image_url'],
                'alt' => $row['image_alt'] === null ? null : (string) $row['image_alt'],
            ] : null,
            'price'              => $row['price'] === null ? null : (float) $row['price'],
            'compare_price'      => $row['compare_price'] === null ? null : (float) $row['compare_price'],
            'discount_percent'   => $row['discount_percent'] === null ? null : (int) $row['discount_percent'],
        ];
    }
}
