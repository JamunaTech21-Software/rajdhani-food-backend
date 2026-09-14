<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `categories` (doc §8.5, RTPP-18).
 *
 * The slug is globally unique — there is no brand to scope it by (doc §6, the
 * single-site cut). `products.category_id` has a plain, non-cascading foreign
 * key into this table, which is why nothing here ever issues a hard `DELETE`:
 * a category with products would simply fail the constraint, and a category
 * without them would silently succeed today and start failing the moment one
 * was added — a correctness trap disguised as convenience. Soft delete via
 * `deleted_at` is the only delete path.
 */
final class CategoryRepository extends Repository
{
    private const ADMIN_COLUMNS = 'id, name, slug, description, icon_name, image_id,
                                   sort_order, is_active, meta_title, meta_description,
                                   created_at, updated_at, deleted_at';

    /**
     * Admin list — every non-deleted category, active or not, so the dashboard
     * can show and toggle both. Deleted rows are gone from every read path,
     * admin included; there is no restore endpoint in scope.
     *
     * @return list<array<string,mixed>>
     */
    public function paginate(int $limit, int $offset, ?string $search): array
    {
        $where = 'WHERE deleted_at IS NULL';
        $parameters = [];

        if ($search !== null && $search !== '') {
            $where .= ' AND name LIKE :search';
            $parameters[':search'] = '%' . $this->escapeLike($search) . '%';
        }

        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::ADMIN_COLUMNS . " FROM categories {$where}
              ORDER BY sort_order, name
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $search): int
    {
        $where = 'WHERE deleted_at IS NULL';
        $parameters = [];

        if ($search !== null && $search !== '') {
            $where .= ' AND name LIKE :search';
            $parameters[':search'] = '%' . $this->escapeLike($search) . '%';
        }

        return (int) $this->scalar("SELECT COUNT(*) FROM categories {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::ADMIN_COLUMNS . ' FROM categories WHERE id = :id AND deleted_at IS NULL',
            [':id' => $id],
        );
    }

    /**
     * @param string|null $excludingId when checking during an update, the
     *                                 category's own current slug must not
     *                                 count as a collision with itself
     */
    public function slugExists(string $slug, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM categories WHERE slug = :slug';
        $parameters = [':slug' => $slug];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    /**
     * @param array<string,scalar|null> $fields already validated by the service
     */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO categories (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
        $parameters = [':id' => $id, ':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE categories SET ' . implode(', ', $assignments) . ', updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            $parameters,
        );
    }

    /** Idempotent: deleting an already-deleted (or missing) category affects zero rows, not an error. */
    public function softDelete(string $id): int
    {
        // Two parameters, not one reused: PDO::ATTR_EMULATE_PREPARES is false
        // (config/database.php), and a named placeholder cannot appear twice
        // in a real prepared statement — the driver raises "Invalid parameter
        // number" at execute() rather than binding it to both spots.
        $now = $this->now();

        return $this->run(
            'UPDATE categories SET deleted_at = :deleted_at, updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            [':deleted_at' => $now, ':updated_at' => $now, ':id' => $id],
        );
    }

    /**
     * Apply a new display order in one pass.
     *
     * @param list<string> $orderedIds category ids, in the order they should display
     *
     * @return list<string> ids from the input that do not exist (or are deleted) — the
     *                      caller rejects the request if this is non-empty, rather than
     *                      silently reordering a subset
     */
    public function reorder(array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE categories SET sort_order = :position, updated_at = :now
                  WHERE id = :id AND deleted_at IS NULL',
                [':position' => $position, ':now' => $this->now(), ':id' => $id],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * The public payload: active categories with a live product count and the
     * image resolved to a URL, not just an id.
     *
     * LEFT JOIN, not INNER — a category with zero published products is still a
     * real category (a new one, waiting for its first product) and must still
     * appear, with `product_count = 0`, not vanish from the filter bar.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return $this->all(
            'SELECT
                 c.id, c.name, c.slug, c.description, c.icon_name,
                 m.id AS image_id, m.secure_url AS image_url, m.alt_text AS image_alt,
                 COUNT(p.id) AS product_count
               FROM categories c
               LEFT JOIN media_assets m ON m.id = c.image_id
               LEFT JOIN products p
                      ON p.category_id = c.id
                     AND p.status = \'PUBLISHED\'
                     AND p.deleted_at IS NULL
              WHERE c.is_active = 1 AND c.deleted_at IS NULL
              GROUP BY c.id, c.name, c.slug, c.description, c.icon_name,
                       m.id, m.secure_url, m.alt_text, c.sort_order
              ORDER BY c.sort_order, c.name'
        );
    }

    /** `%`/`_` are LIKE wildcards; a search for "50%" must not become a wildcard match. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** Column names are literals from a fixed allowlist in the service, never user input. */
    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
