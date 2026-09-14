<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `menu_links` (doc §8.2, §9.8, §10.5; RTPP-32) — the header, footer and
 * legal navigation `GET /public/layout` reads via `NavigationRepository`.
 *
 * `sort_order` is scoped to one `location` — the same reasoning as
 * `FeatureItemRepository`'s scoping by `section`: `header` and `legal` never
 * render as one list, so reordering one location must never touch a row in
 * another.
 *
 * `parent_id` is the one FK in this codebase's admin CRUD scope that a row
 * of the *same* table can hold, and it has no `ON DELETE CASCADE` — deleting
 * a link with children would otherwise surface as a raw FK-constraint
 * `PDOException`. `hasChildren()` exists so the service can turn that into a
 * clean `409 CONFLICT` instead, the same spirit as `MediaRepository`'s
 * reference check before a delete.
 */
final class MenuLinkRepository extends Repository
{
    private const COLUMNS = 'id, location, label, url, parent_id, sort_order, is_active, open_in_new_tab';

    /** @return list<array<string,mixed>> */
    public function list(?string $location): array
    {
        [$where, $parameters] = $this->listFilter($location);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM menu_links {$where} ORDER BY location, sort_order, label",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM menu_links WHERE id = :id', [':id' => $id]);
    }

    public function exists(string $id): bool
    {
        return $this->scalar('SELECT id FROM menu_links WHERE id = :id', [':id' => $id]) !== null;
    }

    public function hasChildren(string $id): bool
    {
        return $this->scalar('SELECT id FROM menu_links WHERE parent_id = :id LIMIT 1', [':id' => $id]) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO menu_links (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE menu_links SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM menu_links WHERE id = :id', [':id' => $id]);
    }

    /**
     * Scoped to one location, same reasoning as `FeatureItemRepository::reorder()`.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist or do not belong to this location
     */
    public function reorder(string $location, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE menu_links SET sort_order = :position WHERE id = :id AND location = :location',
                [':position' => $position, ':id' => $id, ':location' => $location],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $location): array
    {
        if ($location === null) {
            return ['', []];
        }

        return ['WHERE location = :location', [':location' => $location]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
