<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDO;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\GalleryImageRepository;
use Rajdhani\Services\Concerns\HandlesTransactions;
use Rajdhani\Services\Concerns\ValidatesInput;
use Rajdhani\Support\Database;

/**
 * Gallery images (doc §8.7, §9.5, §9.9; RTPP-25).
 *
 * `bulkCreate()` exists for the admin bulk-upload screen named in the ticket:
 * an admin has already uploaded several files through the RTPP-21
 * sign-then-register flow and now attaches all of them to one category in a
 * single call. It validates every entry before writing any of them — a
 * half-registered batch (ids 1-3 landed, id 4 failed on a bad `media_id`)
 * would leave the admin unsure which of their uploads actually made it into
 * the gallery, which is worse than a single rejection naming the bad entry.
 */
final class GalleryImageService
{
    use HandlesTransactions;
    use ValidatesInput;

    private readonly PDO $db;

    public function __construct(
        private readonly GalleryImageRepository $images = new GalleryImageRepository(),
        ?PDO $connection = null,
    ) {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $categoryId = $this->optionalCategoryFilter($query);

        $rows = $this->images->paginate($pagination->limit, $pagination->offset(), $categoryId);
        $total = $this->images->count($categoryId);

        return [
            'data' => array_map($this->view(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireImage($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $id = $this->images->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireImage($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->images->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->images->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $categoryId = $this->requiredCategoryId($input);
        $ids = $this->validateIdList($input, 'ids');

        $missing = $this->images->reorder($categoryId, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this category', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a gallery image in this category: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    /**
     * `GET /public/gallery` (doc §9.5): paginated, optionally filtered to one
     * category by slug (`?category=`) — the tab bar and the "All" view share
     * this one endpoint, the filter being the only difference.
     *
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function publicList(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $categorySlug = is_string($query['category'] ?? null) && $query['category'] !== '' ? $query['category'] : null;

        $rows = $this->images->publicPaginate($pagination->limit, $pagination->offset(), $categorySlug);
        $total = $this->images->publicCount($categorySlug);

        return [
            'data' => array_map($this->publicView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * `POST /admin/gallery/images/bulk` — register several already-uploaded
     * media assets into one category in one call, appended after whatever
     * `sort_order` the category already has.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function bulkCreate(array $input): array
    {
        $categoryId = $this->requiredCategoryId($input);
        $entries = $this->validatedEntries($input, $categoryId);

        $ids = $this->transaction(function () use ($categoryId, $entries): array {
            $nextOrder = $this->images->nextSortOrder($categoryId);
            $created = [];

            foreach ($entries as $entry) {
                $created[] = $this->images->create($entry + ['sort_order' => $nextOrder++]);
            }

            return $created;
        });

        return ['data' => array_map(fn (string $id): array => $this->find($id), $ids)];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return list<array<string,scalar|null>> validated rows, `sort_order` not yet assigned
     */
    private function validatedEntries(array $input, string $categoryId): array
    {
        $images = $input['images'] ?? null;

        if (!is_array($images) || $images === []) {
            throw $this->invalid('images', 'This field is required and must be a non-empty list');
        }

        $entries = [];

        foreach ($images as $index => $entry) {
            if (!is_array($entry)) {
                throw $this->invalid("images[{$index}]", 'Expected an object with media_id');
            }

            $mediaId = $entry['media_id'] ?? null;

            if (!is_string($mediaId) || !UlidHelper::isValid($mediaId)) {
                throw $this->invalid("images[{$index}].media_id", 'This field is required and must be a valid id');
            }

            if (!$this->images->mediaAssetExists($mediaId)) {
                throw $this->invalid("images[{$index}].media_id", 'No such media asset');
            }

            $entries[] = [
                'category_id' => $categoryId,
                'media_id'    => $mediaId,
                'title'       => $this->optionalText($entry, 'title', 255),
                'description' => $this->optionalText($entry, 'description', 512),
                'is_active'   => true,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('category_id')) {
            $fields['category_id'] = $this->requiredCategoryId($input);
        }

        if ($has('media_id')) {
            $fields['media_id'] = $this->requiredMediaId($input);
        }

        if ($has('title')) {
            $fields['title'] = $this->optionalText($input, 'title', 255);
        }

        if ($has('description')) {
            $fields['description'] = $this->optionalText($input, 'description', 512);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('is_active')) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function requiredCategoryId(array $input): string
    {
        $id = $this->requiredUlid($input, 'category_id');

        if (!$this->images->categoryExists($id)) {
            throw $this->invalid('category_id', 'No such gallery category');
        }

        return $id;
    }

    /**
     * `media_id` is `NOT NULL` in the schema — see the class doc.
     *
     * @param array<string,mixed> $input
     */
    private function requiredMediaId(array $input): string
    {
        $id = $this->requiredUlid($input, 'media_id');

        if (!$this->images->mediaAssetExists($id)) {
            throw $this->invalid('media_id', 'No such media asset');
        }

        return $id;
    }

    /** @param array<string,mixed> $query */
    private function optionalCategoryFilter(array $query): ?string
    {
        $value = $query['category_id'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !UlidHelper::isValid($value)) {
            throw $this->invalid('category_id', 'Expected a valid gallery category id');
        }

        return $value;
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
                throw $this->invalid($field, 'Every entry must be a valid gallery image id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireImage(string $id): array
    {
        $image = $this->images->find($id);

        if ($image === null) {
            throw ApiError::notFound('No such gallery image');
        }

        return $image;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'category_id' => (string) $row['category_id'],
            'media_id'    => (string) $row['media_id'],
            'title'       => $row['title'] === null ? null : (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'sort_order'  => (int) $row['sort_order'],
            'is_active'   => (int) $row['is_active'] === 1,
            'created_at'  => DateHelper::iso((string) $row['created_at']),
        ];
    }

    /**
     * @param array<string,mixed> $row as `GalleryImageRepository::publicPaginate()` returns
     *
     * @return array<string,mixed>
     */
    private function publicView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'title'       => $row['title'] === null ? null : (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'category'    => [
                'id'   => (string) $row['category_id'],
                'name' => (string) $row['category_name'],
                'slug' => (string) $row['category_slug'],
            ],
            'image'       => [
                'url'    => (string) $row['image_url'],
                'alt'    => $row['image_alt'] === null ? null : (string) $row['image_alt'],
                'width'  => $row['image_width'] === null ? null : (int) $row['image_width'],
                'height' => $row['image_height'] === null ? null : (int) $row['image_height'],
            ],
        ];
    }
}
