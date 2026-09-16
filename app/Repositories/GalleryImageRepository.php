<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `gallery_images` (doc §8.7, §9.5, §9.9; RTPP-25).
 *
 * `sort_order` is scoped to one `category_id` — the same reasoning as
 * `BannerRepository`'s placement scoping and `PageBlockRepository`'s page
 * scoping: an image belongs to exactly one category's grid, and `reorder()`
 * reflects that.
 *
 * No soft delete (no `deleted_at` column) and no cascade dependents on an
 * image's own id, so `delete()` is a genuine hard `DELETE` — the same
 * reasoning as every RTPP-24 table. `media_id` is `NOT NULL` in the schema
 * (an image row cannot exist without a media asset, unlike every other
 * optional media reference in this codebase), so it is a required field
 * throughout the service, not an `optionalMediaRef()`.
 */
final class GalleryImageRepository extends Repository
{
    private const COLUMNS = 'id, category_id, media_id, title, description, sort_order, is_active, created_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $categoryId): array
    {
        [$where, $parameters] = $this->listFilter($categoryId);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM gallery_images {$where}
              ORDER BY category_id, sort_order
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $categoryId): int
    {
        [$where, $parameters] = $this->listFilter($categoryId);

        return (int) $this->scalar("SELECT COUNT(*) FROM gallery_images {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM gallery_images WHERE id = :id', [':id' => $id]);
    }

    public function categoryExists(string $categoryId): bool
    {
        return $this->scalar('SELECT id FROM gallery_categories WHERE id = :id', [':id' => $categoryId]) !== null;
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /** Where a freshly bulk-registered batch should start numbering from, so it lands after whatever is already in the category. */
    public function nextSortOrder(string $categoryId): int
    {
        return 1 + (int) $this->scalar(
            'SELECT COALESCE(MAX(sort_order), 0) FROM gallery_images WHERE category_id = :id',
            [':id' => $categoryId],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'created_at' => $this->now()];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO gallery_images (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function update(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE gallery_images SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM gallery_images WHERE id = :id', [':id' => $id]);
    }

    /**
     * Scoped to one category: an id belonging to a different category affects
     * zero rows and comes back as missing, same as `BannerRepository::reorder()`.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist or do not belong to this category
     */
    public function reorder(string $categoryId, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE gallery_images SET sort_order = :position WHERE id = :id AND category_id = :category_id',
                [':position' => $position, ':id' => $id, ':category_id' => $categoryId],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * `GET /public/gallery` (doc §9.5): active images, optionally filtered to
     * one category by slug, newest-uploaded-category-first is irrelevant here
     * — display order is `sort_order` within each category, and categories
     * interleave by their own `sort_order` so "All" reads as the tab bar's
     * order, not upload order.
     *
     * @return list<array<string,mixed>>
     */
    public function publicPaginate(int $limit, int $offset, ?string $categorySlug): array
    {
        [$where, $parameters] = $this->publicFilter($categorySlug);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            "SELECT
                 i.id, i.title, i.description,
                 c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
                 m.secure_url AS image_url, m.alt_text AS image_alt, m.width AS image_width, m.height AS image_height
               FROM gallery_images i
               INNER JOIN gallery_categories c ON c.id = i.category_id
               INNER JOIN media_assets m ON m.id = i.media_id
              {$where}
              ORDER BY c.sort_order, i.sort_order
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function publicCount(?string $categorySlug): int
    {
        [$where, $parameters] = $this->publicFilter($categorySlug);

        return (int) $this->scalar(
            "SELECT COUNT(*) FROM gallery_images i
               INNER JOIN gallery_categories c ON c.id = i.category_id
              {$where}",
            $parameters,
        );
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function publicFilter(?string $categorySlug): array
    {
        $where = 'WHERE i.is_active = 1 AND c.is_active = 1';
        $parameters = [];

        if ($categorySlug !== null) {
            $where .= ' AND c.slug = :slug';
            $parameters[':slug'] = $categorySlug;
        }

        return [$where, $parameters];
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $categoryId): array
    {
        if ($categoryId === null) {
            return ['', []];
        }

        return ['WHERE category_id = :category_id', [':category_id' => $categoryId]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
