<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\FeatureItemRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Feature items — icon rows grouped by `section` (doc §8.7, §10.1, RTPP-24).
 * A flat sortable list, no pagination: the admin screen is "Sections | ...
 * each a sortable list" (doc §11), not a paginated table, and every section
 * holds a handful of rows in practice.
 */
final class FeatureItemService
{
    use ValidatesInput;

    private const SECTIONS = [
        'HOME_USP', 'HOME_WHY_US', 'ABOUT_VALUES', 'ABOUT_STRENGTH',
        'QUALITY_COMMITMENT', 'DEALER_BENEFITS', 'CONTACT_ASSURANCE', 'PRODUCT_HIGHLIGHTS',
    ];

    public function __construct(
        private readonly FeatureItemRepository $items = new FeatureItemRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        return array_map($this->view(...), $this->items->list($this->optionalSectionFilter($query)));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireItem($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $id = $this->items->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireItem($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->items->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        // Idempotent, like every delete in this codebase, even though this
        // one is a real DELETE rather than a soft one — the end state
        // ("this row is gone") is the same whether it existed a moment ago
        // or never did.
        $this->items->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $section = $this->requiredSection($input);
        $ids = $this->validateIdList($input, 'ids');

        $missing = $this->items->reorder($section, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this section', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a feature item in this section: {$id}"],
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

        if ($has('section')) {
            $fields['section'] = $this->requiredSection($input);
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

        if ($has('icon_image_id')) {
            $fields['icon_image_id'] = $this->optionalMediaRef($input, 'icon_image_id');
        }

        if ($has('icon_bg_color')) {
            $fields['icon_bg_color'] = $this->optionalHexColour($input, 'icon_bg_color');
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
    private function requiredSection(array $input): string
    {
        $value = $input['section'] ?? null;

        if (!is_string($value) || !in_array($value, self::SECTIONS, true)) {
            throw $this->invalid('section', 'Must be one of: ' . implode(', ', self::SECTIONS));
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalSectionFilter(array $query): ?string
    {
        $value = $query['section'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, self::SECTIONS, true)) {
            throw $this->invalid('section', 'Must be one of: ' . implode(', ', self::SECTIONS));
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->items->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
    }

    /**
     * Same shape as `BannerService::validateIdList()` — see that class's
     * doc for why this is copied rather than shared.
     *
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
                throw $this->invalid($field, 'Every entry must be a valid feature item id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireItem(string $id): array
    {
        $item = $this->items->find($id);

        if ($item === null) {
            throw ApiError::notFound('No such feature item');
        }

        return $item;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'section'        => (string) $row['section'],
            'title'          => (string) $row['title'],
            'description'    => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'      => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'icon_image_id'  => $row['icon_image_id'] === null ? null : (string) $row['icon_image_id'],
            'icon_bg_color'  => $row['icon_bg_color'] === null ? null : (string) $row['icon_bg_color'],
            'sort_order'     => (int) $row['sort_order'],
            'is_active'      => (int) $row['is_active'] === 1,
        ];
    }
}
