<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `social_links` (doc §8.2, §9.8, §10.5; RTPP-32) — the footer social icons
 * `GET /public/layout` reads via `NavigationRepository`.
 *
 * A flat list, no soft delete and no scoping dimension for `sort_order` —
 * unlike `menu_links`, there is no `location` to partition by, so
 * `reorder()` is the same unscoped shape `CertificationRepository`'s and
 * `GalleryCategoryRepository`'s already are.
 */
final class SocialLinkRepository extends Repository
{
    private const COLUMNS = 'id, platform, url, icon_name, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->all('SELECT ' . self::COLUMNS . ' FROM social_links ORDER BY sort_order, platform');
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM social_links WHERE id = :id', [':id' => $id]);
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO social_links (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE social_links SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM social_links WHERE id = :id', [':id' => $id]);
    }

    /**
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
                'UPDATE social_links SET sort_order = :position WHERE id = :id',
                [':position' => $position, ':id' => $id],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
