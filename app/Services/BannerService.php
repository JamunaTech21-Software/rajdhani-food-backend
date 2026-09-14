<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\Pagination;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * Banners — placements, scheduling, ordering (doc §8.7, §10.1, §12; RTPP-23).
 *
 * A flat CRUD-plus-reorder module, no child collections, but two things make
 * it more than a copy of `CategoryService`: `sort_order` is scoped to one
 * `placement` (see `BannerRepository`'s class doc), and every banner carries
 * an optional schedule window — `starts_at`/`ends_at` — that the public
 * listing enforces and the admin CRUD validates as a pair, not as two
 * independent fields, so `{"starts_at": "2026-01-10", "ends_at": "2026-01-01"}`
 * is rejected outright rather than silently creating a window that can never
 * be active.
 */
final class BannerService
{
    use ValidatesInput;

    private const PLACEMENTS = [
        'HOME_HERO', 'ABOUT_HERO', 'PRODUCTS_HERO', 'PRODUCT_DETAIL_HERO', 'QUALITY_HERO',
        'DEALER_HERO', 'GALLERY_HERO', 'NEWS_HERO', 'CONTACT_HERO', 'HOME_PROMO',
        'HOME_VIDEO_CARD', 'MID_PAGE_CTA', 'DEALER_CTA', 'SIDEBAR_AD',
    ];

    public function __construct(
        private readonly BannerRepository $banners = new BannerRepository(),
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
        $placement = $this->optionalPlacementFilter($query);
        $status = $this->optionalStatusFilter($query);

        $rows = $this->banners->paginate($pagination->limit, $pagination->offset(), $placement, $status);
        $total = $this->banners->count($placement, $status);

        return [
            'data' => array_map($this->adminView(...), $rows),
            'meta' => $pagination->meta($total),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->adminView($this->requireBanner($id));
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $fields = $this->coreFields($input, partial: false);
        $this->validateScheduleWindow($this->asNullableString($fields['starts_at'] ?? null), $this->asNullableString($fields['ends_at'] ?? null));

        $id = $this->banners->create($fields);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function update(string $id, array $input): array
    {
        $existing = $this->requireBanner($id);
        $fields = $this->coreFields($input, partial: true);

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        // A partial update touching only one side of the schedule window is
        // checked against the *stored* other side — the same reasoning
        // ProductService uses for a partial pack-size price update: the pair
        // that will actually end up in the row is what has to make sense,
        // not just whichever half this one call happened to send.
        $effectiveStart = array_key_exists('starts_at', $fields) ? $fields['starts_at'] : $existing['starts_at'];
        $effectiveEnd = array_key_exists('ends_at', $fields) ? $fields['ends_at'] : $existing['ends_at'];
        $this->validateScheduleWindow($this->asNullableString($effectiveStart), $this->asNullableString($effectiveEnd));

        $this->banners->update($id, $fields);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        // Idempotent, soft delete only — same reasoning as every other
        // module in this codebase: calling delete twice, or on an id that
        // never existed, reaches the same end state either way.
        $this->banners->softDelete($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function reorder(array $input): array
    {
        $placement = $this->requiredPlacement($input);
        $ids = $this->validateIdList($input, 'ids');

        $missing = $this->banners->reorder($placement, $ids);

        if ($missing !== []) {
            throw ApiError::validation('Some ids do not belong to this placement', array_map(
                static fn (string $id): array => ['field' => 'ids', 'message' => "Not a banner in this placement: {$id}"],
                $missing,
            ));
        }

        return ['reordered' => count($ids)];
    }

    /**
     * `GET /public/banners?placement=...` (doc §9.4-adjacent, §10.1).
     * `placement` is required — there is no "every placement at once" public
     * use case in scope, and a slider component always knows which one it is.
     *
     * @param array<string,mixed> $query
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(array $query): array
    {
        $placement = $this->requiredPlacement($query);

        return array_map($this->publicView(...), $this->banners->publicList($placement));
    }

    // ─── field validation ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,scalar|null>
     */
    private function coreFields(array $input, bool $partial): array
    {
        $fields = [];
        $has = static fn (string $key): bool => !$partial || array_key_exists($key, $input);

        if ($has('placement')) {
            $fields['placement'] = $this->requiredPlacement($input);
        }

        foreach ([
            'title' => 255, 'title_highlight' => 255, 'eyebrow_text' => 255,
            'primary_cta_label' => 128, 'secondary_cta_label' => 128,
            'primary_cta_url' => 255, 'secondary_cta_url' => 255, 'video_url' => 512,
        ] as $field => $max) {
            if ($has($field)) {
                $fields[$field] = $this->optionalText($input, $field, $max);
            }
        }

        if ($has('subtitle')) {
            $fields['subtitle'] = $this->optionalText($input, 'subtitle', 65535);
        }

        if ($has('desktop_image_id')) {
            $fields['desktop_image_id'] = $this->optionalMediaRef($input, 'desktop_image_id');
        }

        if ($has('mobile_image_id')) {
            $fields['mobile_image_id'] = $this->optionalMediaRef($input, 'mobile_image_id');
        }

        if ($has('overlay_opacity')) {
            $fields['overlay_opacity'] = $this->optionalOpacity($input);
        }

        if ($has('sort_order')) {
            $fields['sort_order'] = $this->optionalInt($input, 'sort_order', 0);
        }

        if ($has('status')) {
            // Banners default to PUBLISHED, not DRAFT — the schema's own
            // default (doc §8.7), and the right one here: an admin creating
            // a hero banner is almost always putting it live immediately,
            // unlike a product, which is drafted first while its content is
            // still being filled in.
            $fields['status'] = $this->optionalEnum($input, 'status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'], 'PUBLISHED');
        }

        if ($has('starts_at')) {
            $fields['starts_at'] = $this->optionalDateTime($input, 'starts_at');
        }

        if ($has('ends_at')) {
            $fields['ends_at'] = $this->optionalDateTime($input, 'ends_at');
        }

        return $fields;
    }

    /** @param array<string,mixed> $input */
    private function requiredPlacement(array $input): string
    {
        $value = $input['placement'] ?? null;

        if (!is_string($value) || !in_array($value, self::PLACEMENTS, true)) {
            throw $this->invalid('placement', 'Must be one of: ' . implode(', ', self::PLACEMENTS));
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalMediaRef(array $input, string $field): ?string
    {
        $id = $this->optionalUlid($input, $field);

        if ($id !== null && !$this->banners->mediaAssetExists($id)) {
            throw $this->invalid($field, 'No such media asset');
        }

        return $id;
    }

    /** @param array<string,mixed> $input */
    private function optionalOpacity(array $input): ?int
    {
        $value = $input['overlay_opacity'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw $this->invalid('overlay_opacity', 'Expected a number');
        }

        $opacity = (int) $value;

        if ($opacity < 0 || $opacity > 100) {
            throw $this->invalid('overlay_opacity', 'Must be between 0 and 100');
        }

        return $opacity;
    }

    /**
     * Accepts anything `DateTimeImmutable` can parse (ISO 8601 with an
     * offset, or without one — treated as UTC either way to match how every
     * other timestamp in this schema is stored, doc §8) and reformats to
     * this schema's `DATETIME(3)` shape so a lexicographic string comparison
     * (used by `validateScheduleWindow()` and by MySQL itself) agrees with
     * chronological order.
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

    private function asNullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function validateScheduleWindow(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw ApiError::validation('Some fields need attention', [
                ['field' => 'ends_at', 'message' => 'Must be after starts_at'],
            ]);
        }
    }

    /**
     * Same shape as `ProductService::validateIdList()` — not shared, on
     * purpose: this is a small, low-risk-of-divergence check ("is this a
     * non-empty list of valid, non-repeating ids"), unlike the SAVEPOINT
     * transaction logic that *was* worth extracting once two services needed
     * it, where a subtle divergence would have been a real correctness bug.
     *
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
                throw $this->invalid($field, 'Every entry must be a valid banner id');
            }

            $normalised[] = $id;
        }

        if (count(array_unique($normalised)) !== count($normalised)) {
            throw $this->invalid($field, 'Ids must not repeat');
        }

        return $normalised;
    }

    /** @param array<string,mixed> $query */
    private function optionalPlacementFilter(array $query): ?string
    {
        $value = $query['placement'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !in_array($value, self::PLACEMENTS, true)) {
            throw $this->invalid('placement', 'Must be one of: ' . implode(', ', self::PLACEMENTS));
        }

        return $value;
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

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireBanner(string $id): array
    {
        $banner = $this->banners->find($id);

        if ($banner === null) {
            throw ApiError::notFound('No such banner');
        }

        return $banner;
    }

    // ─── output shaping ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function adminView(array $row): array
    {
        return [
            'id'                  => (string) $row['id'],
            'placement'           => (string) $row['placement'],
            'title'               => $row['title'] === null ? null : (string) $row['title'],
            'title_highlight'     => $row['title_highlight'] === null ? null : (string) $row['title_highlight'],
            'subtitle'            => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'eyebrow_text'        => $row['eyebrow_text'] === null ? null : (string) $row['eyebrow_text'],
            'desktop_image_id'    => $row['desktop_image_id'] === null ? null : (string) $row['desktop_image_id'],
            'mobile_image_id'     => $row['mobile_image_id'] === null ? null : (string) $row['mobile_image_id'],
            'video_url'           => $row['video_url'] === null ? null : (string) $row['video_url'],
            'primary_cta_label'   => $row['primary_cta_label'] === null ? null : (string) $row['primary_cta_label'],
            'primary_cta_url'     => $row['primary_cta_url'] === null ? null : (string) $row['primary_cta_url'],
            'secondary_cta_label' => $row['secondary_cta_label'] === null ? null : (string) $row['secondary_cta_label'],
            'secondary_cta_url'   => $row['secondary_cta_url'] === null ? null : (string) $row['secondary_cta_url'],
            'overlay_opacity'     => $row['overlay_opacity'] === null ? null : (int) $row['overlay_opacity'],
            'sort_order'          => (int) $row['sort_order'],
            'status'              => (string) $row['status'],
            'starts_at'           => $row['starts_at'] === null ? null : (string) $row['starts_at'],
            'ends_at'             => $row['ends_at'] === null ? null : (string) $row['ends_at'],
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row as `BannerRepository::publicList()` returns
     *
     * @return array<string,mixed>
     */
    private function publicView(array $row): array
    {
        return [
            'id'                  => (string) $row['id'],
            'placement'           => (string) $row['placement'],
            'title'               => $row['title'] === null ? null : (string) $row['title'],
            'title_highlight'     => $row['title_highlight'] === null ? null : (string) $row['title_highlight'],
            'subtitle'            => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'eyebrow_text'        => $row['eyebrow_text'] === null ? null : (string) $row['eyebrow_text'],
            'video_url'           => $row['video_url'] === null ? null : (string) $row['video_url'],
            'primary_cta_label'   => $row['primary_cta_label'] === null ? null : (string) $row['primary_cta_label'],
            'primary_cta_url'     => $row['primary_cta_url'] === null ? null : (string) $row['primary_cta_url'],
            'secondary_cta_label' => $row['secondary_cta_label'] === null ? null : (string) $row['secondary_cta_label'],
            'secondary_cta_url'   => $row['secondary_cta_url'] === null ? null : (string) $row['secondary_cta_url'],
            'overlay_opacity'     => $row['overlay_opacity'] === null ? null : (int) $row['overlay_opacity'],
            'sort_order'          => (int) $row['sort_order'],
            'desktop_image'       => $row['desktop_image_url'] === null ? null : [
                'url' => (string) $row['desktop_image_url'],
                'alt' => $row['desktop_image_alt'] === null ? null : (string) $row['desktop_image_alt'],
            ],
            'mobile_image'        => $row['mobile_image_url'] === null ? null : [
                'url' => (string) $row['mobile_image_url'],
                'alt' => $row['mobile_image_alt'] === null ? null : (string) $row['mobile_image_alt'],
            ],
        ];
    }
}
