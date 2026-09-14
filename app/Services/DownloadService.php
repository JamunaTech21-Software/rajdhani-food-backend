<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\DownloadRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Brochure and catalogue files (doc §8.7, §9.6, §9.9, §10.3; RTPP-32).
 *
 * **Two-layer `key` uniqueness**, the same pattern `CategoryService` uses
 * for slugs: a pre-check (`keyExists()`) for the friendly case, and
 * `uq_downloads_key` catching the race a pre-check cannot — two admins
 * creating the same key at once. `key` is admin-chosen, not derived from
 * `title`, because the front-end's download buttons are built against a
 * known, stable key (`dealer_brochure`) rather than whatever a title happens
 * to slugify to.
 *
 * **`GET /public/downloads/{key}` never requires an email first** — doc
 * §19's open item on this was resolved 2026-09-13 ("Open — no email
 * required"). `requires_email` is stored and returned to the admin panel
 * but not read by `resolve()`; enforcing it is explicitly left to whichever
 * future ticket revisits that decision, not assumed here.
 */
final class DownloadService
{
    use ValidatesInput;

    public function __construct(
        private readonly DownloadRepository $downloads = new DownloadRepository(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map($this->adminView(...), $this->downloads->list());
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->adminView($this->requireDownload($id));
    }

    /**
     * `GET /public/downloads/{key}` (doc §9.6). Resolves the file URL and
     * increments the counter — the same read-then-increment idiom
     * `NewsService::show()` uses for `view_count`: a lost increment under a
     * genuine race is a cosmetic under-count, not a correctness issue, and
     * does not justify locking machinery a page-view-style counter has
     * never needed elsewhere in this codebase.
     *
     * @return array{title:string,description:?string,url:string}
     */
    public function resolve(string $key): array
    {
        $download = $this->downloads->findActiveByKey($key);

        if ($download === null || $download['file_url'] === null) {
            throw ApiError::notFound('No such download');
        }

        $this->downloads->incrementDownloadCount((string) $download['id']);

        return [
            'title'       => (string) $download['title'],
            'description' => $download['description'] === null ? null : (string) $download['description'],
            'url'         => (string) $download['file_url'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, false);
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
        $this->requireDownload($id);
        $fields = $this->coreFields($input, true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->updateHandlingRace($id, $fields);

        return $this->find($id);
    }

    /** Idempotent, like every other delete in this codebase — nothing references a download's id. */
    public function delete(string $id): void
    {
        $this->downloads->delete($id);
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

        if ($has('title')) {
            $fields['title'] = $this->requiredText($input, 'title', 255);
        }

        if ($has('key')) {
            $fields['key'] = $this->requiredKey($input);
        }

        if ($has('description')) {
            $fields['description'] = $this->optionalText($input, 'description', 512);
        }

        if ($has('file_id')) {
            $fields['file_id'] = $this->requiredFile($input);
        }

        if ($has('requires_email')) {
            $fields['requires_email'] = $this->optionalBool($input, 'requires_email', false);
        }

        if ($has('is_active')) {
            $fields['is_active'] = $this->optionalBool($input, 'is_active', true);
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function requiredKey(array $input): string
    {
        $key = $this->requiredText($input, 'key', 64);

        if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            throw $this->invalid('key', 'Use lowercase letters, digits and underscores only, e.g. dealer_brochure');
        }

        if ($this->downloads->keyExists($key)) {
            throw ApiError::conflict("The key '{$key}' is already in use", [
                ['field' => 'key', 'message' => 'This key is already in use'],
            ]);
        }

        return $key;
    }

    /** @param array<string,mixed> $input */
    private function requiredFile(array $input): string
    {
        $id = $this->requiredUlid($input, 'file_id');

        if (!$this->downloads->mediaAssetExists($id)) {
            throw $this->invalid('file_id', 'No such media asset');
        }

        return $id;
    }

    /**
     * Insert, translating a `uq_downloads_key` race into the same `409` the
     * pre-check gives — the same pattern `CategoryService::insertHandlingRace()`
     * uses for slugs.
     *
     * @param array<string,scalar|null> $fields
     */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->downloads->create($fields);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw ApiError::conflict("The key '{$fields['key']}' is already in use", [
                    ['field' => 'key', 'message' => 'This key is already in use'],
                ]);
            }

            throw $e;
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->downloads->update($id, $fields);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw ApiError::conflict("The key '{$fields['key']}' is already in use", [
                    ['field' => 'key', 'message' => 'This key is already in use'],
                ]);
            }

            throw $e;
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_downloads_key');
    }

    /** @return array<string,mixed> */
    private function requireDownload(string $id): array
    {
        $download = $this->downloads->find($id);

        if ($download === null) {
            throw ApiError::notFound('No such download');
        }

        return $download;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'title'           => (string) $row['title'],
            'key'             => (string) $row['key'],
            'description'     => $row['description'] === null ? null : (string) $row['description'],
            'file'            => $row['file_url'] === null ? null : ['id' => (string) $row['file_id'], 'url' => (string) $row['file_url']],
            'requires_email'  => (bool) $row['requires_email'],
            'download_count'  => (int) $row['download_count'],
            'is_active'       => (bool) $row['is_active'],
        ];
    }
}
