<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\SlugHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\GalleryCategoryRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Gallery categories — the tab bar (doc §8.7, §9.5, §9.9; RTPP-25).
 *
 * Slug uniqueness follows `CategoryService`'s two-layer pattern exactly: a
 * pre-check here for a clean `409`, `uq_gallery_categories_slug` for the race
 * a pre-check cannot catch.
 */
final class GalleryCategoryService
{
    use ValidatesInput;

    public function __construct(
        private readonly GalleryCategoryRepository $categories = new GalleryCategoryRepository(),
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
        $search = is_string($query['search'] ?? null) ? trim($query['search']) : null;

        $rows = $this->categories->paginate($pagination->limit, $pagination->offset(), $search);
        $total = $this->categories->count($search);

        return [
            'data' => array_map($this->view(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireCategory($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $name = $this->requiredText($input, 'name', 255);
        $slug = $this->resolveSlug($input, $name, excludingId: null);

        $fields = [
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $this->optionalText($input, 'description', 65535),
            'icon_name'       => $this->optionalText($input, 'icon_name', 64),
            'cover_image_id'  => $this->optionalMediaRef($input, 'cover_image_id'),
            'sort_order'      => $this->optionalInt($input, 'sort_order', 0),
            'is_active'       => $this->optionalBool($input, 'is_active', true),
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
        $this->requireCategory($id);
        $fields = [];

        if (array_key_exists('name', $input)) {
            $fields['name'] = $this->requiredText($input, 'name', 255);
        }

        if (array_key_exists('slug', $input)) {
            $fields['slug'] = $this->resolveSlug($input, null, excludingId: $id);
        }

        foreach (['description' => 65535, 'icon_name' => 64] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $this->optionalText($input, $field, $max);
            }
        }

        if (array_key_exists('cover_image_id', $input)) {
            $fields['cover_image_id'] = $this->optionalMediaRef($input, 'cover_image_id');
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

    /**
     * How many images would be removed by cascade if this category were
     * deleted right now — the admin screen calls this to warn before the
     * confirmation, not after.
     */
    public function imageCount(string $id): int
    {
        $this->requireCategory($id);

        return $this->categories->imageCount($id);
    }

    /**
     * A genuine, cascading delete — see the repository class doc. Idempotent
     * on a missing id, like every other delete in this codebase, but *not*
     * idempotent in its blast radius: calling it once on a category with
     * images removes them permanently, no restore path.
     */
    public function delete(string $id): void
    {
        $this->categories->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $this->validateIdList($input, 'ids');
        $missing = $this->categories->reorder($ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not match an existing gallery category', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such gallery category: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    /**
     * `GET /public/gallery/categories` (doc §9.5).
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return array_map(function (array $row): array {
            $hasCover = is_string($row['cover_image_url']);

            return [
                'id'           => (string) $row['id'],
                'name'         => (string) $row['name'],
                'slug'         => (string) $row['slug'],
                'description'  => $row['description'] === null ? null : (string) $row['description'],
                'icon_name'    => $row['icon_name'] === null ? null : (string) $row['icon_name'],
                'cover_image'  => $hasCover ? [
                    'url' => (string) $row['cover_image_url'],
                    'alt' => $row['cover_image_alt'] === null ? null : (string) $row['cover_image_alt'],
                ] : null,
                'image_count'  => (int) $row['image_count'],
            ];
        }, $this->categories->publicList());
    }

    /**
     * Shared by `create()` and `update()`: an explicit slug gets exactly that
     * slug or a clear rejection, a name-derived one gets a "-2" suffix on
     * collision — the same split `CategoryService` makes, for the same
     * reason (see its class doc).
     *
     * @param array<string,mixed> $input
     */
    private function resolveSlug(array $input, ?string $fallbackName, ?string $excludingId): string
    {
        $explicitSlug = isset($input['slug']) && is_scalar($input['slug']) && trim((string) $input['slug']) !== '';

        if ($explicitSlug) {
            $slug = SlugHelper::make((string) $input['slug']);

            if ($this->categories->slugExists($slug, $excludingId)) {
                throw ApiError::conflict("The slug '{$slug}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            return $slug;
        }

        if ($fallbackName === null) {
            // update() with no slug key at all never reaches here (the
            // array_key_exists('slug', ...) guard at the call site), and
            // create() always has a name; this path exists only so the
            // signature stays total.
            throw $this->invalid('slug', 'This field is required');
        }

        return SlugHelper::unique(
            SlugHelper::make($fallbackName),
            fn (string $candidate): bool => $this->categories->slugExists($candidate),
        );
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->categories->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
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
            if (!is_string($id) || !UlidHelper::isValid($id)) {
                throw $this->invalid($field, 'Every entry must be a valid gallery category id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @param array<string,scalar|null> $fields */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->categories->create($fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->categories->update($id, $fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function translateRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_gallery_categories_slug')) {
            $slug = $fields['slug'] ?? '';

            return ApiError::conflict("The slug '{$slug}' is already in use", [
                ['field' => 'slug', 'message' => 'This slug is already in use'],
            ]);
        }

        throw $e;
    }

    /** @return array<string,mixed> */
    private function requireCategory(string $id): array
    {
        $category = $this->categories->find($id);

        if ($category === null) {
            throw ApiError::notFound('No such gallery category');
        }

        return $category;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'name'            => (string) $row['name'],
            'slug'            => (string) $row['slug'],
            'description'     => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'       => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'cover_image_id'  => $row['cover_image_id'] === null ? null : (string) $row['cover_image_id'],
            'sort_order'      => (int) $row['sort_order'],
            'is_active'       => (int) $row['is_active'] === 1,
        ];
    }
}
