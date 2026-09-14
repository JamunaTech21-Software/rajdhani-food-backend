<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Testimonials — flat, `status`-gated rather than `is_active` (doc §8.7,
 * §10.4; RTPP-24), matching the schema's own choice: a testimonial can be a
 * `DRAFT` awaiting review before it goes live, which a boolean cannot
 * express. Defaults to `PUBLISHED`, the schema's own default, for the same
 * reason a banner does (`BannerService`'s class doc) — an admin adding one
 * is almost always putting it live immediately.
 */
final class TestimonialService
{
    use ValidatesInput;

    public function __construct(
        private readonly TestimonialRepository $testimonials = new TestimonialRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function list(array $query): array
    {
        return array_map($this->view(...), $this->testimonials->list($this->optionalStatusFilter($query)));
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireTestimonial($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $id = $this->testimonials->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireTestimonial($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->testimonials->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->testimonials->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $this->validateIdList($input, 'ids');
        $missing = $this->testimonials->reorder($ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not match an existing testimonial', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such testimonial: {$id}"],
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

        if ($has('author_name')) {
            $fields['author_name'] = $this->requiredText($input, 'author_name', 255);
        }

        if ($has('author_role')) {
            $fields['author_role'] = $this->optionalText($input, 'author_role', 255);
        }

        if ($has('avatar_id')) {
            $fields['avatar_id'] = $this->optionalMediaRef($input, 'avatar_id');
        }

        if ($has('quote')) {
            $fields['quote'] = $this->requiredText($input, 'quote', 65535);
        }

        if ($has('rating')) {
            $fields['rating'] = $this->optionalRating($input);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('status')) {
            $fields['status'] = $this->optionalEnum($input, 'status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'], 'PUBLISHED');
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function optionalRating(array $input): ?int
    {
        $value = $input['rating'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw $this->invalid('rating', 'Expected a number');
        }

        $rating = (int) $value;

        if ($rating < 1 || $rating > 5) {
            throw $this->invalid('rating', 'Must be between 1 and 5');
        }

        return $rating;
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->testimonials->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
    }

    /** @param array<string,mixed> $query */
    private function optionalStatusFilter(array $query): ?string
    {
        $value = $query['status'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, ['DRAFT', 'PUBLISHED', 'ARCHIVED'], true)) {
            throw $this->invalid('status', 'Must be one of: DRAFT, PUBLISHED, ARCHIVED');
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
                throw $this->invalid($field, 'Every entry must be a valid testimonial id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireTestimonial(string $id): array
    {
        $testimonial = $this->testimonials->find($id);

        if ($testimonial === null) {
            throw ApiError::notFound('No such testimonial');
        }

        return $testimonial;
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
            'author_name' => (string) $row['author_name'],
            'author_role' => $row['author_role'] === null ? null : (string) $row['author_role'],
            'avatar_id'   => $row['avatar_id'] === null ? null : (string) $row['avatar_id'],
            'quote'       => (string) $row['quote'],
            'rating'      => $row['rating'] === null ? null : (int) $row['rating'],
            'status'      => (string) $row['status'],
            'sort_order'  => (int) $row['sort_order'],
        ];
    }
}
