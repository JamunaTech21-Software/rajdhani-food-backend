<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PDOException;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\RichText;
use Rajdhani\Helpers\SlugHelper;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * News posts — scheduled publishing, tags, prev/next navigation (doc §8.7,
 * §9.5, §10.4; RTPP-26).
 *
 * **Publishing is a status plus a timestamp, not just a status.** Setting
 * `status: PUBLISHED` with no `published_at` publishes immediately —
 * `effectivePublishedAt()` defaults it to "now" the moment the *effective*
 * status (whichever this call sends, or the stored one on a partial update)
 * resolves to `PUBLISHED` and no timestamp is otherwise available, the same
 * "effective value" reasoning `BannerService`/`ProcessStepService` apply to
 * their own paired fields. Giving an explicit future `published_at` instead
 * schedules it — `NewsRepository`'s visibility filter is what actually
 * enforces the DoD ("invisible publicly until that date"), computed at query
 * time, no job required.
 *
 * `content` is this module's rich-text field (`MEDIUMTEXT`, schema-commented
 * as such) — sanitised through `RichText::sanitize()` before storage, same
 * treatment as `PageBlockService`'s `body`. `excerpt` and `meta_description`
 * are plain `TEXT`, not commented as rich text, so they are validated as
 * plain strings — the same "rich text means the one field named as such"
 * reading RTPP-24 already established.
 *
 * `author_id` is never client-supplied: it is stamped from the authenticated
 * admin at creation and never changes after, so a post cannot be attributed
 * to an admin other than whoever actually wrote it through this API.
 */
final class NewsService
{
    use ValidatesInput;

