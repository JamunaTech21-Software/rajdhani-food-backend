<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProcessStepRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Process steps — numbered timeline entries grouped by `group` (doc §8.7,
 * §10.4; RTPP-24). The ticket's headline DoD: **two steps cannot share a
 * step number within a group.** Two layers enforce that, the same pattern
 * as a category's slug — a pre-check here for a clean `409`,
 * `uq_process_steps_group_number` for the race a pre-check cannot catch.
 */
final class ProcessStepService
{
    use ValidatesInput;

    private const GROUPS = [
        'FROM_GARDEN_TO_CUP', 'HOW_WE_MAKE_TEA', 'QUALITY_PROCESS',
        'MANUFACTURING_PROCESS', 'BECOME_DEALER',
    ];

    public function __construct(
        private readonly ProcessStepRepository $steps = new ProcessStepRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        return array_map($this->view(...), $this->steps->list($this->optionalGroupFilter($query)));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireStep($id));
    }

    /**
     * `GET /public/process-steps?group=` (doc §9.3, §10.4; RTPP-67) —
     * `group` is required, same reasoning as the feature-item and stat
     * public endpoints: a timeline is always one group's steps, never a mix.
     *
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function publicByGroup(array $query): array
    {
        $group = $this->requiredGroup($query);

        return array_map($this->publicView(...), $this->steps->publicByGroup($group));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, existingId: null, partial: false);
        $id = $this->insertHandlingRace($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireStep($id);
        $fields = $this->coreFields($input, existingId: $id, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->updateHandlingRace($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->steps->delete($id);
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

        $missing = $this->steps->reorder($group, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this group', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a process step in this group: {$id}"],
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
    private function coreFields(array $input, ?string $existingId, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('group')) {
            $fields['group'] = $this->requiredGroup($input);
        }

        if ($has('title')) {
            $fields['title'] = $this->requiredText($input, 'title', 255);
        }

        if ($has('description')) {
            $fields['description'] = $this->optionalText($input, 'description', 65535);
        }

        if ($has('icon_name')) {
            $fields['icon_name'] = $this->optionalText($input, 'icon_name', 64);
        }

        if ($has('image_id')) {
            $fields['image_id'] = $this->optionalMediaRef($input, 'image_id');
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('is_active')) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        // step_number needs the *effective* group (whichever this call sends,
        // or the stored one on a partial update) to check uniqueness against
        // the pair that will actually end up in the row — the same reasoning
        // BannerService applies to a partial schedule-window update.
        if ($has('step_number')) {
            $stepNumber = $this->requiredPositiveInt($input, 'step_number');
            $effectiveGroup = $this->effectiveGroup($fields, $existingId);

            if ($effectiveGroup !== null && $this->steps->stepNumberTaken($effectiveGroup, $stepNumber, excludingId: $existingId)) {
                throw ApiError::conflict("Step {$stepNumber} is already used in this group", [
                    ['field' => 'step_number', 'message' => 'This step number is already used in this group'],
                ]);
            }

            $fields['step_number'] = $stepNumber;
        }

        return $fields;
    }

    /**
     * The group `step_number`'s uniqueness must be checked against — whichever
     * this call itself sends, or the row's stored one on a partial update
     * that only touches `step_number`. Same reasoning as
     * `BannerService::update()`'s effective-schedule-window computation.
     *
     * @param array<string,scalar|null> $fields
     */
    private function effectiveGroup(array $fields, ?string $existingId): ?string
    {
        if (isset($fields['group']) && is_string($fields['group'])) {
            return $fields['group'];
        }

        if ($existingId === null) {
            return null;
        }

        $existing = $this->steps->find($existingId);

        return $existing !== null && is_string($existing['group']) ? $existing['group'] : null;
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

    /** @param array<string,mixed> $input */
    private function requiredPositiveInt(array $input, string $field): int
    {
        $value = $input[$field] ?? null;

        if (!is_numeric($value) || (int) $value <= 0) {
            throw $this->invalid($field, 'Expected a positive whole number');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->steps->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
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
                throw $this->invalid($field, 'Every entry must be a valid process step id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @param array<string,scalar|null> $fields */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->steps->create($fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->steps->update($id, $fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function translateRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_process_steps_group_number')) {
            return ApiError::conflict('This step number is already used in this group', [
                ['field' => 'step_number', 'message' => 'This step number is already used in this group'],
            ]);
        }

        throw $e;
    }

    /** @return array<string,mixed> */
    private function requireStep(string $id): array
    {
        $step = $this->steps->find($id);

        if ($step === null) {
            throw ApiError::notFound('No such process step');
        }

        return $step;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'group'       => (string) $row['group'],
            'step_number' => (int) $row['step_number'],
            'title'       => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'   => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'image_id'    => $row['image_id'] === null ? null : (string) $row['image_id'],
            'sort_order'  => (int) $row['sort_order'],
            'is_active'   => (int) $row['is_active'] === 1,
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function publicView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'step_number' => (int) $row['step_number'],
            'title'       => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'   => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'image'       => $row['image_url'] === null ? null : [
                'url'    => (string) $row['image_url'],
                'alt'    => $row['image_alt'] === null ? null : (string) $row['image_alt'],
                'width'  => $row['image_width'] === null ? null : (int) $row['image_width'],
                'height' => $row['image_height'] === null ? null : (int) $row['image_height'],
            ],
        ];
    }
}
