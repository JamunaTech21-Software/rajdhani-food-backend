<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\SocialLinkRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * `/admin/social-links` (doc §8.2, §9.8, §10.5; RTPP-32) — the footer's
 * social icon row, gated at `Capability::CONTENT` alongside menu links.
 *
 * `platform` is free text (`VARCHAR(64)`, not an `ENUM`) in the schema — the
 * column comment lists common examples (facebook, instagram, linkedin,
 * youtube, whatsapp) but does not constrain the value, so this service
 * doesn't invent a stricter rule the schema itself doesn't enforce.
 */
final class SocialLinkService
{
    use ValidatesInput;

    public function __construct(
        private readonly SocialLinkRepository $links = new SocialLinkRepository(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map($this->view(...), $this->links->list());
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

    /** Idempotent, like every other delete in this codebase — nothing references a social link's id. */
    public function delete(string $id): void
    {
        $this->links->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{reordered:int}
     */
    public function reorder(array $input): array
    {
        $ids = $this->validateIdList($input, 'ids');
        $missing = $this->links->reorder($ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not exist', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a social link: {$id}"],
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

        if ($has('platform')) {
            $fields['platform'] = $this->requiredText($input, 'platform', 64);
        }

        if ($has('url')) {
            $fields['url'] = $this->requiredText($input, 'url', 255);
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
                throw $this->invalid($field, 'Every entry must be a valid social link id');
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
            throw ApiError::notFound('No such social link');
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
            'id'         => (string) $row['id'],
            'platform'   => (string) $row['platform'],
            'url'        => (string) $row['url'],
            'icon_name'  => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'sort_order' => (int) $row['sort_order'],
            'is_active'  => (bool) $row['is_active'],
        ];
    }
}
