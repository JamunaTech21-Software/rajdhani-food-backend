<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\RichText;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\PageBlockRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Page blocks — the editable sections of static pages, keyed
 * `(page_key, block_key)` (doc §8.7, §11; RTPP-24). The one module in this
 * ticket with a genuinely rich-text field: `body` goes through
 * `RichText::sanitize()` before storage, same as `ProductService`'s tab
 * content, because it is HTML an editor typed and the public site will
 * render unescaped.
 *
 * `(page_key, block_key)` uniqueness follows the same two-layer pattern as a
 * category's slug: a pre-check here for a clean `409`, `uq_page_blocks` for
 * the race a pre-check cannot catch.
 */
final class PageBlockService
{
    use ValidatesInput;

    public function __construct(
        private readonly PageBlockRepository $blocks = new PageBlockRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        $pageKey = is_string($query['page_key'] ?? null) ? $query['page_key'] : null;

        return array_map($this->view(...), $this->blocks->list($pageKey));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireBlock($id));
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
        $this->requireBlock($id);
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
        $this->blocks->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $pageKey = $this->requiredText($input, 'page_key', 64);
        $ids = $this->validateIdList($input, 'ids');

        $missing = $this->blocks->reorder($pageKey, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this page', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a block on this page: {$id}"],
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

        if ($has('page_key')) {
            $fields['page_key'] = $this->requiredText($input, 'page_key', 64);
        }

        foreach (['eyebrow' => 255, 'heading' => 255, 'subheading' => 255, 'cta_label' => 128, 'cta_url' => 255] as $field => $max) {
            if ($has($field)) {
                $fields[$field] = $this->optionalText($input, $field, $max);
            }
        }

        if ($has('body')) {
            $fields['body'] = RichText::sanitize($this->rawTextInput($input, 'body'));
        }

        if ($has('bullet_points')) {
            $fields['bullet_points'] = $this->bulletPoints($input);
        }

        if ($has('image_id')) {
            $fields['image_id'] = $this->optionalMediaRef($input, 'image_id');
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('status')) {
            $fields['status'] = $this->optionalEnum($input, 'status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'], 'PUBLISHED');
        }

        // block_key needs the *effective* page_key — whichever this call
        // sends, or the stored one on a partial update that only touches
        // block_key — the same reasoning ProcessStepService applies to
        // step_number against its group.
        if ($has('block_key')) {
            $blockKey = $this->requiredText($input, 'block_key', 64);
            $effectivePageKey = $this->effectivePageKey($fields, $existingId);

            if ($effectivePageKey !== null && $this->blocks->blockKeyTaken($effectivePageKey, $blockKey, excludingId: $existingId)) {
                throw ApiError::conflict("The block '{$blockKey}' already exists on this page", [
                    ['field' => 'block_key', 'message' => 'This block key is already used on this page'],
                ]);
            }

            $fields['block_key'] = $blockKey;
        }

        return $fields;
    }

    /** @param array<string,scalar|null> $fields */
    private function effectivePageKey(array $fields, ?string $existingId): ?string
    {
        if (isset($fields['page_key']) && is_string($fields['page_key'])) {
            return $fields['page_key'];
        }

        if ($existingId === null) {
            return null;
        }

        $existing = $this->blocks->find($existingId);

        return $existing !== null && is_string($existing['page_key']) ? $existing['page_key'] : null;
    }

    /** @param array<string,mixed> $input */
    private function rawTextInput(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->invalid($field, 'Expected a string');
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function bulletPoints(array $input): ?string
    {
        $value = $input['bullet_points'] ?? null;

        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value)) {
            throw $this->invalid('bullet_points', 'Expected a list of strings');
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_scalar($item) || trim((string) $item) === '') {
                throw $this->invalid('bullet_points', 'Every entry must be a non-empty string');
            }

            $strings[] = trim((string) $item);
        }

        return json_encode($strings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->blocks->mediaAssetExists($id)) {
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
                throw $this->invalid($field, 'Every entry must be a valid page block id');
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
            return $this->blocks->create($fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->blocks->update($id, $fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function translateRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_page_blocks')) {
            return ApiError::conflict('This block key is already used on this page', [
                ['field' => 'block_key', 'message' => 'This block key is already used on this page'],
            ]);
        }

        throw $e;
    }

    /** @return array<string,mixed> */
    private function requireBlock(string $id): array
    {
        $block = $this->blocks->find($id);

        if ($block === null) {
            throw ApiError::notFound('No such page block');
        }

        return $block;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'page_key'      => (string) $row['page_key'],
            'block_key'     => (string) $row['block_key'],
            'eyebrow'       => $row['eyebrow'] === null ? null : (string) $row['eyebrow'],
            'heading'       => $row['heading'] === null ? null : (string) $row['heading'],
            'subheading'    => $row['subheading'] === null ? null : (string) $row['subheading'],
            'body'          => $row['body'] === null ? null : (string) $row['body'],
            'bullet_points' => $row['bullet_points'] === null
                ? null
                : json_decode((string) $row['bullet_points'], true, 512, JSON_THROW_ON_ERROR),
            'image_id'      => $row['image_id'] === null ? null : (string) $row['image_id'],
            'cta_label'     => $row['cta_label'] === null ? null : (string) $row['cta_label'],
            'cta_url'       => $row['cta_url'] === null ? null : (string) $row['cta_url'],
            'sort_order'    => (int) $row['sort_order'],
            'status'        => (string) $row['status'],
        ];
    }
}
