<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `stat_counters` (doc §8.7, §10.1; RTPP-24). The animated count-up bands —
 * `value` is stored as text (`'25+'`, `'1000+'`, `'100%'`) because these are
 * display strings, not numbers to do arithmetic on.
 *
 * `sort_order` is scoped to one `group`, and there is no soft delete — same
 * reasoning as `FeatureItemRepository`'s class doc.
 */
final class StatCounterRepository extends Repository
{
    private const COLUMNS = 'id, `group`, value, label, icon_name, sort_order, is_active';

    /** @return list<array<string,mixed>> */
    public function list(?string $group): array
    {
        [$where, $parameters] = $this->listFilter($group);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM stat_counters {$where} ORDER BY `group`, sort_order",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM stat_counters WHERE id = :id', [':id' => $id]);
    }

    /**
     * The public stats band for one group (doc §9.3 `/public/stats`, §10.1
     * "Stat counters"; RTPP-36) — unlike `list()`, which the admin screen
     * uses and which includes inactive rows so they can be re-enabled.
     *
     * @return list<array<string,mixed>>
     */
    public function publicByGroup(string $group): array
    {
        return $this->all(
            'SELECT ' . self::COLUMNS . ' FROM stat_counters WHERE `group` = :group AND is_active = 1 ORDER BY sort_order',
            [':group' => $group],
        );
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO stat_counters (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE stat_counters SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM stat_counters WHERE id = :id', [':id' => $id]);
    }

    /**
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
                'UPDATE stat_counters SET sort_order = :position WHERE id = :id AND `group` = :group',
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
