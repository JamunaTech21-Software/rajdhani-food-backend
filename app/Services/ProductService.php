<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\RichText;
use Rajdhani\Helpers\SlugHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Database;

/**
 * Products, and their three child collections, as one aggregate (doc §8.5,
 * §9.9; RTPP-19). "The largest content module" in the plan, and the shape here
 * follows from one fact: `product_pack_sizes`, `product_highlights` and
 * `product_images` are never referenced by id from outside this module (see
 * `ProductRepository`'s class doc), so a product's children can be replaced
 * wholesale on every save without breaking anything else.
 *
 * That is what makes the DoD's hardest line possible: **a product round-trips
 * through create → update → publish with all children intact, written in one
 * transaction.** `create()` and `update()` both accept the child arrays inline
 * and replace the whole set inside one transaction — not a diff against what
 * was there before, a replacement, which is the simplest operation that is
 * still correct and matches how a tabbed form actually submits (the tab's
 * current contents, not an edit script).
 *
 * The three child collections are *also* independently addressable —
 * `POST /admin/products/:id/pack-sizes` and siblings — for the dashboard's
 * "add one row" interactions, which go through the same repository methods a
 * whole-product save uses.
 */
final class ProductService
{
    use HandlesTransactions;
    use ValidatesInput;

    private readonly PDO $db;

    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        ?PDO $connection = null,
    ) {
        // A default of `Database::connection()` is not legal here — PHP only
        // allows a `new` expression as a promoted property's default, not an
        // arbitrary static call — so the fallback is resolved in the body
        // instead. A test supplies its own connection so every write in this
        // service lands inside the same transaction the test intends to
        // roll back.
        $this->db = $connection ?? Database::connection();
    }

    // ─── product root ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $search = is_string($query['search'] ?? null) ? trim((string) $query['search']) : null;
        $status = $this->filterStatus($query);
        $categoryId = is_string($query['category_id'] ?? null) ? $query['category_id'] : null;

        $rows = $this->products->paginate($pagination->limit, $pagination->offset(), $search, $status, $categoryId);
        $total = $this->products->count($search, $status, $categoryId);

        return [
            'data' => array_map($this->summaryView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->fullView($this->requireProduct($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, existingId: null);

        $id = $this->transaction(function () use ($fields, $input): string {
            $id = $this->insertHandlingRace($fields);
            $this->replaceChildrenIfProvided($id, $input);

            return $id;
        });

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireProduct($id);

        $fields = $this->coreFields($input, existingId: $id, partial: true);

        if ($fields === [] && !$this->hasAnyChildKey($input)) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->transaction(function () use ($id, $fields, $input): void {
            if ($fields !== []) {
                $this->updateHandlingRace($id, $fields);
            }

            $this->replaceChildrenIfProvided($id, $input);
        });

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        // Idempotent, soft delete only — same reasoning as categories. The
        // three child tables cascade on a real DELETE of the product row
        // (doc §8.5), which is exactly why this never issues one: cascading
        // away pack sizes and images because someone unpublished a product
        // for a day is not what "delete" should mean here.
        $this->products->softDelete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $this->validateIdList($input, 'ids', 'product');
        $missing = $this->products->reorder($ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not match an existing product', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such product: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    // ─── public catalogue (doc §9.4, §10.2; RTPP-20) ───────────────────────
    //
    // Read-only, PUBLISHED-only, no admin fields. Filter state is expressed
    // entirely in `$query` so the customer site can round-trip it through the
    // URL (that round-trip *is* the DoD, not just a nicety) — `category` and
    // `sort` are validated against a fixed vocabulary rather than passed
    // through, both because free text has no business reaching an ORDER BY
    // and because an unrecognised value should be ignored, not 400 the whole
    // page: a stale or hand-edited query string degrades to the default
    // rather than breaking the listing.

    private const SORTS = ['newest', 'name', 'price_asc', 'price_desc', 'rating'];
    private const RELATED_DEFAULT_LIMIT = 8;
    private const RELATED_MAX_LIMIT = 20;

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function publicPaginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $category = $this->publicStringParam($query, 'category');
        $search = $this->publicStringParam($query, 'search');
        $featured = $this->publicBoolParam($query, 'featured');
        $sort = $this->publicSortParam($query);

        $rows = $this->products->publicPaginate($pagination->limit, $pagination->offset(), $category, $search, $featured, $sort);
        $total = $this->products->publicCount($category, $search, $featured);

        return [
            'data' => array_map($this->cardView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function publicFindBySlug(string $slug): array
    {
        return $this->publicDetailView($this->requirePublishedProduct($slug));
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function publicRelated(string $slug, array $query): array
    {
        $product = $this->requirePublishedProduct($slug);
        $limit = min(self::RELATED_MAX_LIMIT, max(1, $this->publicIntParam($query, 'limit', self::RELATED_DEFAULT_LIMIT)));

        $rows = $this->products->relatedByCategory((string) $product['category_id'], (string) $product['id'], $limit);

        return array_map($this->cardView(...), $rows);
    }

    /** @return array<string,mixed> */
    private function requirePublishedProduct(string $slug): array
    {
        $product = $this->products->findPublishedBySlug($slug);

        if ($product === null) {
            // Deliberately the same 404 whether the slug never existed, names
            // a DRAFT/ARCHIVED product, or was soft-deleted — the public API
            // must not leak which of those is true (doc §9.4's "only
            // published products are ever returned").
            throw ApiError::notFound('No such product');
        }

        return $product;
    }

    /** @param array<string,mixed> $query */
    private function publicStringParam(array $query, string $field): ?string
    {
        $value = $query[$field] ?? null;
        $trimmed = is_scalar($value) ? trim((string) $value) : '';

        return $trimmed === '' ? null : $trimmed;
    }

    /** @param array<string,mixed> $query */
    private function publicBoolParam(array $query, string $field): bool
    {
        $value = $query[$field] ?? null;

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    /** @param array<string,mixed> $query */
    private function publicIntParam(array $query, string $field, int $default): int
    {
        $value = $query[$field] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Unrecognised or missing sorts degrade to the repository's own default
     * rather than erroring — see the class doc above.
     *
     * @param array<string,mixed> $query
     */
    private function publicSortParam(array $query): string
    {
        $sort = $query['sort'] ?? null;

        return is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'default';
    }

    // ─── pack sizes ─────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listPackSizes(string $productId): array
    {
        $this->requireProduct($productId);

        return array_map($this->packSizeView(...), $this->products->packSizes($productId));
    }

    /** @return array<string,mixed> */
    public function findPackSize(string $productId, string $id): array
    {
        $this->requireProduct($productId);
        $row = $this->products->findPackSize($productId, $id);

        if ($row === null) {
            throw ApiError::notFound('No such pack size');
        }

        return $this->packSizeView($row);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function createPackSize(string $productId, array $input): array
    {
        $this->requireProduct($productId);
        $fields = $this->packSizeFields($input, $productId, excludingId: null);

        $id = $this->transaction(function () use ($productId, $fields): string {
            $id = $this->insertPackSizeHandlingRace($productId, $fields);

            if ($fields['is_default'] === 1) {
                $this->products->clearOtherDefaultPackSizes($productId, $id);
            }

            return $id;
        });

        return $this->findPackSize($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updatePackSize(string $productId, string $id, array $input): array
    {
        $this->requireProduct($productId);

        if ($this->products->findPackSize($productId, $id) === null) {
            throw ApiError::notFound('No such pack size');
        }

        $fields = $this->packSizeFields($input, $productId, excludingId: $id, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->transaction(function () use ($productId, $id, $fields): void {
            $this->updatePackSizeHandlingRace($id, $fields);

            if (($fields['is_default'] ?? null) === 1) {
                $this->products->clearOtherDefaultPackSizes($productId, $id);
            }
        });

        return $this->findPackSize($productId, $id);
    }

    public function deletePackSize(string $productId, string $id): void
    {
        $this->requireProduct($productId);
        $this->products->deletePackSize($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorderPackSizes(string $productId, array $input): array
    {
        return $this->reorderChildren(
            $productId,
            $input,
            existingIds: array_column($this->products->packSizes($productId), 'id'),
            label: 'pack size',
            apply: function (string $id, int $position): void {
                $this->products->updatePackSize($id, ['sort_order' => $position]);
            },
        );
    }

    // ─── highlights ─────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listHighlights(string $productId): array
    {
        $this->requireProduct($productId);

        return array_map($this->highlightView(...), $this->products->highlights($productId));
    }

    /** @return array<string,mixed> */
    public function findHighlight(string $productId, string $id): array
    {
        $this->requireProduct($productId);
        $row = $this->products->findHighlight($productId, $id);

        if ($row === null) {
            throw ApiError::notFound('No such highlight');
        }

        return $this->highlightView($row);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function createHighlight(string $productId, array $input): array
    {
        $this->requireProduct($productId);
        $fields = $this->highlightFields($input);
        $id = $this->products->createHighlight($productId, $fields);

        return $this->findHighlight($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updateHighlight(string $productId, string $id, array $input): array
    {
        $this->requireProduct($productId);

        if ($this->products->findHighlight($productId, $id) === null) {
            throw ApiError::notFound('No such highlight');
        }

        $fields = $this->highlightFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->products->updateHighlight($id, $fields);

        return $this->findHighlight($productId, $id);
    }

    public function deleteHighlight(string $productId, string $id): void
    {
        $this->requireProduct($productId);
        $this->products->deleteHighlight($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorderHighlights(string $productId, array $input): array
    {
        return $this->reorderChildren(
            $productId,
            $input,
            existingIds: array_column($this->products->highlights($productId), 'id'),
            label: 'highlight',
            apply: function (string $id, int $position): void {
                $this->products->updateHighlight($id, ['sort_order' => $position]);
            },
        );
    }

    // ─── images ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listImages(string $productId): array
    {
        $this->requireProduct($productId);

        return array_map($this->imageViewFromRow(...), $this->products->images($productId));
    }

    /** @return array<string,mixed> */
    public function findImage(string $productId, string $id): array
    {
        $this->requireProduct($productId);
        $row = $this->products->findImage($productId, $id);

        if ($row === null) {
            throw ApiError::notFound('No such product image');
        }

        return $this->imageViewFromRow($row);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function createImage(string $productId, array $input): array
    {
        $this->requireProduct($productId);
        $fields = $this->imageFields($input);

        $id = $this->transaction(function () use ($productId, $fields): string {
            $id = $this->products->createImage($productId, $fields);

            if ($fields['is_primary'] === 1) {
                $this->products->clearOtherPrimaryImages($productId, $id);
            }

            return $id;
        });

        return $this->findImage($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updateImage(string $productId, string $id, array $input): array
    {
        $this->requireProduct($productId);

        if ($this->products->findImage($productId, $id) === null) {
            throw ApiError::notFound('No such product image');
        }

        $fields = $this->imageFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->transaction(function () use ($productId, $id, $fields): void {
            $this->products->updateImage($id, $fields);

            if (($fields['is_primary'] ?? null) === 1) {
                $this->products->clearOtherPrimaryImages($productId, $id);
            }
        });

        return $this->findImage($productId, $id);
    }

    public function deleteImage(string $productId, string $id): void
    {
        $this->requireProduct($productId);
        $this->products->deleteImage($productId, $id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorderImages(string $productId, array $input): array
    {
        return $this->reorderChildren(
            $productId,
            $input,
            existingIds: array_column($this->products->images($productId), 'id'),
            label: 'image',
            apply: function (string $id, int $position): void {
                $this->products->updateImage($id, ['sort_order' => $position]);
            },
        );
    }

    // ─── core field validation ──────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, ?string $existingId, bool $partial = false): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('name')) {
            $fields['name'] = $this->requiredText($input, 'name', 255);
        }

        if ($has('category_id')) {
            $categoryId = $this->requiredUlid($input, 'category_id');

            if (!$this->products->categoryExists($categoryId)) {
                throw $this->fieldError('category_id', 'No such category');
            }

            $fields['category_id'] = $categoryId;
        }

        // A slug is resolved when either is true: this is a create (which
        // always needs one, explicit or derived from the required $name), or
        // the caller explicitly sent one. It is deliberately NOT resolved just
        // because $name changed on an update — re-deriving on every rename
        // would silently move a published product's URL, breaking whatever
        // already links to it.
        $isCreate = $existingId === null;
        $slugExplicitlySent = array_key_exists('slug', $input);

        if ($isCreate || $slugExplicitlySent) {
            $fields['slug'] = $this->resolveSlug($input, $fields['name'] ?? null, $existingId);
        }

        foreach (['short_description', 'tagline'] as $field) {
            if ($has($field)) {
                $fields[$field] = $this->optionalText($input, $field, $field === 'tagline' ? 255 : 65535);
            }
        }

        foreach (['description', 'ingredients', 'nutrition_info', 'brewing_guide', 'packaging_info'] as $field) {
            if ($has($field)) {
                // Sanitised before it ever reaches a bind parameter — see
                // RichText's class doc for why this cannot happen later.
                $fields[$field] = RichText::sanitize($this->rawTabInput($input, $field));
            }
        }

        if ($has('key_features')) {
            $fields['key_features'] = $this->keyFeatures($input);
        }

        if ($has('badge_text')) {
            $fields['badge_text'] = $this->optionalText($input, 'badge_text', 64);
        }

        if ($has('badge_color')) {
            $fields['badge_color'] = $this->optionalHexColour($input, 'badge_color');
        }

        if ($has('status')) {
            $fields['status'] = $this->optionalEnum($input, 'status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'], 'DRAFT');
        }

        if ($has('is_featured')) {
            $fields['is_featured'] = $this->optionalBool($input, 'is_featured', false);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        foreach (['meta_title' => 255, 'meta_description' => 65535] as $field => $max) {
            if ($has($field)) {
                $fields[$field] = $this->optionalText($input, $field, $max);
            }
        }

        return $fields;
    }

    /**
     * Slug resolution mirrors `CategoryService` exactly, including *why* an
     * explicit value and a derived one behave differently — see that class's
     * doc. Kept as separate code rather than shared, because the two differ
     * in one real way: a product update may change `name` without touching
     * `slug`, so re-deriving on every save would silently move a published
     * product's URL. Only an explicit `slug` in the input changes it.
     *
     * @param array<string,mixed> $input
     */
    private function resolveSlug(array $input, ?string $name, ?string $existingId): string
    {
        $explicit = isset($input['slug']) && is_scalar($input['slug']) && trim((string) $input['slug']) !== '';

        if ($explicit) {
            $slug = SlugHelper::make((string) $input['slug']);

            if ($this->products->slugExists($slug, excludingId: $existingId)) {
                throw ApiError::conflict("The slug '{$slug}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            return $slug;
        }

        // Reached two ways: a create with no slug given (where $name is
        // required and always present), or an update that explicitly sent an
        // empty slug — `{"slug": ""}` — which is not a valid explicit value
        // but did ask for *something* to happen. Without a $name to derive
        // from in that second case, there is nothing correct to do; refuse
        // rather than deriving from a name that was never sent.
        if ($name === null) {
            throw $this->fieldError('slug', 'Cannot be empty');
        }

        // Auto-suffix on collision — the same reasoning as CategoryService::create():
        // nobody chose a specific value here to be surprised about.
        return SlugHelper::unique(
            SlugHelper::make($name),
            fn (string $candidate): bool => $this->products->slugExists($candidate),
        );
    }

    /** @param array<string,mixed> $input */
    private function rawTabInput(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->fieldError($field, 'Expected a string');
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function keyFeatures(array $input): ?string
    {
        $value = $input['key_features'] ?? null;

        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value)) {
            throw $this->fieldError('key_features', 'Expected a list of strings');
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_scalar($item) || trim((string) $item) === '') {
                throw $this->fieldError('key_features', 'Every entry must be a non-empty string');
            }

            $strings[] = trim((string) $item);
        }

        return json_encode($strings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    // ─── child field validation ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function packSizeFields(array $input, string $productId, ?string $excludingId, bool $partial = false): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('label')) {
            $fields['label'] = $this->requiredText($input, 'label', 64);
        }

        $priceGiven = $has('price');
        $price = null;

        if ($priceGiven) {
            $price = $this->requiredDecimal($input, 'price', min: 0.0);
            $fields['price'] = number_format($price, 2, '.', '');
        }

        $compareGiven = array_key_exists('compare_price', $input);
        $compare = null;

        if ($compareGiven) {
            $compare = $input['compare_price'] === null ? null : $this->requiredDecimal($input, 'compare_price', min: 0.0);
            $fields['compare_price'] = $compare === null ? null : number_format($compare, 2, '.', '');
        }

        // Discount is derived, never accepted from the client — the two prices
        // are the source of truth, and a client-supplied percentage could
        // disagree with them, which is a support ticket waiting to happen.
        //
        // A partial update touching only one of the two prices still has to
        // derive the discount from the *pair* that will actually be stored —
        // read the sibling value back from the row being edited rather than
        // silently treating the untouched side as absent.
        if ($priceGiven || $compareGiven) {
            $existing = $excludingId === null ? null : $this->products->findPackSize($productId, $excludingId);

            $effectivePrice = $priceGiven
                ? $price
                : ($existing !== null ? (float) $existing['price'] : null);

            $effectiveCompare = $compareGiven
                ? $compare
                : ($existing !== null && $existing['compare_price'] !== null ? (float) $existing['compare_price'] : null);

            $fields['discount_percent'] = $this->deriveDiscount($effectivePrice, $effectiveCompare);
        }

        if (array_key_exists('sku', $input) || !$partial) {
            $sku = $this->requiredText($input, 'sku', 64);

            if ($this->products->skuExists($sku, excludingId: $excludingId)) {
                throw ApiError::conflict("The SKU '{$sku}' is already in use", [
                    ['field' => 'sku', 'message' => 'This SKU is already in use'],
                ]);
            }

            $fields['sku'] = $sku;
        }

        if ($has('price_includes_vat')) {
            $fields['price_includes_vat'] = $this->optionalBool($input, 'price_includes_vat', true) ? 1 : 0;
        }

        if ($has('is_default')) {
            $fields['is_default'] = $this->optionalBool($input, 'is_default', false) ? 1 : 0;
        }

        if ($has('is_available')) {
            $fields['is_available'] = $this->optionalBool($input, 'is_available', true) ? 1 : 0;
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function highlightFields(array $input, bool $partial = false): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('title')) {
            $fields['title'] = $this->requiredText($input, 'title', 255);
        }

        if ($has('subtitle')) {
            $fields['subtitle'] = $this->optionalText($input, 'subtitle', 255);
        }

        if ($has('icon_name')) {
            $fields['icon_name'] = $this->optionalText($input, 'icon_name', 64);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function imageFields(array $input, bool $partial = false): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('media_id')) {
            $mediaId = $this->requiredUlid($input, 'media_id');

            if (!$this->products->mediaAssetExists($mediaId)) {
                throw $this->fieldError('media_id', 'No such media asset');
            }

            $fields['media_id'] = $mediaId;
        }

        if ($has('is_primary')) {
            $fields['is_primary'] = $this->optionalBool($input, 'is_primary', false) ? 1 : 0;
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        return $fields;
    }

    // ─── nested children on the product itself ──────────────────────────────

    /**
     * Only touches a child collection whose key was actually present in the
     * body — sending a product update with no `pack_sizes` key leaves pack
     * sizes untouched, not wiped. Sending `"pack_sizes": []` *is* a request to
     * clear them; that distinction is exactly why this checks
     * `array_key_exists()` rather than truthiness.
     *
     * @param array<string,mixed> $input
     */
    private function replaceChildrenIfProvided(string $productId, array $input): void
    {
        if (array_key_exists('pack_sizes', $input)) {
            $this->replacePackSizes($productId, $input['pack_sizes']);
        }

        if (array_key_exists('highlights', $input)) {
            $this->replaceHighlights($productId, $input['highlights']);
        }

        if (array_key_exists('images', $input)) {
            $this->replaceImages($productId, $input['images']);
        }
    }

    /** @param mixed $rows */
    private function replacePackSizes(string $productId, mixed $rows): void
    {
        $items = $this->asChildArray($rows, 'pack_sizes');
        $this->products->replacePackSizes($productId);
        $order = 0;
        $defaultId = null;

        foreach ($items as $item) {
            $fields = $this->packSizeFields($item, $productId, excludingId: null);
            $fields['sort_order'] = $fields['sort_order'] ?? ++$order;
            $id = $this->insertPackSizeHandlingRace($productId, $fields);

            if (($fields['is_default'] ?? 0) === 1) {
                $defaultId = $id;
            }
        }

        if ($defaultId !== null) {
            $this->products->clearOtherDefaultPackSizes($productId, $defaultId);
        }
    }

    /** @param mixed $rows */
    private function replaceHighlights(string $productId, mixed $rows): void
    {
        $items = $this->asChildArray($rows, 'highlights');
        $this->products->replaceHighlights($productId);
        $order = 0;

        foreach ($items as $item) {
            $fields = $this->highlightFields($item);
            $fields['sort_order'] = $fields['sort_order'] ?? ++$order;
            $this->products->createHighlight($productId, $fields);
        }
    }

    /** @param mixed $rows */
    private function replaceImages(string $productId, mixed $rows): void
    {
        $items = $this->asChildArray($rows, 'images');
        $this->products->replaceImages($productId);
        $order = 0;
        $primaryId = null;

        foreach ($items as $item) {
            $fields = $this->imageFields($item);
            $fields['sort_order'] = $fields['sort_order'] ?? ++$order;
            $id = $this->products->createImage($productId, $fields);

            if (($fields['is_primary'] ?? 0) === 1) {
                $primaryId = $id;
            }
        }

        if ($primaryId !== null) {
            $this->products->clearOtherPrimaryImages($productId, $primaryId);
        }
    }

    /** @return list<array<string,mixed>> */
    private function asChildArray(mixed $rows, string $field): array
    {
        if (!is_array($rows)) {
            throw $this->fieldError($field, 'Expected a list');
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw $this->fieldError($field, 'Every entry must be an object');
            }
        }

        /** @var list<array<string,mixed>> $rows */
        return $rows;
    }

    /** @param array<string,mixed> $input */
    private function hasAnyChildKey(array $input): bool
    {
        return array_key_exists('pack_sizes', $input)
            || array_key_exists('highlights', $input)
            || array_key_exists('images', $input);
    }

    // ─── shared reorder logic for the three child types ─────────────────────

    /**
     * @param array<string,mixed>        $input
     * @param list<string>                $existingIds ids currently belonging to this product
     * @param callable(string,int): void $apply
     *
     * @return array<string,mixed>
     */
    private function reorderChildren(string $productId, array $input, array $existingIds, string $label, callable $apply): array
    {
        $this->requireProduct($productId);
        $ids = $this->validateIdList($input, 'ids', $label);

        $owned = array_flip($existingIds);
        $missing = array_values(array_filter($ids, static fn (string $id): bool => !isset($owned[$id])));

        $position = 0;

        foreach ($ids as $id) {
            if (isset($owned[$id])) {
                $apply($id, ++$position);
            }
        }

        if ($missing !== []) {
            throw ApiError::validation("Some ids do not belong to this product's {$label} set", array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such {$label}: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return list<string>
     */
    private function validateIdList(array $input, string $field, string $label): array
    {
        $ids = $input[$field] ?? null;

        if (!is_array($ids) || $ids === []) {
            throw $this->fieldError($field, 'This field is required and must be a non-empty list');
        }

        $normalised = [];

        foreach ($ids as $id) {
            if (!is_string($id) || !UlidHelper::isValid($id)) {
                throw $this->fieldError($field, "Every entry must be a valid {$label} id");
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->fieldError($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    // ─── race handling ──────────────────────────────────────────────────────
    // `transaction()` itself now lives in HandlesTransactions — extracted
    // once MediaService (RTPP-22) needed the identical SAVEPOINT-based
    // wrapper this class already had.

    /** @param array<string,scalar|null> $fields */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->products->create($fields);
        } catch (PDOException $e) {
            throw $this->translateProductRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->products->update($id, $fields);
        } catch (PDOException $e) {
            throw $this->translateProductRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function insertPackSizeHandlingRace(string $productId, array $fields): string
    {
        try {
            return $this->products->createPackSize($productId, $fields);
        } catch (PDOException $e) {
            throw $this->translatePackSizeRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updatePackSizeHandlingRace(string $id, array $fields): void
    {
        try {
            $this->products->updatePackSize($id, $fields);
        } catch (PDOException $e) {
            throw $this->translatePackSizeRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function translateProductRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_products_slug')) {
            return ApiError::conflict("The slug '{$fields['slug']}' is already in use", [
                ['field' => 'slug', 'message' => 'This slug is already in use'],
            ]);
        }

        throw $e;
    }

    /** @param array<string,scalar|null> $fields */
    private function translatePackSizeRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_pack_sizes_sku')) {
            return ApiError::conflict("The SKU '{$fields['sku']}' is already in use", [
                ['field' => 'sku', 'message' => 'This SKU is already in use'],
            ]);
        }

        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_pack_sizes_product_label')) {
            return ApiError::conflict("The label '{$fields['label']}' is already used on this product", [
                ['field' => 'label', 'message' => 'This product already has a pack size with this label'],
            ]);
        }

        throw $e;
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireProduct(string $id): array
    {
        $product = $this->products->find($id);

        if ($product === null) {
            throw ApiError::notFound('No such product');
        }

        return $product;
    }

    /** @param array<string,mixed> $input */
    private function requiredDecimal(array $input, string $field, float $min): float
    {
        $value = $input[$field] ?? null;

        if (!is_numeric($value)) {
            throw $this->fieldError($field, 'Expected a number');
        }

        $number = (float) $value;

        if ($number < $min) {
            throw $this->fieldError($field, "Must be at least {$min}");
        }

        return $number;
    }

    private function deriveDiscount(?float $price, ?float $comparePrice): ?int
    {
        if ($price === null || $comparePrice === null || $comparePrice <= $price) {
            return null;
        }

        return (int) round((($comparePrice - $price) / $comparePrice) * 100);
    }

    /** @param array<string,mixed> $query */
    private function filterStatus(array $query): ?string
    {
        $status = $query['status'] ?? null;

        if ($status === null) {
            return null;
        }

        if (!is_string($status) || !in_array($status, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
            throw $this->fieldError('status', 'Must be one of: DRAFT, PUBLISHED, ARCHIVED');
        }

        return $status;
    }

    private function fieldError(string $field, string $message): ApiError
    {
        return ApiError::validation('Some fields need attention', [['field' => $field, 'message' => $message]]);
    }

    // ─── output shaping ──────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function summaryView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'category_id' => (string) $row['category_id'],
            'name'        => (string) $row['name'],
            'slug'        => (string) $row['slug'],
            'status'      => (string) $row['status'],
            'is_featured' => (int) $row['is_featured'] === 1,
            'sort_order'  => (int) $row['sort_order'],
            'updated_at'  => DateHelper::iso((string) $row['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function fullView(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'id'                => $id,
            'category_id'       => (string) $row['category_id'],
            'name'              => (string) $row['name'],
            'slug'              => (string) $row['slug'],
            'short_description' => $row['short_description'] === null ? null : (string) $row['short_description'],
            'tagline'           => $row['tagline'] === null ? null : (string) $row['tagline'],
            'description'       => $row['description'] === null ? null : (string) $row['description'],
            'ingredients'       => $row['ingredients'] === null ? null : (string) $row['ingredients'],
            'nutrition_info'    => $row['nutrition_info'] === null ? null : (string) $row['nutrition_info'],
            'brewing_guide'     => $row['brewing_guide'] === null ? null : (string) $row['brewing_guide'],
            'packaging_info'    => $row['packaging_info'] === null ? null : (string) $row['packaging_info'],
            'key_features'      => $row['key_features'] === null
                ? null
                : json_decode((string) $row['key_features'], true, 512, JSON_THROW_ON_ERROR),
            'badge_text'        => $row['badge_text'] === null ? null : (string) $row['badge_text'],
            'badge_color'       => $row['badge_color'] === null ? null : (string) $row['badge_color'],
            'status'            => (string) $row['status'],
            'is_featured'       => (int) $row['is_featured'] === 1,
            'sort_order'        => (int) $row['sort_order'],
            'view_count'        => (int) $row['view_count'],
            'rating_average'    => (float) $row['rating_average'],
            'rating_count'      => (int) $row['rating_count'],
            'meta_title'        => $row['meta_title'] === null ? null : (string) $row['meta_title'],
            'meta_description'  => $row['meta_description'] === null ? null : (string) $row['meta_description'],
            'created_at'        => DateHelper::iso((string) $row['created_at']),
            'updated_at'        => DateHelper::iso((string) $row['updated_at']),
            'pack_sizes'        => array_map($this->packSizeView(...), $this->products->packSizes($id)),
            'highlights'        => array_map($this->highlightView(...), $this->products->highlights($id)),
            'images'            => array_map($this->imageViewFromRow(...), $this->products->images($id)),
        ];
    }

    /**
     * The product-card shape shared by `GET /public/products` and its
     * `related` sibling (doc §10.2's "responsive grid of product cards" is
     * one grid component doing both jobs). `price`/`compare_price`/
     * `discount_percent` come from whichever pack size `ProductRepository`
     * picked as the card's representative one — the default pack size if the
     * admin marked one, else the cheapest — never a client-chosen size.
     *
     * @param array<string,mixed> $row as `ProductRepository::publicPaginate()`/`relatedByCategory()` return
     *
     * @return array<string,mixed>
     */
    private function cardView(array $row): array
    {
        return [
            'id'                => (string) $row['id'],
            'name'              => (string) $row['name'],
            'slug'              => (string) $row['slug'],
            'short_description' => $row['short_description'] === null ? null : (string) $row['short_description'],
            'tagline'           => $row['tagline'] === null ? null : (string) $row['tagline'],
            'badge_text'        => $row['badge_text'] === null ? null : (string) $row['badge_text'],
            'badge_color'       => $row['badge_color'] === null ? null : (string) $row['badge_color'],
            'is_featured'       => (int) $row['is_featured'] === 1,
            'rating_average'    => (float) $row['rating_average'],
            'rating_count'      => (int) $row['rating_count'],
            'price'             => $row['price'] === null ? null : (float) $row['price'],
            'compare_price'     => $row['compare_price'] === null ? null : (float) $row['compare_price'],
            'discount_percent'  => $row['discount_percent'] === null ? null : (int) $row['discount_percent'],
            'image'             => $row['image_url'] === null ? null : [
                'url'    => (string) $row['image_url'],
                'alt'    => $row['image_alt'] === null ? null : (string) $row['image_alt'],
                'width'  => $row['image_width'] === null ? null : (int) $row['image_width'],
                'height' => $row['image_height'] === null ? null : (int) $row['image_height'],
            ],
            'category'          => [
                'id'   => (string) $row['category_id'],
                'name' => (string) $row['category_name'],
                'slug' => (string) $row['category_slug'],
            ],
        ];
    }

    /**
     * The `GET /public/products/:slug` shape (doc §10.2's Detail section):
     * tab content, pack sizes, highlights, images, the rating summary, SEO
     * meta and a breadcrumb-ready category — everything the page needs in one
     * payload, and nothing an admin screen needs that a customer should not
     * see (`status`, `view_count`, timestamps).
     *
     * @param array<string,mixed> $row as `ProductRepository::findPublishedBySlug()` returns
     *
     * @return array<string,mixed>
     */
    private function publicDetailView(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'id'                => $id,
            'name'              => (string) $row['name'],
            'slug'              => (string) $row['slug'],
            'short_description' => $row['short_description'] === null ? null : (string) $row['short_description'],
            'tagline'           => $row['tagline'] === null ? null : (string) $row['tagline'],
            'description'       => $row['description'] === null ? null : (string) $row['description'],
            'ingredients'       => $row['ingredients'] === null ? null : (string) $row['ingredients'],
            'nutrition_info'    => $row['nutrition_info'] === null ? null : (string) $row['nutrition_info'],
            'brewing_guide'     => $row['brewing_guide'] === null ? null : (string) $row['brewing_guide'],
            'packaging_info'    => $row['packaging_info'] === null ? null : (string) $row['packaging_info'],
            'key_features'      => $row['key_features'] === null
                ? null
                : json_decode((string) $row['key_features'], true, 512, JSON_THROW_ON_ERROR),
            'badge_text'        => $row['badge_text'] === null ? null : (string) $row['badge_text'],
            'badge_color'       => $row['badge_color'] === null ? null : (string) $row['badge_color'],
            'rating_average'    => (float) $row['rating_average'],
            'rating_count'      => (int) $row['rating_count'],
            'meta_title'        => $row['meta_title'] === null ? null : (string) $row['meta_title'],
            'meta_description'  => $row['meta_description'] === null ? null : (string) $row['meta_description'],
            'category'          => [
                'id'   => (string) $row['category_id'],
                'name' => (string) $row['category_name'],
                'slug' => (string) $row['category_slug'],
            ],
            'pack_sizes'        => array_map($this->packSizeView(...), $this->products->packSizes($id)),
            'highlights'        => array_map($this->highlightView(...), $this->products->highlights($id)),
            'images'            => array_map($this->imageViewFromRow(...), $this->products->images($id)),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function packSizeView(array $row): array
    {
        return [
            'id'                 => (string) $row['id'],
            'label'              => (string) $row['label'],
            'sku'                => (string) $row['sku'],
            'price'              => (float) $row['price'],
            'compare_price'      => $row['compare_price'] === null ? null : (float) $row['compare_price'],
            'discount_percent'   => $row['discount_percent'] === null ? null : (int) $row['discount_percent'],
            'price_includes_vat' => (int) $row['price_includes_vat'] === 1,
            'is_default'         => (int) $row['is_default'] === 1,
            'is_available'       => (int) $row['is_available'] === 1,
            'sort_order'         => (int) $row['sort_order'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function highlightView(array $row): array
    {
        return [
            'id'         => (string) $row['id'],
            'title'      => (string) $row['title'],
            'subtitle'   => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'icon_name'  => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    /**
     * @param array<string,mixed> $row joined with media_assets, as ProductRepository::images() returns
     *
     * @return array<string,mixed>
     */
    private function imageViewFromRow(array $row): array
    {
        return [
            'id'         => (string) $row['id'],
            'media_id'   => (string) $row['media_id'],
            'url'        => isset($row['media_url']) ? (string) $row['media_url'] : $this->lookupMediaUrl((string) $row['media_id']),
            'alt'        => isset($row['media_alt']) ? (string) $row['media_alt'] : null,
            'width'      => isset($row['media_width']) ? (int) $row['media_width'] : null,
            'height'     => isset($row['media_height']) ? (int) $row['media_height'] : null,
            'is_primary' => (int) $row['is_primary'] === 1,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    /**
     * `findImage()` (a single row, no join) reaches this; `images()` (the
     * list, joined with `media_assets`) never does. Kept as a fallback rather
     * than joining the single-row query too, since the single-row path is
     * only ever used right after a write this class just validated the
     * `media_id` for.
     */
    private function lookupMediaUrl(string $mediaId): string
    {
        $statement = Database::connection()->prepare('SELECT secure_url FROM media_assets WHERE id = :id');
        $statement->execute([':id' => $mediaId]);
        $url = $statement->fetchColumn();

        return is_string($url) ? $url : '';
    }
}