    public function __construct(
        private readonly NewsRepository $posts = new NewsRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $status = $this->optionalStatusFilter($query);
        $search = is_string($query['search'] ?? null) ? trim($query['search']) : null;
        $tag = is_string($query['tag'] ?? null) && $query['tag'] !== '' ? $query['tag'] : null;

        $rows = $this->posts->paginate($pagination->limit, $pagination->offset(), $status, $search, $tag);
        $total = $this->posts->count($status, $search, $tag);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->adminView($this->requirePost($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(string $authorId, array $input): array
    {
        $fields = $this->coreFields($input, existing: null, partial: false);
        $fields['author_id'] = $authorId;
        $fields['published_at'] = $this->effectivePublishedAt($fields, existing: null);

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
        $existing = $this->requirePost($id);
        $fields = $this->coreFields($input, existing: $existing, partial: true);

        if (array_key_exists('status', $fields) || array_key_exists('published_at', $fields)) {
            $fields['published_at'] = $this->effectivePublishedAt($fields, $existing);
        }

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
        // Idempotent soft delete, same reasoning as every other module in
        // this codebase with a `deleted_at` column.
        $this->posts->softDelete($id);
    }

    /**
     * `GET /public/news` (doc §9.5).
     *
     * @param array<string,mixed> $query
     *
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    public function publicList(array $query): array
    {
        $pagination = Pagination::fromQuery($query);
        $tag = is_string($query['tag'] ?? null) && $query['tag'] !== '' ? $query['tag'] : null;

        $rows = $this->posts->publicPaginate($pagination->limit, $pagination->offset(), $tag);
        $total = $this->posts->publicCount($tag);

        return [
            'data' => array_map($this->publicCardView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /**
     * `GET /public/news/featured` (doc §9.5, §10.1) — the home "Latest Updates" band.
     *
     * @return list<array<string,mixed>>
     */
    public function publicFeatured(): array
    {
        return array_map($this->publicCardView(...), $this->posts->publicFeatured());
    }

    /**
     * `GET /public/news/{slug}` (doc §9.5): the full article, with prev/next
     * navigation and an incremented view count. A slug that exists but is not
     * currently visible (`DRAFT`, `ARCHIVED`, or scheduled for later) 404s
     * exactly like one that never existed — see the repository class doc.
     *
     * @return array<string,mixed>
     */
    public function publicShow(string $slug): array
    {
        $post = $this->posts->findPublicBySlug($slug);

        if ($post === null) {
            throw ApiError::notFound('No such news post');
        }

        $this->posts->incrementViewCount((string) $post['id']);
        $post['view_count'] = (int) $post['view_count'] + 1;

        $publishedAt = (string) $post['published_at'];
        $id = (string) $post['id'];

        return $this->publicDetailView($post, $this->posts->previous($publishedAt, $id), $this->posts->next($publishedAt, $id));
    }

    /**
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, ?array $existing, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('title')) {
            $fields['title'] = $this->requiredText($input, 'title', 255);
        }

        if ($has('slug')) {
            $fields['slug'] = $this->resolveSlug($input, $fields['title'] ?? null, excludingId: $existing['id'] ?? null);
        }

        if ($has('excerpt')) {
            $fields['excerpt'] = $this->optionalText($input, 'excerpt', 65535);
        }

        if ($has('content')) {
            $fields['content'] = $this->requiredRichText($input, 'content');
        }

        if ($has('cover_image_id')) {
            $fields['cover_image_id'] = $this->optionalMediaRef($input, 'cover_image_id');
        }

        if ($has('tags')) {
            $fields['tags'] = $this->optionalTagList($input);
        }

        if ($has('status')) {
            $fields['status'] = $this->optionalEnum($input, 'status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'], 'DRAFT');
        }

        if ($has('is_featured')) {
            $fields['is_featured'] = $this->optionalBool($input, 'is_featured', false);
        }

        if ($has('published_at')) {
            $fields['published_at'] = $this->optionalDateTime($input, 'published_at');
        }

        if ($has('meta_title')) {
            $fields['meta_title'] = $this->optionalText($input, 'meta_title', 255);
        }

        if ($has('meta_description')) {
            $fields['meta_description'] = $this->optionalText($input, 'meta_description', 65535);
        }

        return $fields;
    }

    /**
     * If, after this call, the post's status resolves to `PUBLISHED` and no
     * `published_at` is otherwise available, default it to now — "publish"
     * with no date picked means "publish now," not "publish with no date."
     * Anything else — an explicit `published_at` (present or future), or a
     * non-`PUBLISHED` status — passes through untouched.
     *
     * @param array<string,scalar|null> $fields
     * @param array<string,mixed>|null  $existing
     */
    private function effectivePublishedAt(array $fields, ?array $existing): ?string
    {
        $effectiveStatus = $fields['status'] ?? ($existing['status'] ?? 'DRAFT');
        $effectivePublishedAt = array_key_exists('published_at', $fields)
            ? $fields['published_at']
            : ($existing['published_at'] ?? null);

        if ($effectiveStatus === 'PUBLISHED' && $effectivePublishedAt === null) {
            return $this->nowDateTime();
        }

        return $effectivePublishedAt;
    }

    /** @param array<string,mixed> $input */
    private function resolveSlug(array $input, ?string $fallbackTitle, ?string $excludingId): string
    {
        $explicitSlug = isset($input['slug']) && is_scalar($input['slug']) && trim((string) $input['slug']) !== '';

        if ($explicitSlug) {
            $slug = SlugHelper::make((string) $input['slug']);

            if ($this->posts->slugExists($slug, $excludingId)) {
                throw ApiError::conflict("The slug '{$slug}' is already in use", [
                    ['field' => 'slug', 'message' => 'This slug is already in use'],
                ]);
            }

            return $slug;
        }

        if ($fallbackTitle === null) {
            throw $this->invalid('slug', 'This field is required');
        }

        return SlugHelper::unique(
            SlugHelper::make($fallbackTitle),
            fn (string $candidate): bool => $this->posts->slugExists($candidate),
        );
    }

    /** @param array<string,mixed> $input */
    private function requiredRichText(array $input, string $field): string
    {
        $value = $input[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw $this->invalid($field, 'This field is required');
        }

        $sanitized = RichText::sanitize($value);

        if ($sanitized === null) {
            throw $this->invalid($field, 'Must contain visible content once disallowed markup is removed');
        }

        return $sanitized;
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->posts->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
    }

    /** @param array<string,mixed> $input */
    private function optionalTagList(array $input): ?string
    {
        $value = $input['tags'] ?? null;

        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value)) {
            throw $this->invalid('tags', 'Expected a list of strings');
        }

        $tags = [];

        foreach ($value as $tag) {
            if (!is_scalar($tag) || trim((string) $tag) === '') {
                throw $this->invalid('tags', 'Every entry must be a non-empty string');
            }

            $tags[] = trim((string) $tag);
        }

        return json_encode(array_values(array_unique($tags)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Accepts anything `DateTimeImmutable` can parse, reformatted to this
     * schema's `DATETIME(3)` shape — identical to `BannerService`'s own
     * copy, duplicated rather than shared for the same reason that one gives:
     * a small, low-risk-of-divergence check, not the kind of subtle
     * correctness logic `HandlesTransactions` was worth extracting for.
     *
     * @param array<string,mixed> $input
     */
    private function optionalDateTime(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw $this->invalid($field, 'Expected a date-time string');
        }

        try {
            $parsed = new DateTimeImmutable($value);
        } catch (Exception) {
            throw $this->invalid($field, 'Not a valid date-time');
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function nowDateTime(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
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

    /** @param array<string,scalar|null> $fields */
    private function insertHandlingRace(array $fields): string
    {
        try {
            return $this->posts->create($fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function updateHandlingRace(string $id, array $fields): void
    {
        try {
            $this->posts->update($id, $fields);
        } catch (PDOException $e) {
            throw $this->translateRace($e, $fields);
        }
    }

    /** @param array<string,scalar|null> $fields */
    private function translateRace(PDOException $e, array $fields): ApiError
    {
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_news_posts_slug')) {
            $slug = $fields['slug'] ?? '';

            return ApiError::conflict("The slug '{$slug}' is already in use", [
                ['field' => 'slug', 'message' => 'This slug is already in use'],
            ]);
        }

        throw $e;
    }

    /** @return array<string,mixed> */
    private function requirePost(string $id): array
    {
        $post = $this->posts->find($id);

        if ($post === null) {
            throw ApiError::notFound('No such news post');
        }

        return $post;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'               => (string) $row['id'],
            'title'            => (string) $row['title'],
            'slug'             => (string) $row['slug'],
            'excerpt'          => $row['excerpt'] === null ? null : (string) $row['excerpt'],
            'content'          => (string) $row['content'],
            'cover_image_id'   => $row['cover_image_id'] === null ? null : (string) $row['cover_image_id'],
            'tags'             => $row['tags'] === null ? null : json_decode((string) $row['tags'], true, 512, JSON_THROW_ON_ERROR),
            'status'           => (string) $row['status'],
            'is_featured'      => (int) $row['is_featured'] === 1,
            'published_at'     => $row['published_at'] === null ? null : (string) $row['published_at'],
            'view_count'       => (int) $row['view_count'],
            'author_id'        => $row['author_id'] === null ? null : (string) $row['author_id'],
            'meta_title'       => $row['meta_title'] === null ? null : (string) $row['meta_title'],
            'meta_description' => $row['meta_description'] === null ? null : (string) $row['meta_description'],
            'created_at'       => (string) $row['created_at'],
            'updated_at'       => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row as `NewsRepository::publicPaginate()`/`publicFeatured()` return
     *
     * @return array<string,mixed>
     */
    private function publicCardView(array $row): array
    {
        $hasCover = is_string($row['cover_image_url']);

        return [
            'title'        => (string) $row['title'],
            'slug'         => (string) $row['slug'],
            'excerpt'      => $row['excerpt'] === null ? null : (string) $row['excerpt'],
            'published_at' => (string) $row['published_at'],
            'cover_image'  => $hasCover ? [
                'url' => (string) $row['cover_image_url'],
                'alt' => $row['cover_image_alt'] === null ? null : (string) $row['cover_image_alt'],
            ] : null,
        ];
    }

    /**
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed>|null $next
     *
     * @return array<string,mixed>
     */
    private function publicDetailView(array $row, ?array $previous, ?array $next): array
    {
        $hasCover = is_string($row['cover_image_url']);

        return [
            'title'        => (string) $row['title'],
            'slug'         => (string) $row['slug'],
            'excerpt'      => $row['excerpt'] === null ? null : (string) $row['excerpt'],
            'content'      => (string) $row['content'],
            'tags'         => $row['tags'] === null ? null : json_decode((string) $row['tags'], true, 512, JSON_THROW_ON_ERROR),
            'published_at' => (string) $row['published_at'],
            'view_count'   => (int) $row['view_count'],
            'author_name'  => $row['author_name'] === null ? null : (string) $row['author_name'],
            'cover_image'  => $hasCover ? [
                'url' => (string) $row['cover_image_url'],
                'alt' => $row['cover_image_alt'] === null ? null : (string) $row['cover_image_alt'],
            ] : null,
            'previous' => $previous === null ? null : ['slug' => (string) $previous['slug'], 'title' => (string) $previous['title']],
            'next'     => $next === null ? null : ['slug' => (string) $next['slug'], 'title' => (string) $next['title']],
        ];
    }
}
