<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `product_enquiries` (doc §8.8, §9.6, §9.10, §11; RTPP-29).
 *
 * No `deleted_at` — a lead is never deleted, only moved to `SPAM` or
 * `CLOSED`; this table has no delete path anywhere in this ticket's scope.
 * `reference_no` is assigned by `EnquiryService::submit()` before `create()`
 * is ever called, inside the same transaction as
 * `ReferenceCounterRepository::nextSequence()` — this repository has no
 * opinion on how the reference was produced, only that one is required.
 */
final class EnquiryRepository extends Repository
{
    private const COLUMNS = 'e.id, e.reference_no, e.product_id, p.name AS product_name, p.slug AS product_slug,
                             e.customer_id, e.name, e.company_name, e.phone, e.email, e.city,
                             e.pack_size_label, e.quantity, e.message, e.status,
                             e.assigned_to_id, a.name AS assignee_name,
                             e.internal_notes, e.source_page, e.ip_address, e.created_at, e.updated_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $status, ?string $productId, ?string $assignedToId): array
    {
        [$where, $parameters] = $this->filter($status, $productId, $assignedToId);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()}
              {$where}
              ORDER BY e.created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $status, ?string $productId, ?string $assignedToId): int
    {
        [$where, $parameters] = $this->filter($status, $productId, $assignedToId);

        return (int) $this->scalar("SELECT COUNT(*) FROM product_enquiries e {$where}", $parameters);
    }

    /**
     * Every matching row, unpaginated — `EnquiryService::exportCsv()`'s only
     * caller. The same filters as `paginate()`, deliberately no limit: an
     * export that silently truncated at the page size would be a wrong
     * export, not a smaller one.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(?string $status, ?string $productId, ?string $assignedToId): array
    {
        [$where, $parameters] = $this->filter($status, $productId, $assignedToId);

        return $this->all(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()} {$where} ORDER BY e.created_at DESC",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()} WHERE e.id = :id",
            [':id' => $id],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + [
            'id' => $id, 'status' => 'NEW', 'assigned_to_id' => null, 'internal_notes' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO product_enquiries (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE product_enquiries SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = :id',
            $parameters,
        );
    }

    public function assigneeExists(string $adminId): bool
    {
        return $this->scalar('SELECT id FROM admin_users WHERE id = :id', [':id' => $adminId]) !== null;
    }

    private function baseFrom(): string
    {
        return 'FROM product_enquiries e
                 LEFT JOIN products p ON p.id = e.product_id
                 LEFT JOIN admin_users a ON a.id = e.assigned_to_id';
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function filter(?string $status, ?string $productId, ?string $assignedToId): array
    {
        $where = 'WHERE 1 = 1';
        $parameters = [];

        if ($status !== null) {
            $where .= ' AND e.status = :status';
            $parameters[':status'] = $status;
        }

        if ($productId !== null) {
            $where .= ' AND e.product_id = :product_id';
            $parameters[':product_id'] = $productId;
        }

        if ($assignedToId !== null) {
            $where .= ' AND e.assigned_to_id = :assigned_to_id';
            $parameters[':assigned_to_id'] = $assignedToId;
        }

        return [$where, $parameters];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
