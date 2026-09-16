<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\SlugHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Category CRUD and the public listing (doc §8.5, §9.4, §9.9; RTPP-18).
 *
 * Slugs are globally unique — there is no brand to scope them by (doc §6). Two
 * layers enforce that, on purpose: this service checks first, for a clean
 * `409 CONFLICT` naming the taken slug; `uq_categories_slug` catches the race a
 * pre-check cannot — two admins saving the same new category at once. Neither
 * layer is redundant with the other.
 */
final class CategoryService
{
    use ValidatesInput;

    public function __construct(
        private readonly CategoryRepository $categories = new CategoryRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $search = is_string($query['search'] ?? null) ? trim((string) $query['search']) : null;

        $rows = $this->categories->paginate($pagination->limit, $pagination->offset(), $search);
        $total = $this->categories->count($search);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        $category = $this->categories->find($id);

        if ($category === null) {
            throw ApiError::notFound('No such category');
        }

        return $this->adminView($category);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $name = $this->requiredText($input, 'name', 255);
        $explicitSlug = isset($input['slug']) && is_scalar($input['slug']) && trim((string) $input['slug']) !== '';

        if ($explicitSlug) {
            // An admin who typed a specific slug gets exactly that slug or a
            // clear rejection — never a silent "-2" they did not ask for.
            // That is also what update() does, and the two must agree: the
            // same input should not behave differently depending on whether
            // the category already existed.
            $slug = SlugHelper::make((string) $input['slug']);

            if ($this->categories->slugExists($slug)) {
                throw ApiError::conflict("The slug '{$slug}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }
        } else {
            // No slug given: derive one from the name, and here the "-2"
            // suffix is exactly right — the admin never chose a specific
            // slug to be surprised about, and forcing them to resolve a
            // collision they did not know they had would be worse.
            $slug = SlugHelper::unique(
                SlugHelper::make($name),
                fn (string $candidate): bool => $this->categories->slugExists($candidate),
            );
        }

        // Either branch above is a pre-check, not the guarantee: the UNIQUE
        // key on `slug` is what actually protects against two requests
        // landing in the same instant — see the class doc.
        $fields = [
            'name'             => $name,
            'slug'             => $slug,
            'description'      => $this->optionalText($input, 'description', 65535),
            'icon_name'        => $this->optionalText($input, 'icon_name', 64),
            'image_id'         => $this->optionalImageId($input),
            'sort_order'       => $this->optionalInt($input, 'sort_order', 0),
            'is_active'        => $this->optionalBool($input, 'is_active', true),
            'meta_title'       => $this->optionalText($input, 'meta_title', 255),
            'meta_description' => $this->optionalText($input, 'meta_description', 65535),
        ];

        $id = $this->insertHandlingRace($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $existing = $this->categories->find($id);

        if ($existing === null) {
            throw ApiError::notFound('No such category');
        }

        $fields = [];

        if (array_key_exists('name', $input)) {
            $fields['name'] = $this->requiredText($input, 'name', 255);
        }

        if (array_key_exists('slug', $input)) {
            $candidate = SlugHelper::make((string) $input['slug']);

            if ($this->categories->slugExists($candidate, excludingId: $id)) {
                throw ApiError::conflict("The slug '{$candidate}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            $fields['slug'] = $candidate;
        }

        foreach (['description', 'icon_name', 'meta_title', 'meta_description'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $this->optionalText($input, $field, $field === 'icon_name' ? 64 : 65535);
            }
        }

        if (array_key_exists('image_id', $input)) {
            $fields['image_id'] = $this->optionalImageId($input);
        }

        if (array_key_exists('sort_order', $input)) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if (array_key_exists('is_active', $input)) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->updateHandlingRace($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        // Idempotent on purpose: calling delete twice, or on an id that never
        // existed, is not an error — the end state the caller wants (the
        // category is gone) already holds. Soft delete only; see the
        // repository doc for why a hard DELETE is never issued.
        $this->categories->softDelete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $input['ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            throw ApiError::validation('Send the category ids in display order', [
                ['field' => 'ids', 'message' => 'This field is required and must be a non-empty list'],
            ]);
        }

        $normalised = [];

        foreach ($ids as $id) {
            if (!is_string($id) || !UlidHelper::isValid($id)) {
                throw ApiError::validation('Every id must be a valid category id', [
                    ['field' => 'ids', 'message' => 'Every entry must be a valid category id'],
                ]);
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw ApiError::validation('Category ids must not repeat', [
                ['field' => 'ids', 'message' => 'Each category may appear only once'],
            ]);
        }

        $missing = $this->categories->reorder($normalised);

        if ($missing !== []) {
            // The whole batch already partially applied at this point — the
            // ids that do exist have their new sort_order. That is deliberate:
            // refusing to reorder the valid entries because one id was stale
            // (a category deleted in another tab, say) would be a worse
            // failure mode than doing the possible part and saying so.
            throw ApiError::validation('Some ids do not match an existing category', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such category: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($normalised)];
    }

    /**
     * The public payload for `GET /public/categories` (doc §9.4).
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return array_map(function (array $row): array {
            $hasImage = is_string($row['image_id']) && $row['image_id'] !== '' && is_string($row['image_url']);

            return [
                'id'            => (string) $row['id'],
                'name'          => (string) $row['name'],
                'slug'          => (string) $row['slug'],
                'description'   => $row['description'] === null ? null : (string) $row['description'],
                'icon_name'     => $row['icon_name'] === null ? null : (string) $row['icon_name'],
                'image'         => $hasImage ? [
                    'id'  => (string) $row['image_id'],
                    'url' => (string) $row['image_url'],
                    'alt' => $row['image_alt'] === null ? null : (string) $row['image_alt'],
                ] : null,
                'product_count' => (int) $row['product_count'],
            ];
        }, $this->categories->publicList());
    }

    /**
     * Insert, translating a `uq_categories_slug` race into the same `409` the
     * pre-check gives — the caller should never be able to tell which layer
     * caught it.
     *
     * @param array<string,scalar|null> $fields
     */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->categories->create($fields);
        } catch (\PDOException $e) {
            if ($this->isDuplicateSlug($e)) {
                throw ApiError::conflict("The slug '{$fields['slug']}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            throw $e;
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->categories->update($id, $fields);
        } catch (\PDOException $e) {
            if ($this->isDuplicateSlug($e)) {
                throw ApiError::conflict("The slug '{$fields['slug']}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * SQLSTATE 23000 is any integrity-constraint violation — the FK on
     * `image_id` included. Matching the key name from the driver message is
     * what tells the two apart, so a bad `image_id` is not misreported as a
     * slug conflict.
     */
    private function isDuplicateSlug(\PDOException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_categories_slug');
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'name'             => (string) $row['name'],
            'slug'             => (string) $row['slug'],
            'description'      => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'        => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'image_id'         => $row['image_id'] === null ? null : (string) $row['image_id'],
            'sort_order'       => (int) $row['sort_order'],
            'is_active'        => (int) $row['is_active'] === 1,
            'meta_title'       => $row['meta_title'] === null ? null : (string) $row['meta_title'],
            'meta_description' => $row['meta_description'] === null ? null : (string) $row['meta_description'],
            'created_at'       => DateHelper::iso((string) $row['created_at']),
            'updated_at'       => DateHelper::iso((string) $row['updated_at']),
        ];
    }

    /**
     * `image_id` needed its own name in the old signature; the shared trait
     * calls the general version `optionalUlid()`. Kept as a one-line alias so
     * every call site in this file did not need editing for a rename alone.
     *
     * @param array<string,mixed> $input
     */
    private function optionalImageId(array $input): ?string
    {
        return $this->optionalUlid($input, 'image_id');
    }
}
