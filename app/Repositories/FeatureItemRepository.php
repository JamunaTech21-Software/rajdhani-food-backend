<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `feature_items` (doc §8.7, §10.1, §12; RTPP-24).
 *
 * A flat content row, no soft delete — nothing in the schema references a
 * feature item's id (unlike categories or products), so a real `DELETE` is
 * the whole story; there is no `deleted_at` column to have used instead.
 *
 * `sort_order` is scoped to one `section` — the same reasoning as a banner's
 * placement (`BannerRepository`'s class doc): `HOME_USP` and `ABOUT_VALUES`
 * never render as one list, so reordering one section must never touch a row
 * in another.
 */
final class FeatureItemRepository extends Repository
{
    private const COLUMNS = 'id, section, title, description, icon_name, icon_image_id,
                             icon_bg_color, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function list(?string $section): array
    {
        [$where, $parameters] = $this->listFilter($section);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM feature_items {$where} ORDER BY section, sort_order",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM feature_items WHERE id = :id', [':id' => $id]);
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO feature_items (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE feature_items SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM feature_items WHERE id = :id', [':id' => $id]);
    }

    /**
     * Scoped to one section, same reasoning as `BannerRepository::reorder()`.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist or do not belong to this section
     */
    public function reorder(string $section, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE feature_items SET sort_order = :position WHERE id = :id AND section = :section',
                [':position' => $position, ':id' => $id, ':section' => $section],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $section): array
    {
        if ($section === null) {
            return ['', []];
        }

        return ['WHERE section = :section', [':section' => $section]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
