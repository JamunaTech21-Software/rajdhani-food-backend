<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `testimonials` (doc §8.7, §10.4; RTPP-24). Flat, `status`-gated rather
 * than `is_active` (the schema's own choice — a testimonial can be a
 * `DRAFT` awaiting review, not just on/off). No soft delete; nothing
 * references a testimonial's id.
 */
final class TestimonialRepository extends Repository
{
    private const COLUMNS = 'id, author_name, author_role, avatar_id, quote, rating, status, sort_order';

    /** @return list<array<string,mixed>> */
    public function list(?string $status): array
    {
        [$where, $parameters] = $this->listFilter($status);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM testimonials {$where} ORDER BY sort_order, author_name",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM testimonials WHERE id = :id', [':id' => $id]);
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /**
     * `GET /public/testimonials`, and the home aggregate's testimonials slice
     * (doc §9.3, §14.1; RTPP-36) — `PUBLISHED` only, with the avatar resolved
     * to a URL the same way every other public view does (`ProductRepository`'s
     * `PUBLIC_CARD_COLUMNS`), since the admin's own `find()`/`list()` return
     * the raw `avatar_id` a public caller has no other way to render.
     *
     * @return list<array<string,mixed>>
     */
    public function publicPublished(int $limit): array
    {
        return $this->all(
            "SELECT t.id, t.author_name, t.author_role, t.quote, t.rating,
                    m.secure_url AS avatar_url, m.alt_text AS avatar_alt
               FROM testimonials t
               LEFT JOIN media_assets m ON m.id = t.avatar_id
              WHERE t.status = 'PUBLISHED'
              ORDER BY t.sort_order, t.author_name
              LIMIT :limit",
            [':limit' => $limit],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO testimonials (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE testimonials SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM testimonials WHERE id = :id', [':id' => $id]);
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
                'UPDATE testimonials SET sort_order = :position WHERE id = :id',
                [':position' => $position, ':id' => $id],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $status): array
    {
        if ($status === null) {
            return ['', []];
        }

        return ['WHERE status = :status', [':status' => $status]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
