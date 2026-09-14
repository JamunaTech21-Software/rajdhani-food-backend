<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\MenuLinkRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * `/admin/menu-links` (doc §8.2, §9.8, §10.5; RTPP-32) — header, footer and
 * legal navigation, gated at `Capability::CONTENT` alongside banners and page
 * blocks (§7.3's "Banners and page content" row).
 */
final class MenuLinkService
{
    use ValidatesInput;

    private const LOCATIONS = ['header', 'footer_quick', 'footer_products', 'legal'];

    public function __construct(
        private readonly MenuLinkRepository $links = new MenuLinkRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        $location = $query['location'] ?? null;

        if ($location !== null && (!is_string($location) || !in_array($location, self::LOCATIONS, true))) {
            throw $this->invalid('location', 'Must be one of: ' . implode(', ', self::LOCATIONS));
        }

        return array_map($this->view(...), $this->links->list($location));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireLink($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, false);
        $id = $this->links->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireLink($id);
        $fields = $this->coreFields($input, true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->links->update($id, $fields);

        return $this->find($id);
    }

    /**
     * Idempotent, like every other delete in this codebase — an id that does
     * not exist has already reached the end state this call wants. A link
     * with children is a `409`: deleting it would either orphan its children
     * (an FK violation, since `parent_id` has no `ON DELETE` clause) or
     * silently take them down with it, and neither is a "delete this one
     * link" the caller asked for.
     */
    public function delete(string $id): void
    {
        if (!$this->links->exists($id)) {
            return;
        }

        if ($this->links->hasChildren($id)) {
            throw ApiError::conflict('This link has child links — remove or reassign them first', [
                ['field' => 'id', 'message' => 'This link has child links'],
            ]);
        }

        $this->links->delete($id);
    }

    /**
     * `PATCH /admin/menu-links/reorder`, scoped to one `location` — the same
     * pattern `FeatureItemService::reorder()` uses for `section`.
     *
     * @param array<string,mixed> $input
     *
     * @return array{reordered:int}
     */
    public function reorder(array $input): array
    {
        $location = $input['location'] ?? null;

        if (!is_string($location) || !in_array($location, self::LOCATIONS, true)) {
            throw $this->invalid('location', 'Must be one of: ' . implode(', ', self::LOCATIONS));
        }

        $ids = $this->validateIdList($input, 'ids');
        $missing = $this->links->reorder($location, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this location', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a menu link in this location: {$id}"],
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

        if ($has('location')) {
            $location = $input['location'] ?? null;

            if (!is_string($location) || !in_array($location, self::LOCATIONS, true)) {
                throw $this->invalid('location', 'Must be one of: ' . implode(', ', self::LOCATIONS));
            }

            $fields['location'] = $location;
        }

        if ($has('label')) {
            $fields['label'] = $this->requiredText($input, 'label', 128);
        }

        if ($has('url')) {
            $fields['url'] = $this->requiredText($input, 'url', 255);
        }

        if ($has('parent_id')) {
            $fields['parent_id'] = $this->optionalParent($input);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('is_active')) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        if ($has('open_in_new_tab')) {
            $fields['open_in_new_tab'] = $this->optionalBool($input, 'open_in_new_tab', false);
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function optionalParent(array $input): ?string
    {
        $id = $this->optionalUlid($input, 'parent_id');

        if ($id !== null && !$this->links->exists($id)) {
            throw $this->invalid('parent_id', 'No such menu link');
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
                throw $this->invalid($field, 'Every entry must be a valid menu link id');
            }

            $normalised[] = $id;
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireLink(string $id): array
    {
        $link = $this->links->find($id);

        if ($link === null) {
            throw ApiError::notFound('No such menu link');
        }

        return $link;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'location'        => (string) $row['location'],
            'label'           => (string) $row['label'],
            'url'             => (string) $row['url'],
            'parent_id'       => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'sort_order'      => (int) $row['sort_order'],
            'is_active'       => (bool) $row['is_active'],
            'open_in_new_tab' => (bool) $row['open_in_new_tab'],
        ];
    }
}
