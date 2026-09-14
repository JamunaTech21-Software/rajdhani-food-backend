<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `news_posts` (doc §8.7, §9.5, §9.9; RTPP-26).
 *
 * The slug is globally unique — no brand to scope it by (doc §6), same
 * reasoning as `CategoryRepository`. `author_id` has a real foreign key onto
 * `admin_users` (one of the constraints the v3.0 single-site cut made
 * *stronger*, doc §6.2) but is nullable, so a post whose author is later
 * deleted keeps existing rather than being orphaned by the schema.
 *
 * Public visibility is `status = 'PUBLISHED' AND published_at <= NOW()` —
 * there is no upper bound the way a banner's `ends_at` is one; an article
 * does not expire on its own, only `ARCHIVED` retires it. A post scheduled
 * for the future sits in the table with `status = 'PUBLISHED'` already and
 * simply is not visible yet — no job flips it live, the query does, the
 * moment the clock passes `published_at` (the DoD, literally).
 *
 * Soft delete via `deleted_at`, same as every other content table with the
 * column.
 */
final class NewsRepository extends Repository
{
    private const ADMIN_COLUMNS = 'id, title, slug, excerpt, content, cover_image_id, tags,
                                   status, is_featured, published_at, view_count, author_id,
                                   meta_title, meta_description, created_at, updated_at, deleted_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $status, ?string $search, ?string $tag): array
    {
        [$where, $parameters] = $this->adminFilter($status, $search, $tag);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::ADMIN_COLUMNS . " FROM news_posts {$where}
              ORDER BY created_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $status, ?string $search, ?string $tag): int
    {
        [$where, $parameters] = $this->adminFilter($status, $search, $tag);

        return (int) $this->scalar("SELECT COUNT(*) FROM news_posts {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::ADMIN_COLUMNS . ' FROM news_posts WHERE id = :id AND deleted_at IS NULL',
            [':id' => $id],
        );
    }

    /**
     * Every published post's slug and last-modified time — `sitemap.xml`
     * (doc §14.3; RTPP-36). Same visibility rule as `publicFeatured()`:
     * published and already at or past its publish time.
     *
     * @return list<array{slug:string,updated_at:string}>
     */
    public function publishedSlugs(): array
    {
        /** @var list<array{slug:string,updated_at:string}> */
        return $this->all(
            "SELECT slug, updated_at FROM news_posts
              WHERE deleted_at IS NULL AND status = 'PUBLISHED' AND published_at <= :now
              ORDER BY slug",
            [':now' => $this->now()],
        );
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /** @param string|null $excludingId the post's own current slug must not count as a collision with itself */
    public function slugExists(string $slug, ?string $excludingId = null): bool
    {
        $sql = 'SELECT id FROM news_posts WHERE slug = :slug';
        $parameters = [':slug' => $slug];

        if ($excludingId !== null) {
            $sql .= ' AND id != :excluding';
            $parameters[':excluding'] = $excludingId;
        }

        return $this->scalar($sql . ' LIMIT 1', $parameters) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO news_posts (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @param array<string,scalar|null> $fields */
    public function update(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id, ':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run(
            'UPDATE news_posts SET ' . implode(', ', $assignments) . ', updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            $parameters,
        );
    }

    /** Idempotent, like every other soft delete in this codebase. */
    public function softDelete(string $id): int
    {
        $now = $this->now();

        return $this->run(
            'UPDATE news_posts SET deleted_at = :deleted_at, updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            [':deleted_at' => $now, ':updated_at' => $now, ':id' => $id],
        );
    }

    /**
     * `GET /public/news` (doc §9.5): visible posts only, newest-published
     * first, optionally filtered to one tag. This same ordering —
     * `published_at DESC, id DESC` — is the one strict total order
     * `previous()`/`next()` navigate against, so a post's neighbours here are
     * exactly its neighbours on the listing page.
     *
     * @return list<array<string,mixed>>
     */
    public function publicPaginate(int $limit, int $offset, ?string $tag): array
    {
        [$where, $parameters] = $this->publicFilter($tag);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            "SELECT {$this->publicColumns()}
               FROM news_posts p
               LEFT JOIN media_assets m ON m.id = p.cover_image_id
              {$where}
              ORDER BY p.published_at DESC, p.id DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function publicCount(?string $tag): int
    {
        [$where, $parameters] = $this->publicFilter($tag);

        return (int) $this->scalar("SELECT COUNT(*) FROM news_posts p {$where}", $parameters);
    }

    /**
     * `GET /public/news/featured` (doc §9.5, §10.1): the home "Latest
     * Updates" band. Simply the three most recent visible posts — `is_featured`
     * is an admin-settable flag with no bearing on this endpoint; the doc
     * names "three most recent published," not "three flagged as featured."
     *
     * @return list<array<string,mixed>>
     */
    public function publicFeatured(): array
    {
        [$where, $parameters] = $this->publicFilter(null);

        return $this->all(
            "SELECT {$this->publicColumns()}
               FROM news_posts p
               LEFT JOIN media_assets m ON m.id = p.cover_image_id
              {$where}
              ORDER BY p.published_at DESC, p.id DESC
              LIMIT 3",
            $parameters,
        );
    }

    /**
     * `GET /public/news/{slug}` (doc §9.5): a `DRAFT`/`ARCHIVED`/not-yet-visible
     * post's slug resolves to nothing here, the same convention as a product's
     * slug (`ProductRepository::findBySlug()`) — the public API never reveals
     * which of "never existed" and "not public yet" applies.
     *
     * @return array<string,mixed>|null
     */
    public function findPublicBySlug(string $slug): ?array
    {
        [$where, $parameters] = $this->publicFilter(null);
        $parameters[':slug'] = $slug;

        return $this->one(
            "SELECT {$this->publicColumns()}, a.name AS author_name
               FROM news_posts p
               LEFT JOIN media_assets m ON m.id = p.cover_image_id
               LEFT JOIN admin_users a ON a.id = p.author_id
              {$where} AND p.slug = :slug",
            $parameters,
        );
    }

    public function incrementViewCount(string $id): void
    {
        $this->run('UPDATE news_posts SET view_count = view_count + 1 WHERE id = :id', [':id' => $id]);
    }

    /**
     * The visible post immediately before this one in the listing order —
     * see the class doc on `publicPaginate()` for what "before" means here.
     *
     * @return array<string,mixed>|null
     */
    public function previous(string $publishedAt, string $id): ?array
    {
        [$where, $parameters] = $this->publicFilter(null);
        $parameters[':published_at'] = $publishedAt;
        $parameters[':id'] = $id;

        return $this->one(
            "SELECT p.slug, p.title
               FROM news_posts p
              {$where} AND (p.published_at, p.id) < (:published_at, :id)
              ORDER BY p.published_at DESC, p.id DESC
              LIMIT 1",
            $parameters,
        );
    }

    /** @return array<string,mixed>|null */
    public function next(string $publishedAt, string $id): ?array
    {
        [$where, $parameters] = $this->publicFilter(null);
        $parameters[':published_at'] = $publishedAt;
        $parameters[':id'] = $id;

        return $this->one(
            "SELECT p.slug, p.title
               FROM news_posts p
              {$where} AND (p.published_at, p.id) > (:published_at, :id)
              ORDER BY p.published_at ASC, p.id ASC
              LIMIT 1",
            $parameters,
        );
    }

    private function publicColumns(): string
    {
        return 'p.id, p.title, p.slug, p.excerpt, p.content, p.tags, p.published_at, p.view_count,
                m.secure_url AS cover_image_url, m.alt_text AS cover_image_alt';
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function publicFilter(?string $tag): array
    {
        $where = "WHERE p.deleted_at IS NULL AND p.status = 'PUBLISHED' AND p.published_at <= :now";
        $parameters = [':now' => $this->now()];

        if ($tag !== null) {
            $where .= ' AND JSON_CONTAINS(p.tags, :tag)';
            $parameters[':tag'] = json_encode($tag, JSON_THROW_ON_ERROR);
        }

        return [$where, $parameters];
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function adminFilter(?string $status, ?string $search, ?string $tag): array
    {
        $where = 'WHERE deleted_at IS NULL';
        $parameters = [];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $parameters[':status'] = $status;
        }

        if ($search !== null && $search !== '') {
            $where .= ' AND title LIKE :search';
            $parameters[':search'] = '%' . $this->escapeLike($search) . '%';
        }

        if ($tag !== null) {
            $where .= ' AND JSON_CONTAINS(tags, :tag)';
            $parameters[':tag'] = json_encode($tag, JSON_THROW_ON_ERROR);
        }

        return [$where, $parameters];
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
