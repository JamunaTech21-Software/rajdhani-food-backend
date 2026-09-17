<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\CertificationRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Certifications — logo plus a downloadable certificate PDF (doc §8.7,
 * §10.4; RTPP-24). Flat, no grouping, so `reorder()` is a plain unscoped
 * list — the same shape as `CategoryService::reorder()`.
 */
final class CertificationService
{
    use ValidatesInput;

    public function __construct(
        private readonly CertificationRepository $certifications = new CertificationRepository(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map($this->view(...), $this->certifications->list());
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->view($this->requireCertification($id));
    }

    /**
     * `GET /public/certifications` (doc §9.3, §10.4; RTPP-67) — active
     * certifications, the same row rendered on both About and Quality.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return array_map($this->publicView(...), $this->certifications->publicActive());
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $id = $this->certifications->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $this->requireCertification($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->certifications->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->certifications->delete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $ids = $this->validateIdList($input, 'ids');
        $missing = $this->certifications->reorder($ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not match an existing certification', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "No such certification: {$id}"],
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

        if ($has('name')) {
            $fields['name'] = $this->requiredText($input, 'name', 255);
        }

        if ($has('subtitle')) {
            $fields['subtitle'] = $this->optionalText($input, 'subtitle', 255);
        }

        if ($has('logo_id')) {
            $fields['logo_id'] = $this->optionalMediaRef($input, 'logo_id');
        }

        if ($has('certificate_file_id')) {
            $fields['certificate_file_id'] = $this->optionalMediaRef($input, 'certificate_file_id');
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
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->certifications->mediaAssetExists($id)) {
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
                throw $this->invalid($field, 'Every entry must be a valid certification id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @return array<string,mixed> */
    private function requireCertification(string $id): array
    {
        $certification = $this->certifications->find($id);

        if ($certification === null) {
            throw ApiError::notFound('No such certification');
        }

        return $certification;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'id'                  => (string) $row['id'],
            'name'                => (string) $row['name'],
            'subtitle'            => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'logo_id'             => $row['logo_id'] === null ? null : (string) $row['logo_id'],
            'certificate_file_id' => $row['certificate_file_id'] === null ? null : (string) $row['certificate_file_id'],
            'sort_order'          => (int) $row['sort_order'],
            'is_active'           => (int) $row['is_active'] === 1,
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
            'name'        => (string) $row['name'],
            'subtitle'    => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'logo'        => $row['logo_url'] === null ? null : [
                'url'    => (string) $row['logo_url'],
                'alt'    => $row['logo_alt'] === null ? null : (string) $row['logo_alt'],
                'width'  => $row['logo_width'] === null ? null : (int) $row['logo_width'],
                'height' => $row['logo_height'] === null ? null : (int) $row['logo_height'],
            ],
            'certificate_url' => $row['certificate_url'] === null ? null : (string) $row['certificate_url'],
        ];
    }
}
