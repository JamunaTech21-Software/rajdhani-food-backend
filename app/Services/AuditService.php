<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\DateHelper;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Repositories\AuditLogRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * The audit log query API (doc §11 "Audit Log"; RTPP-35) — read-only, on
 * purpose: nothing in this class, or in `RolePolicy`, grants a way to write
 * or edit an audit row. It is written by `AuditLog` middleware and by nobody
 * else, which is what makes it worth reading.
 */
final class AuditService
{
    use ValidatesInput;

    public function __construct(
        private readonly AuditLogRepository $logs = new AuditLogRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $adminId = $this->optionalUlid($query, 'actor');
        $action = $this->optionalText($query, 'action', 128);
        $entityType = $this->optionalText($query, 'resource', 64);
        $from = $this->optionalDate($query, 'from');
        $to = $this->inclusiveTo($query);

        $rows = $this->logs->paginate($pagination->limit, $pagination->offset(), $adminId, $action, $entityType, $from, $to);
        $total = $this->logs->count($adminId, $action, $entityType, $from, $to);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * A bare `Y-m-d` "to" date means "through the end of that day" to anyone
     * reading a filter form, but `created_at <= '2026-09-14'` compares against
     * midnight and excludes the entire day it names. Widening a date-only
     * value to the last instant of that day is what makes the two agree.
     *
     * @param array<string,mixed> $query
     */
    private function inclusiveTo(array $query): ?string
    {
        $to = $this->optionalDate($query, 'to');

        if ($to === null || strlen($to) !== 10) {
            return $to;
        }

        return "{$to} 23:59:59.999";
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'admin_id'    => $row['admin_id'] === null ? null : (string) $row['admin_id'],
            'admin_name'  => $row['admin_name'] === null ? null : (string) $row['admin_name'],
            'admin_email' => $row['admin_email'] === null ? null : (string) $row['admin_email'],
            'action'      => (string) $row['action'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id'   => $row['entity_id'] === null ? null : (string) $row['entity_id'],
            'before'      => $row['before_json'] === null ? null : json_decode((string) $row['before_json'], true),
            'after'       => $row['after_json'] === null ? null : json_decode((string) $row['after_json'], true),
            'ip_address'  => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at'  => DateHelper::iso((string) $row['created_at']),
        ];
    }
}
