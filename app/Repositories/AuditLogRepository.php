<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `audit_logs` (doc §3.1, §8, §13; RTPP-35).
 *
 * `admin_id` is nullable in the schema for a future system-triggered entry,
 * but every row this application writes today comes from `AuditLog`
 * middleware on an already-authenticated admin route, so it is populated in
 * practice on every one of them.
 *
 * `before_json`/`after_json` are stored as `AuditDiff::compact()` already
 * reduced them — this class does not diff anything itself, it only encodes
 * and reads back whatever it is handed.
 */
final class AuditLogRepository extends Repository
{
    private const COLUMNS = 'a.id, a.admin_id, u.name AS admin_name, u.email AS admin_email,
                             a.action, a.entity_type, a.entity_id, a.before_json, a.after_json,
                             a.ip_address, a.created_at';

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(
        ?string $adminId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before,
        ?array $after,
        string $ipAddress,
    ): void {
        $this->run(
            'INSERT INTO audit_logs (id, admin_id, action, entity_type, entity_id, before_json, after_json, ip_address, created_at)
             VALUES (:id, :admin_id, :action, :entity_type, :entity_id, :before_json, :after_json, :ip_address, :created_at)',
            [
                ':id'          => UlidHelper::generate(),
                ':admin_id'    => $adminId,
                ':action'      => $action,
                ':entity_type' => $entityType,
                ':entity_id'   => $entityId,
                ':before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                ':after_json'  => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
                ':ip_address'  => $ipAddress,
                ':created_at'  => $this->now(),
            ],
        );
    }

    /** @return list<array<string,mixed>> */
    public function paginate(
        int $limit,
        int $offset,
        ?string $adminId,
        ?string $action,
        ?string $entityType,
        ?string $from,
        ?string $to,
    ): array {
        [$where, $parameters] = $this->filter($adminId, $action, $entityType, $from, $to);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM audit_logs a
              LEFT JOIN admin_users u ON u.id = a.admin_id
              {$where}
              ORDER BY a.created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $adminId, ?string $action, ?string $entityType, ?string $from, ?string $to): int
    {
        [$where, $parameters] = $this->filter($adminId, $action, $entityType, $from, $to);

        return (int) $this->scalar("SELECT COUNT(*) FROM audit_logs a {$where}", $parameters);
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function filter(?string $adminId, ?string $action, ?string $entityType, ?string $from, ?string $to): array
    {
        $conditions = [];
        $parameters = [];

        if ($adminId !== null) {
            $conditions[] = 'a.admin_id = :admin_id';
            $parameters[':admin_id'] = $adminId;
        }

        if ($action !== null) {
            $conditions[] = 'a.action = :action';
            $parameters[':action'] = $action;
        }

        if ($entityType !== null) {
            $conditions[] = 'a.entity_type = :entity_type';
            $parameters[':entity_type'] = $entityType;
        }

        if ($from !== null) {
            $conditions[] = 'a.created_at >= :from';
            $parameters[':from'] = $from;
        }

        if ($to !== null) {
            $conditions[] = 'a.created_at <= :to';
            $parameters[':to'] = $to;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $parameters];
    }
}
