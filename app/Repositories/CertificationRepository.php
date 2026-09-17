<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `certifications` (doc §8.7, §10.4; RTPP-24). Flat — no grouping column, so
 * `sort_order` is a single global order, and `reorder()` is the same
 * unscoped shape as `CategoryRepository::reorder()`. No soft delete; nothing
 * references a certification's id.
 */
final class CertificationRepository extends Repository
{
    private const COLUMNS = 'id, name, subtitle, logo_id, certificate_file_id, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->all('SELECT ' . self::COLUMNS . ' FROM certifications ORDER BY sort_order, name');
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM certifications WHERE id = :id', [':id' => $id]);
    }

    /**
     * `GET /public/certifications` (doc §9.3, §10.4; RTPP-67) — active
     * rows only, logo and certificate file both resolved to URLs the way
     * every other public view in this codebase resolves a media reference.
     *
     * @return list<array<string,mixed>>
     */
    public function publicActive(): array
    {
        return $this->all(
            'SELECT c.id, c.name, c.subtitle,
                    l.secure_url AS logo_url, l.alt_text AS logo_alt, l.width AS logo_width, l.height AS logo_height,
                    f.secure_url AS certificate_url
               FROM certifications c
               LEFT JOIN media_assets l ON l.id = c.logo_id
               LEFT JOIN media_assets f ON f.id = c.certificate_file_id
              WHERE c.is_active = 1
              ORDER BY c.sort_order, c.name',
        );
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
            'INSERT INTO certifications (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE certifications SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM certifications WHERE id = :id', [':id' => $id]);
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
                'UPDATE certifications SET sort_order = :position WHERE id = :id',
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
