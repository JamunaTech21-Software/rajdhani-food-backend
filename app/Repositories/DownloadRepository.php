<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `downloads` (doc §8.7, §9.6, §9.9, §10.3; RTPP-32) — brochure and
 * catalogue files, resolved publicly by `key` rather than by id: the
 * front-end's "Download Brochure" button references a stable key
 * (`dealer_brochure`) it was built against, not a ULID it would have to look
 * up first.
 *
 * `requires_email` is carried through unchanged but not enforced by this
 * ticket — doc §19's open item on gating brochure downloads behind an email
 * address was resolved 2026-09-13: open, no email required. The column
 * stays for whichever future ticket revisits that.
 */
final class DownloadRepository extends Repository
{
    private const ADMIN_COLUMNS = 'd.id, d.title, d.`key`, d.description, d.file_id, m.secure_url AS file_url,
                                   d.requires_email, d.download_count, d.is_active';

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->all('SELECT ' . self::ADMIN_COLUMNS . " {$this->baseFrom()} ORDER BY d.title");
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::ADMIN_COLUMNS . " {$this->baseFrom()} WHERE d.id = :id", [':id' => $id]);
    }

    /**
     * `GET /public/downloads/{key}` — only an active download is resolvable;
     * an inactive one 404s, same as an unpublished product.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveByKey(string $key): ?array
    {
        return $this->one(
            'SELECT ' . self::ADMIN_COLUMNS . " {$this->baseFrom()} WHERE d.`key` = :key AND d.is_active = 1",
            [':key' => $key],
        );
    }

    public function keyExists(string $key): bool
    {
        return $this->scalar('SELECT id FROM downloads WHERE `key` = :key', [':key' => $key]) !== null;
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    public function incrementDownloadCount(string $id): void
    {
        $this->run('UPDATE downloads SET download_count = download_count + 1 WHERE id = :id', [':id' => $id]);
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'download_count' => 0];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO downloads (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE downloads SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM downloads WHERE id = :id', [':id' => $id]);
    }

    private function baseFrom(): string
    {
        return 'FROM downloads d LEFT JOIN media_assets m ON m.id = d.file_id';
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
