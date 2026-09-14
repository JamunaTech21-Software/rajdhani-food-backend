<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `contact_messages` (doc §8.8, §9.6, §9.10, §11; RTPP-31).
 *
 * No `assigned_to_id` and no `updated_at` — unlike enquiries and dealer
 * applications, a contact message is not assigned to anyone and the schema
 * (`database/migrations/007_submissions.sql`) tracks only `created_at` and
 * `replied_at`. `update()` therefore never touches an `updated_at` column
 * that does not exist.
 */
final class ContactMessageRepository extends Repository
{
    private const COLUMNS = 'id, name, email, phone, subject, message, status, replied_at, internal_notes, ip_address, created_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $status): array
    {
        [$where, $parameters] = $this->filter($status);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM contact_messages
              {$where}
              ORDER BY created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $status): int
    {
        [$where, $parameters] = $this->filter($status);

        return (int) $this->scalar("SELECT COUNT(*) FROM contact_messages {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM contact_messages WHERE id = :id', [':id' => $id]);
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();

        $row = $fields + [
            'id' => $id, 'status' => 'UNREAD', 'replied_at' => null, 'internal_notes' => null,
            'created_at' => $this->now(),
        ];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO contact_messages (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE contact_messages SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    /**
     * Same as `update()`, but also stamps `replied_at` — the same split
     * `ReviewRepository::approve()`/`reject()` use for `moderated_at` and
     * `DealerApplicationRepository::updateWithReviewTimestamp()` uses for
     * `reviewed_at`: `now()` is a repository concern.
     *
     * @param array<string,scalar|null> $fields
     */
    public function updateWithRepliedTimestamp(string $id, array $fields): int
    {
        return $this->update($id, $fields + ['replied_at' => $this->now()]);
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function filter(?string $status): array
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
