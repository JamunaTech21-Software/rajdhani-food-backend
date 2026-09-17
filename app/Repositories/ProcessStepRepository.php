<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `process_steps` (doc §8.7, §10.4, §12; RTPP-24).
 *
 * `UNIQUE KEY uq_process_steps_group_number (group, step_number)` is the
 * ticket's headline DoD item — new in v3.0 because it was impossible to
 * enforce cleanly while the key had to include `brand_id` too. The service
 * pre-checks for a clean `409`; this constraint is what actually guarantees
 * it against two admins saving step 3 of the same timeline at once.
 *
 * No soft delete, same reasoning as `FeatureItemRepository` — nothing
 * references a process step's id, and there is no `deleted_at` column.
 */
final class ProcessStepRepository extends Repository
{
    private const COLUMNS = 'id, `group`, step_number, title, description, icon_name,
                             image_id, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function list(?string $group): array
    {
        [$where, $parameters] = $this->listFilter($group);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM process_steps {$where} ORDER BY `group`, step_number",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM process_steps WHERE id = :id', [':id' => $id]);
    }

    /**
     * `GET /public/process-steps?group=` (doc §9.3, §10.4; RTPP-67) —
     * active steps in one group, in `step_number` order (the timeline's
     * actual sequence — distinct from `sort_order`, which only reorders
     * display position; see this class's own doc). Active only, image
     * resolved the way every other public view in this codebase does.
     *
     * @return list<array<string,mixed>>
     */
    public function publicByGroup(string $group): array
    {
        return $this->all(
            'SELECT s.id, s.step_number, s.title, s.description, s.icon_name,
                    m.secure_url AS image_url, m.alt_text AS image_alt, m.width AS image_width, m.height AS image_height
               FROM process_steps s
               LEFT JOIN media_assets m ON m.id = s.image_id
              WHERE s.`group` = :group AND s.is_active = 1
              ORDER BY s.step_number',
            [':group' => $group],
        );
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /**
     * @param string|null $excludingId when checking during an update, the
     *                                 step's own current number must not
     *                                 count as a collision with itself
     */
    public function stepNumberTaken(string $group, int $stepNumber, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM process_steps WHERE `group` = :group AND step_number = :step_number';
        $parameters = [':group' => $group, ':step_number' => $stepNumber];

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
            'INSERT INTO process_steps (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE process_steps SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM process_steps WHERE id = :id', [':id' => $id]);
    }

    /**
     * Scoped to one group — reorders display position (`sort_order`), never
     * `step_number`, which is a separate identity the timeline's "Step N"
     * label and the uniqueness constraint both depend on.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist or do not belong to this group
     */
    public function reorder(string $group, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE process_steps SET sort_order = :position WHERE id = :id AND `group` = :group',
                [':position' => $position, ':id' => $id, ':group' => $group],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $group): array
    {
        if ($group === null) {
            return ['', []];
        }

        return ['WHERE `group` = :group', [':group' => $group]];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
