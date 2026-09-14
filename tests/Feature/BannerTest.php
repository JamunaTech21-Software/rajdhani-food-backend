<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Services\BannerService;

/**
 * Admin banner CRUD, scheduling validation, and placement-scoped reorder
 * (doc §8.7, §10.1, §12; RTPP-23), against a real database.
 */
final class BannerTest extends DatabaseTestCase
{
    private BannerService $banners;
    private BannerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BannerRepository($this->db);
        $this->banners = new BannerService($this->repository);
    }

    // ─── create ─────────────────────────────────────────────────────────────

    public function testCreatingABannerDefaultsStatusToPublishedNotDraft(): void
    {
        $banner = $this->banners->create(['placement' => 'MID_PAGE_CTA']);

        self::assertSame('PUBLISHED', $banner['status']);
        self::assertSame(0, $banner['sort_order']);
        self::assertNull($banner['starts_at']);
        self::assertNull($banner['ends_at']);
    }

    public function testAnInvalidPlacementIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->create(['placement' => 'NOT_A_REAL_PLACEMENT']));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertSame('placement', $error->details()[0]['field']);
    }

    public function testOverlayOpacityMustBeWithinZeroToHundred(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'overlay_opacity' => 150,
        ]));

        self::assertSame('overlay_opacity', $error->details()[0]['field']);
    }

    public function testDesktopImageMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'desktop_image_id' => UlidHelper::generate(),
        ]));

        self::assertSame('desktop_image_id', $error->details()[0]['field']);
    }

    public function testAValidDesktopImageIsAccepted(): void
    {
        $media = $this->insertMediaAsset();

        $banner = $this->banners->create(['placement' => 'MID_PAGE_CTA', 'desktop_image_id' => $media]);

        self::assertSame($media, $banner['desktop_image_id']);
    }

    // ─── schedule window ─────────────────────────────────────────────────────

    public function testAScheduleWindowWhereEndsBeforeStartsIsRejectedOnCreate(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->create([
            'placement' => 'MID_PAGE_CTA',
            'starts_at' => '2026-06-10T00:00:00Z',
            'ends_at'   => '2026-06-01T00:00:00Z',
        ]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertSame('ends_at', $error->details()[0]['field']);
    }

    public function testAValidScheduleWindowIsAccepted(): void
    {
        $banner = $this->banners->create([
            'placement' => 'MID_PAGE_CTA',
            'starts_at' => '2026-06-01T00:00:00Z',
            'ends_at'   => '2026-06-10T00:00:00Z',
        ]);

        self::assertStringStartsWith('2026-06-01', $banner['starts_at']);
        self::assertStringStartsWith('2026-06-10', $banner['ends_at']);
    }

    /**
     * A partial update touching only one side of the window has to be
     * checked against the *stored* other side, not treated as if the
     * untouched side were absent — the same class of bug RTPP-19 found in
     * ProductService's discount recomputation.
     */
    public function testAPartialUpdateValidatesAgainstTheStoredOtherSide(): void
    {
        $banner = $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'starts_at' => '2026-06-10T00:00:00Z',
        ]);

        $error = $this->captureApiError(
            fn () => $this->banners->update($banner['id'], ['ends_at' => '2026-06-01T00:00:00Z'])
        );

        self::assertSame('ends_at', $error->details()[0]['field']);
    }

    public function testAPartialUpdateThatKeepsTheWindowValidSucceeds(): void
    {
        $banner = $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'starts_at' => '2026-06-01T00:00:00Z',
        ]);

        $updated = $this->banners->update($banner['id'], ['ends_at' => '2026-06-10T00:00:00Z']);

        self::assertStringStartsWith('2026-06-10', $updated['ends_at']);
    }

    public function testAMalformedDateTimeIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'starts_at' => 'not-a-date',
        ]));

        self::assertSame('starts_at', $error->details()[0]['field']);
    }

    // ─── update / delete ─────────────────────────────────────────────────────

    public function testUpdatingWithNoFieldsIsRejected(): void
    {
        $banner = $this->banners->create(['placement' => 'MID_PAGE_CTA']);

        $error = $this->captureApiError(fn () => $this->banners->update($banner['id'], []));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testDeletingIsIdempotent(): void
    {
        $banner = $this->banners->create(['placement' => 'MID_PAGE_CTA']);

        $this->banners->delete($banner['id']);
        $this->banners->delete($banner['id']);

        $error = $this->captureApiError(fn () => $this->banners->find($banner['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testDeletingANonExistentBannerIsNotAnError(): void
    {
        $this->banners->delete(UlidHelper::generate());

        self::assertTrue(true);
    }

    // ─── reorder ─────────────────────────────────────────────────────────────

    public function testReorderAppliesPositionsWithinThePlacement(): void
    {
        $a = $this->banners->create(['placement' => 'MID_PAGE_CTA']);
        $b = $this->banners->create(['placement' => 'MID_PAGE_CTA']);

        $this->banners->reorder(['placement' => 'MID_PAGE_CTA', 'ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->banners->find($b['id'])['sort_order']);
        self::assertSame(2, $this->banners->find($a['id'])['sort_order']);
    }

    /**
     * A banner from a different placement must not be silently reassigned a
     * position in this one's slider — it is reported as not belonging, the
     * same way a nonexistent id would be.
     */
    public function testReorderRejectsAnIdFromADifferentPlacement(): void
    {
        $home = $this->banners->create(['placement' => 'MID_PAGE_CTA']);
        $sidebar = $this->banners->create(['placement' => 'SIDEBAR_AD']);

        $error = $this->captureApiError(
            fn () => $this->banners->reorder(['placement' => 'MID_PAGE_CTA', 'ids' => [$home['id'], $sidebar['id']]])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertStringContainsString($sidebar['id'], $error->details()[0]['message']);

        // Partial application already happened for the valid id — matching
        // CategoryService::reorder()'s documented behaviour for the same case.
        self::assertSame(1, $this->banners->find($home['id'])['sort_order']);
    }

    // ─── pagination / filtering ──────────────────────────────────────────────

    public function testPaginationFiltersByPlacementAndStatus(): void
    {
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'status' => 'DRAFT']);
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'status' => 'PUBLISHED']);
        $this->banners->create(['placement' => 'SIDEBAR_AD', 'status' => 'PUBLISHED']);

        $result = $this->banners->paginate(['placement' => 'MID_PAGE_CTA', 'status' => 'PUBLISHED']);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame('MID_PAGE_CTA', $result['data'][0]['placement']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $statement = $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :now)'
        );
        $statement->execute([
            ':id'        => $id,
            ':public_id' => 'test/' . bin2hex(random_bytes(6)),
            ':url'       => 'https://example.test/img.jpg',
            ':now'       => $this->now(),
        ]);

        return $id;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    private function captureApiError(callable $action): ApiError
    {
        try {
            $action();
        } catch (ApiError $e) {
            return $e;
        }

        self::fail('Expected an ApiError, none was thrown.');
    }
}
