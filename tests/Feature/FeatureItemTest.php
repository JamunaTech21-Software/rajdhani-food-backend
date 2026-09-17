<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\FeatureItemRepository;
use Rajdhani\Services\FeatureItemService;

/**
 * Feature items — icon rows grouped by section (doc §8.7, §10.1; RTPP-24),
 * against a real database.
 *
 * The dev database carries real seeded rows for `HOME_USP` and
 * `DEALER_BENEFITS` — tests needing an exact result use an unseeded section
 * (`ABOUT_VALUES`), the same lesson RTPP-20's and RTPP-23's tests already
 * learned about this database.
 */
final class FeatureItemTest extends DatabaseTestCase
{
    private FeatureItemService $items;
    private FeatureItemRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new FeatureItemRepository($this->db);
        $this->items = new FeatureItemService($this->repository);
    }

    public function testCreatingAFeatureItem(): void
    {
        $item = $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'Integrity']);

        self::assertSame('ABOUT_VALUES', $item['section']);
        self::assertSame('Integrity', $item['title']);
        self::assertTrue($item['is_active']);
        self::assertSame(0, $item['sort_order']);
    }

    public function testAnInvalidSectionIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->items->create(['section' => 'NOT_REAL', 'title' => 'X']));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
        self::assertSame('section', $error->details()[0]['field']);
    }

    public function testATitleIsRequired(): void
    {
        $error = $this->captureApiError(fn () => $this->items->create(['section' => 'ABOUT_VALUES']));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testIconImageMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->items->create([
            'section' => 'ABOUT_VALUES', 'title' => 'X', 'icon_image_id' => UlidHelper::generate(),
        ]));

        self::assertSame('icon_image_id', $error->details()[0]['field']);
    }

    public function testDeletingActuallyRemovesTheRowNotJustMarksIt(): void
    {
        $item = $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'Temp']);

        $this->items->delete($item['id']);

        $error = $this->captureApiError(fn () => $this->items->find($item['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testDeletingANonExistentItemIsNotAnError(): void
    {
        $this->items->delete(UlidHelper::generate());

        self::assertTrue(true);
    }

    public function testReorderIsScopedToOneSection(): void
    {
        $a = $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'A']);
        $b = $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'B']);

        $this->items->reorder(['section' => 'ABOUT_VALUES', 'ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->items->find($b['id'])['sort_order']);
        self::assertSame(2, $this->items->find($a['id'])['sort_order']);
    }

    public function testReorderRejectsAnIdFromADifferentSection(): void
    {
        $inSection = $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'A']);
        $otherSection = $this->items->create(['section' => 'QUALITY_COMMITMENT', 'title' => 'B']);

        $error = $this->captureApiError(fn () => $this->items->reorder([
            'section' => 'ABOUT_VALUES', 'ids' => [$inSection['id'], $otherSection['id']],
        ]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testListingFiltersBySection(): void
    {
        $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'A']);
        $this->items->create(['section' => 'QUALITY_COMMITMENT', 'title' => 'B']);

        $names = array_column($this->items->list(['section' => 'ABOUT_VALUES']), 'title');

        self::assertSame(['A'], $names);
    }

    // ─── public read (RTPP-67) ───────────────────────────────────────────

    public function testPublicBySectionOnlyReturnsActiveItemsInThatSection(): void
    {
        $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'Visible', 'is_active' => true]);
        $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'Hidden', 'is_active' => false]);
        $this->items->create(['section' => 'QUALITY_COMMITMENT', 'title' => 'Wrong Section', 'is_active' => true]);

        $titles = array_column($this->items->publicBySection(['section' => 'ABOUT_VALUES']), 'title');

        self::assertSame(['Visible'], $titles);
    }

    public function testPublicBySectionRequiresASection(): void
    {
        $error = $this->captureApiError(fn () => $this->items->publicBySection([]));

        self::assertSame('section', $error->details()[0]['field']);
    }

    public function testPublicViewShapesTheIconObjectFromTheJoinedMediaAsset(): void
    {
        $mediaId = $this->insertMediaAsset();
        $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'With Icon', 'icon_image_id' => $mediaId]);

        $item = $this->items->publicBySection(['section' => 'ABOUT_VALUES'])[0];

        self::assertArrayNotHasKey('icon_image_id', $item);
        self::assertSame('https://example.test/img.jpg', $item['icon']['url']);
    }

    public function testPublicViewOmitsTheIconObjectWhenThereIsNoIconImage(): void
    {
        $this->items->create(['section' => 'ABOUT_VALUES', 'title' => 'No Icon']);

        $item = $this->items->publicBySection(['section' => 'ABOUT_VALUES'])[0];

        self::assertNull($item['icon']);
    }

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :now)'
        )->execute([
            ':id'        => $id,
            ':public_id' => 'test/' . bin2hex(random_bytes(6)),
            ':url'       => 'https://example.test/img.jpg',
            ':now'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);

        return $id;
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
