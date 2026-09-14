<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Services\BannerService;

/**
 * The public banner listing — schedule-window enforcement and placement
 * scoping (doc §9.4-adjacent, §10.1; RTPP-23), against a real database.
 *
 * `testABannerScheduledForTomorrowDoesNotAppearToday()` and
 * `testAnExpiredBannerDoesNotAppearAnymore()` are this ticket's DoD, verbatim.
 */
final class PublicBannerTest extends DatabaseTestCase
{
    private BannerService $banners;
    private BannerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BannerRepository($this->db);
        $this->banners = new BannerService($this->repository);
    }

    public function testOnlyPublishedBannersAppearPublicly(): void
    {
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'status' => 'DRAFT', 'title' => 'Draft Banner']);
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'status' => 'PUBLISHED', 'title' => 'Live Banner']);
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'status' => 'ARCHIVED', 'title' => 'Old Banner']);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertSame(['Live Banner'], $titles);
    }

    public function testABannerScheduledForTomorrowDoesNotAppearToday(): void
    {
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Future Banner', 'starts_at' => $tomorrow]);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertNotContains('Future Banner', $titles);
    }

    public function testAnExpiredBannerDoesNotAppearAnymore(): void
    {
        $yesterday = (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Expired Banner', 'ends_at' => $yesterday]);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertNotContains('Expired Banner', $titles);
    }

    public function testABannerWithNoScheduleBoundsAlwaysAppears(): void
    {
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Always On']);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertContains('Always On', $titles);
    }

    public function testABannerCurrentlyInsideItsWindowAppears(): void
    {
        $yesterday = (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $this->banners->create([
            'placement' => 'MID_PAGE_CTA', 'title' => 'Currently Live',
            'starts_at' => $yesterday, 'ends_at' => $tomorrow,
        ]);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertContains('Currently Live', $titles);
    }

    public function testFilteringByPlacementExcludesOtherPlacements(): void
    {
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Hero Banner']);
        $this->banners->create(['placement' => 'SIDEBAR_AD', 'title' => 'Sidebar Banner']);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertSame(['Hero Banner'], $titles);
    }

    public function testPublicListOrdersBySortOrder(): void
    {
        $second = $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Second', 'sort_order' => 2]);
        $first = $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'First', 'sort_order' => 1]);

        $titles = array_column($this->banners->publicList(['placement' => 'MID_PAGE_CTA']), 'title');

        self::assertSame(['First', 'Second'], $titles);
    }

    public function testPlacementIsRequiredOnThePublicRoute(): void
    {
        $error = $this->captureApiError(fn () => $this->banners->publicList([]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testPublicViewResolvesTheImageToAUrlNotJustAnId(): void
    {
        $media = $this->insertMediaAsset();
        $this->banners->create(['placement' => 'MID_PAGE_CTA', 'title' => 'Imaged', 'desktop_image_id' => $media]);

        $banner = $this->banners->publicList(['placement' => 'MID_PAGE_CTA'])[0];

        self::assertNotNull($banner['desktop_image']);
        self::assertSame('https://example.test/img.jpg', $banner['desktop_image']['url']);
        self::assertArrayNotHasKey('desktop_image_id', $banner);
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
