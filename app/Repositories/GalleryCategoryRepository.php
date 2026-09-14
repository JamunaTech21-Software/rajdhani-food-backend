<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `gallery_categories` (doc §8.7, §9.5, §9.9; RTPP-25).
 *
 * The slug is globally unique, same reasoning as `CategoryRepository` — no
 * brand to scope it by (doc §6). Unlike every other table this ticket set
 * touches, this one carries no `created_at`/`updated_at` at all (the schema
 * comment calls this out nowhere; it is simply absent), so neither column
 * appears in `COLUMNS`, `create()`, or `update()`.
 *
 * `delete()` is a genuine hard `DELETE`, and deliberately so: `fk_gallery_images_category`
 * is declared `ON DELETE CASCADE` (doc §8.7's migration), which makes
 * deleting a category with images in it correct schema behaviour, not a bug
 * to guard against — the migration already decided that a gallery category
 * and its images share one lifetime. `GalleryCategoryService::delete()`
 * reports how many images went with it so the admin screen can warn before
 * the call, not discover it after.
 */
final class GalleryCategoryRepository extends Repository
{
    private const COLUMNS = 'id, name, slug, description, icon_name, cover_image_id, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $search): array
    {
        [$where, $parameters] = $this->searchFilter($search);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM gallery_categories {$where}
              ORDER BY sort_order, name
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $search): int
    {
        [$where, $parameters] = $this->searchFilter($search);

        return (int) $this->scalar("SELECT COUNT(*) FROM gallery_categories {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM gallery_categories WHERE id = :id', [':id' => $id]);
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /**
     * @param string|null $excludingId when checking during an update, the
     *                                 category's own current slug must not
     *                                 count as a collision with itself
     */
    public function slugExists(string $slug, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM gallery_categories WHERE slug = :slug';
        $parameters = [':slug' => $slug];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO gallery_categories (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE gallery_categories SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    /** How many images this category currently holds — surfaced to the admin before a delete. */
    public function imageCount(string $categoryId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM gallery_images WHERE category_id = :id',
            [':id' => $categoryId],
        );
    }

    /**
     * Cascades to `gallery_images` at the database level — see the class doc.
     *
     * @return int rows affected in `gallery_categories` itself, not counting the cascade
     */
    public function delete(string $id): int
    {
        return $this->run('DELETE FROM gallery_categories WHERE id = :id', [':id' => $id]);
    }

    /**
     * Flat, unscoped, same shape as `CategoryRepository::reorder()` — there is
     * no grouping column here, just one tab bar's worth of categories.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist
     */
    public function reorder(array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE gallery_categories SET sort_order = :position WHERE id = :id',
                [':position' => $position, ':id' => $id],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * `GET /public/gallery/categories` (doc §9.5) — the filter tab bar. Active
     * categories only, each with its cover resolved to a URL and a live count
     * of its active images so an empty category can still render (0, not
     * hidden) while the front end decides how to display that.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return $this->all(
            'SELECT
                 c.id, c.name, c.slug, c.description, c.icon_name,
                 m.secure_url AS cover_image_url, m.alt_text AS cover_image_alt,
                 COUNT(i.id) AS image_count
               FROM gallery_categories c
               LEFT JOIN media_assets m ON m.id = c.cover_image_id
               LEFT JOIN gallery_images i ON i.category_id = c.id AND i.is_active = 1
              WHERE c.is_active = 1
              GROUP BY c.id, c.name, c.slug, c.description, c.icon_name,
                       m.secure_url, m.alt_text, c.sort_order
              ORDER BY c.sort_order, c.name'
        );
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function searchFilter(?string $search): array
    {
        if ($search === null || $search === '') {
            return ['', []];
        }

        return ['WHERE name LIKE :search', [':search' => '%' . $this->escapeLike($search) . '%']];
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
