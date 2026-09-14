<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `banners` (doc §8.7, §10.1, §12; RTPP-23).
 *
 * `sort_order` is only ever meaningful *within* a placement — a `HOME_HERO`
 * slider and a `SIDEBAR_AD` slot never render in the same list, so nothing
 * here enforces it to be globally unique. `reorder()` reflects that: it is
 * scoped to one placement per call, not a bare list of ids the way
 * `CategoryRepository::reorder()` is, because an id that has drifted to a
 * different placement since the admin screen loaded should be reported as
 * missing, not silently reassigned a position in the wrong slider.
 *
 * Soft delete only, same reasoning as categories and products: nothing here
 * needs a hard `DELETE`, and a restore is one `deleted_at = NULL` away if a
 * future ticket ever wants one.
 */
final class BannerRepository extends Repository
{
    private const COLUMNS = 'id, placement, title, title_highlight, subtitle, eyebrow_text,
                             desktop_image_id, mobile_image_id, video_url,
                             primary_cta_label, primary_cta_url, secondary_cta_label, secondary_cta_url,
                             overlay_opacity, sort_order, status, starts_at, ends_at,
                             created_at, updated_at, deleted_at';

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?string $placement, ?string $status): array
    {
        [$where, $parameters] = $this->listFilter($placement, $status);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM banners {$where}
              ORDER BY placement, sort_order
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?string $placement, ?string $status): int
    {
        [$where, $parameters] = $this->listFilter($placement, $status);

        return (int) $this->scalar("SELECT COUNT(*) FROM banners {$where}", $parameters);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM banners WHERE id = :id AND deleted_at IS NULL',
            [':id' => $id],
        );
    }

    public function mediaAssetExists(string $mediaId): bool
    {
        return $this->scalar('SELECT id FROM media_assets WHERE id = :id', [':id' => $mediaId]) !== null;
    }

    /** @param array<string,scalar|null> $fields */
    public function create(array $fields): string
    {
        $id = UlidHelper::generate();
        $now = $this->now();

        $row = $fields + ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];
        $columns = array_keys($row);

        $this->run(
            'INSERT INTO banners (' . implode(', ', array_map($this->quote(...), $columns)) . ')
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
            'UPDATE banners SET ' . implode(', ', $assignments) . ', updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            $parameters,
        );
    }

    /** Idempotent, like every other soft delete in this codebase. */
    public function softDelete(string $id): int
    {
        $now = $this->now();

        return $this->run(
            'UPDATE banners SET deleted_at = :deleted_at, updated_at = :updated_at
              WHERE id = :id AND deleted_at IS NULL',
            [':deleted_at' => $now, ':updated_at' => $now, ':id' => $id],
        );
    }

    /**
     * Scoped to one placement: the `WHERE placement = :placement` on every
     * row's `UPDATE` means an id belonging to a different placement affects
     * zero rows and comes back as missing, the same way an id that does not
     * exist at all does — see the class doc for why that is the right
     * behaviour here rather than reassigning it anyway.
     *
     * @param list<string> $orderedIds
     *
     * @return list<string> ids that do not exist, are deleted, or do not belong to this placement
     */
    public function reorder(string $placement, array $orderedIds): array
    {
        $missing = [];
        $position = 0;

        foreach ($orderedIds as $id) {
            $position++;
            $affected = $this->run(
                'UPDATE banners SET sort_order = :position, updated_at = :now
                  WHERE id = :id AND placement = :placement AND deleted_at IS NULL',
                [':position' => $position, ':now' => $this->now(), ':id' => $id, ':placement' => $placement],
            );

            if ($affected === 0) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * `GET /public/banners?placement=...` (doc §9.4-adjacent, §10.1): active,
     * in-window banners for one placement, in slider order. "In window" is
     * two independently-optional bounds — a banner with a `starts_at` but no
     * `ends_at` runs forever once it starts, and vice versa — so both are
     * `NULL`-or-satisfied checks, not a `BETWEEN`.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(string $placement): array
    {
        $now = $this->now();

        return $this->all(
            "SELECT {$this->publicColumns()}
               FROM banners b
               LEFT JOIN media_assets d ON d.id = b.desktop_image_id
               LEFT JOIN media_assets m ON m.id = b.mobile_image_id
              WHERE b.deleted_at IS NULL
                AND b.status = 'PUBLISHED'
                AND b.placement = :placement
                AND (b.starts_at IS NULL OR b.starts_at <= :now_start)
                AND (b.ends_at IS NULL OR b.ends_at >= :now_end)
              ORDER BY b.sort_order, b.created_at",
            [':placement' => $placement, ':now_start' => $now, ':now_end' => $now],
        );
    }

    private function publicColumns(): string
    {
        return 'b.id, b.placement, b.title, b.title_highlight, b.subtitle, b.eyebrow_text,
                b.video_url, b.primary_cta_label, b.primary_cta_url,
                b.secondary_cta_label, b.secondary_cta_url, b.overlay_opacity, b.sort_order,
                d.secure_url AS desktop_image_url, d.alt_text AS desktop_image_alt,
                m.secure_url AS mobile_image_url, m.alt_text AS mobile_image_alt';
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function listFilter(?string $placement, ?string $status): array
    {
        $where = 'WHERE deleted_at IS NULL';
        $parameters = [];

        if ($placement !== null) {
            $where .= ' AND placement = :placement';
            $parameters[':placement'] = $placement;
        }

        if ($status !== null) {
            $where .= ' AND status = :status';
            $parameters[':status'] = $status;
        }

        return [$where, $parameters];
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
