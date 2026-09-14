<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `page_blocks` (doc §8.7, §11; RTPP-24) — the editable sections of static
 * pages (About, Quality, Dealer, legal pages), keyed `(page_key, block_key)`.
 * `body` is this module's one genuinely rich-text field (`MEDIUMTEXT`,
 * commented as such in the schema) — sanitised through `RichText::sanitize()`
 * before storage, same as `ProductService`'s tab content.
 *
 * `sort_order` is scoped to one `page_key` — a page's blocks reorder among
 * themselves, never against another page's. No soft delete; nothing
 * references a page block's id.
 */
final class PageBlockRepository extends Repository
{
    private const COLUMNS = 'id, page_key, block_key, eyebrow, heading, subheading, body,
                             bullet_points, image_id, cta_label, cta_url, sort_order, status';

    /** @return list<array<string,mixed>> */
    public function list(?string $pageKey): array
    {
        [$where, $parameters] = $this->listFilter($pageKey);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM page_blocks {$where} ORDER BY page_key, sort_order",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM page_blocks WHERE id = :id', [':id' => $id]);
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /**
     * @param string|null $excludingId when checking during an update, the
     *                                 block's own current key must not count
     *                                 as a collision with itself
     */
    public function blockKeyTaken(string $pageKey, string $blockKey, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM page_blocks WHERE page_key = :page_key AND block_key = :block_key';
        $parameters = [':page_key' => $pageKey, ':block_key' => $blockKey];

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
            'INSERT INTO page_blocks (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE page_blocks SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM page_blocks WHERE id = :id', [':id' => $id]);
    }

    /**
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist or do not belong to this page
     */
    public function reorder(string $pageKey, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE page_blocks SET sort_order = :position WHERE id = :id AND page_key = :page_key',
                [':position' => $position, ':id' => $id, ':page_key' => $pageKey],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $pageKey): array
    {
        if ($pageKey === null) {
            return ['', []];
        }

        return ['WHERE page_key = :page_key', [':page_key' => $pageKey]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
