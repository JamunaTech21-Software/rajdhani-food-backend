<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Stat counters — the animated count-up bands, grouped by `group` (doc
 * §8.7, §10.1; RTPP-24). Flat sortable list, no pagination — see
 * `FeatureItemService`'s class doc for why.
 */
final class StatCounterService
{
    use ValidatesInput;

    private const GROUPS = ['HOME', 'ABOUT', 'GALLERY', 'TEA_GARDEN', 'DEALER_NETWORK'];

    public function __construct(
        private readonly StatCounterRepository $stats = new StatCounterRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        return array_map($this->view(...), $this->stats->list($this->optionalGroupFilter($query)));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireStat($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $id = $this->stats->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireStat($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->stats->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->stats->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $group = $this->requiredGroup($input);
        $ids = $this->validateIdList($input, 'ids');

        $missing = $this->stats->reorder($group, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this group', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a stat counter in this group: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('group')) {
            $fields['group'] = $this->requiredGroup($input);
        }

        if ($has('value')) {
            $fields['value'] = $this->requiredText($input, 'value', 32);
        }

        if ($has('label')) {
            $fields['label'] = $this->requiredText($input, 'label', 255);
        }

        if ($has('icon_name')) {
            $fields['icon_name'] = $this->optionalText($input, 'icon_name', 64);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('is_active')) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function requiredGroup(array $input): string
    {
        $value = $input['group'] ?? null;

        if (!is_string($value) || !in_array($value, self::GROUPS, true)) {
            throw $this->invalid('group', 'Must be one of: ' . implode(', ', self::GROUPS));
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalGroupFilter(array $query): ?string
    {
        $value = $query['group'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, self::GROUPS, true)) {
            throw $this->invalid('group', 'Must be one of: ' . implode(', ', self::GROUPS));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return list<string>
     */
    private function validateIdList(array $input, string $field): array
    {
        $ids = $input[$field] ?? null;

        if (!is_array($ids) || $ids === []) {
            throw $this->invalid($field, 'This field is required and must be a non-empty list');
        }

        $normalised = [];

        foreach ($ids as $id) {
            if (!is_string($id) || !UlidHelper::isValid($id)) {
                throw $this->invalid($field, 'Every entry must be a valid stat counter id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireStat(string $id): array
    {
        $stat = $this->stats->find($id);

        if ($stat === null) {
            throw ApiError::notFound('No such stat counter');
        }

        return $stat;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'         => (string) $row['id'],
            'group'      => (string) $row['group'],
            'value'      => (string) $row['value'],
            'label'      => (string) $row['label'],
            'icon_name'  => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'sort_order' => (int) $row['sort_order'],
            'is_active'  => (int) $row['is_active'] === 1,
        ];
    }
}
