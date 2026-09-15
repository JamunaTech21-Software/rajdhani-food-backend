<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `media_assets` (doc §8.1, §12; RTPP-21, RTPP-22, RTPP-91).
 *
 * `hardDelete()` and `references()` are RTPP-22's: a real `DELETE`, not a
 * soft delete, because there is nothing to "undelete" once the underlying
 * Cloudinary asset is gone too, and `references()` is what makes that
 * `DELETE` safe to issue at all. `paginate()`/`count()`/`update()` are
 * RTPP-91's — the library's read/edit path, which had no caller until the
 * admin dashboard's Media Library screen (RTPP-50) needed one.
 */
final class MediaRepository extends Repository
{
    private const COLUMNS = 'id, public_id, secure_url, type, format, width, height, bytes,
                             folder, alt_text, caption, uploaded_by_id, created_at';

    /**
     * Every foreign key onto `media_assets.id` in the current schema (doc
     * §8, RTPP-22's corrected ticket text). Data, not one hand-written query
     * per table — the single place to update when a future migration adds a
     * new FK here, which the ticket text explicitly warns this list must not
     * be treated as a fixed, final enumeration.
     *
     * Deliberately counts every matching row regardless of the referencing
     * row's own status — a soft-deleted category still holds its `image_id`
     * (soft delete does not clear it), and soft delete elsewhere in this
     * codebase means "might come back", not "gone". Treating that reference
     * as no longer counting would let a restore bring back a category
     * pointing at an image this call had already let someone delete.
     *
     * @var list<array{table:string,column:string,label:string}>
     */
    private const REFERENCE_CHECKS = [
        ['table' => 'admin_users', 'column' => 'avatar_id', 'label' => 'admin user avatar(s)'],
        ['table' => 'categories', 'column' => 'image_id', 'label' => 'category image(s)'],
        ['table' => 'product_images', 'column' => 'media_id', 'label' => 'product image(s)'],
        ['table' => 'site_profile', 'column' => 'logo_light_id', 'label' => "the site's light-mode logo"],
        ['table' => 'site_profile', 'column' => 'logo_dark_id', 'label' => "the site's dark-mode logo"],
        ['table' => 'site_profile', 'column' => 'favicon_id', 'label' => 'the site favicon'],
        ['table' => 'site_profile', 'column' => 'og_image_id', 'label' => "the site's default social-share image"],
        ['table' => 'seo_meta', 'column' => 'og_image_id', 'label' => 'page social-share image(s)'],
        ['table' => 'gallery_categories', 'column' => 'cover_image_id', 'label' => 'gallery category cover(s)'],
        ['table' => 'gallery_images', 'column' => 'media_id', 'label' => 'gallery image(s)'],
        ['table' => 'news_posts', 'column' => 'cover_image_id', 'label' => 'news post cover image(s)'],
        ['table' => 'downloads', 'column' => 'file_id', 'label' => 'download file(s)'],
        ['table' => 'banners', 'column' => 'desktop_image_id', 'label' => 'banner desktop image(s)'],
        ['table' => 'banners', 'column' => 'mobile_image_id', 'label' => 'banner mobile image(s)'],
        ['table' => 'feature_items', 'column' => 'icon_image_id', 'label' => 'feature item icon(s)'],
        ['table' => 'process_steps', 'column' => 'image_id', 'label' => 'process step image(s)'],
        ['table' => 'certifications', 'column' => 'logo_id', 'label' => 'certification logo(s)'],
        ['table' => 'certifications', 'column' => 'certificate_file_id', 'label' => 'certification file(s)'],
        ['table' => 'testimonials', 'column' => 'avatar_id', 'label' => 'testimonial avatar(s)'],
        ['table' => 'page_blocks', 'column' => 'image_id', 'label' => 'page block image(s)'],
    ];

    public function publicIdExists(string $publicId): bool
    {
        return $this->scalar(
            'SELECT id FROM media_assets WHERE public_id = :public_id',
            [':public_id' => $publicId],
        ) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $row = $fields + ['id' => $id, 'created_at' => $this->now()];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO media_assets (' . implode(', ', array_map($this->quote(...), $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
            $row,
        );

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM media_assets WHERE id = :id', [':id' => $id]);
    }

    /**
     * The Media Library grid (doc §11, §12; RTPP-91) — newest first, so a
     * freshly uploaded asset is where an editor expects to find it without
     * paging.
     *
     * @return list<array<string,mixed>>
     */
    public function paginate(int $limit, int $offset, ?string $folder, ?string $type): array
    {
        [$where, $parameters] = $this->listFilter($folder, $type);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM media_assets {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $folder, ?string $type): int
    {
        [$where, $parameters] = $this->listFilter($folder, $type);

        return (int) $this->scalar("SELECT COUNT(*) FROM media_assets {$where}", $parameters);
    }

    /**
     * Alt text and caption only — every other column here describes what
     * Cloudinary actually holds (`public_id`, `bytes`, `width`, …) and
     * re-uploading is the only honest way to change any of that.
     *
     * @param array<string,scalar|null> $fields
     */
    public function update(string $id, array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':id' => $id];

        foreach ($fields as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $parameters[':' . $column] = $value;
        }

        return $this->run('UPDATE media_assets SET ' . implode(', ', $assignments) . ' WHERE id = :id', $parameters);
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $folder, ?string $type): array
    {
        $conditions = [];
        $parameters = [];

        if ($folder !== null) {
            $conditions[] = 'folder = :folder';
            $parameters[':folder'] = $folder;
        }

        if ($type !== null) {
            $conditions[] = 'type = :type';
            $parameters[':type'] = $type;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $parameters];
    }

    /**
     * Table and column names come from `self::REFERENCE_CHECKS`, a fixed
     * private constant — never from request input — so interpolating them
     * directly into the query carries no injection risk.
     *
     * @return list<array{table:string,count:int,label:string}> only the tables that actually reference this asset
     */
    public function references(string $mediaId): array
    {
        $found = [];

        foreach (self::REFERENCE_CHECKS as $check) {
            $count = (int) $this->scalar(
                "SELECT COUNT(*) FROM {$check['table']} WHERE {$check['column']} = :id",
                [':id' => $mediaId],
            );

            if ($count > 0) {
                $found[] = ['table' => $check['table'], 'count' => $count, 'label' => $check['label']];
            }
        }

        return $found;
    }

    public function hardDelete(string $id): int
    {
        return $this->run('DELETE FROM media_assets WHERE id = :id', [':id' => $id]);
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
