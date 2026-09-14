<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `dealer_applications` (doc §8.8, §9.6, §9.10, §10.3; RTPP-30).
 *
 * No `deleted_at` — same reasoning as `EnquiryRepository`: a lead moves
 * through `status`, it is never deleted. `application_id` is assigned by
 * `DealerApplicationService::submit()` before `create()` is called, inside
 * the same transaction as `ReferenceCounterRepository::nextSequence('DA', …)`
 * — this repository has no opinion on how it was produced.
 */
final class DealerApplicationRepository extends Repository
{
    private const COLUMNS = 'a.id, a.application_id, a.full_name, a.company_name, a.phone, a.email,
                             a.district_id, d.name AS district_name,
                             a.upazila_id, u.name AS upazila_name,
                             a.address_line, a.has_trade_license, a.has_tin_certificate,
                             a.years_of_experience, a.message, a.status,
                             a.assigned_to_id, ad.name AS assignee_name,
                             a.internal_notes, a.reviewed_at, a.ip_address, a.created_at, a.updated_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $status, ?string $districtId, ?string $assignedToId): array
    {
        [$where, $parameters] = $this->filter($status, $districtId, $assignedToId);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()}
              {$where}
              ORDER BY a.created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $status, ?string $districtId, ?string $assignedToId): int
    {
        [$where, $parameters] = $this->filter($status, $districtId, $assignedToId);

        return (int) $this->scalar("SELECT COUNT(*) FROM dealer_applications a {$where}", $parameters);
    }

    /**
     * Every matching row, unpaginated — `DealerApplicationService::exportCsv()`'s
     * only caller, same reasoning as `EnquiryRepository::listAll()`.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(?string $status, ?string $districtId, ?string $assignedToId): array
    {
        [$where, $parameters] = $this->filter($status, $districtId, $assignedToId);

        return $this->all(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()} {$where} ORDER BY a.created_at DESC",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . " {$this->baseFrom()} WHERE a.id = :id",
            [':id' => $id],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + [
            'id' => $id, 'status' => 'SUBMITTED', 'assigned_to_id' => null, 'internal_notes' => null,
            'reviewed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO dealer_applications (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE dealer_applications SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = :id',
            $parameters,
        );
    }

    /**
     * Same as `update()`, but also stamps `reviewed_at` — the timestamp
     * belongs here, not in the service, for the same reason
     * `ReviewRepository::approve()`/`reject()` compute `moderated_at`
     * themselves: `now()` is a repository concern.
     *
     * @param array<string,scalar|null> $fields
     */
    public function updateWithReviewTimestamp(string $id, array $fields): int
    {
        return $this->update($id, $fields + ['reviewed_at' => $this->now()]);
    }

    public function assigneeExists(string $adminId): bool
    {
        return $this->scalar('SELECT id FROM admin_users WHERE id = :id', [':id' => $adminId]) !== null;
    }

    private function baseFrom(): string
    {
        return 'FROM dealer_applications a
                 JOIN districts d ON d.id = a.district_id
                 JOIN upazilas u ON u.id = a.upazila_id
                 LEFT JOIN admin_users ad ON ad.id = a.assigned_to_id';
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function filter(?string $status, ?string $districtId, ?string $assignedToId): array
    {
        $where = 'WHERE 1 = 1';
        $parameters = [];

        if ($status !== null) {
            $where .= ' AND a.status = :status';
            $parameters[':status'] = $status;
        }

        if ($districtId !== null) {
            $where .= ' AND a.district_id = :district_id';
            $parameters[':district_id'] = $districtId;
        }

        if ($assignedToId !== null) {
            $where .= ' AND a.assigned_to_id = :assigned_to_id';
            $parameters[':assigned_to_id'] = $assignedToId;
        }

        return [$where, $parameters];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
