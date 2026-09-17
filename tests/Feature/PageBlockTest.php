<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\PageBlockRepository;
use Rajdhani\Services\PageBlockService;

/**
 * Page blocks — editable static-page sections keyed `(page_key, block_key)`
 * (doc §8.7, §11; RTPP-24), against a real database.
 *
 * `testRichTextIsSanitisedBeforeStorage()` and
 * `testTwoBlocksCannotShareAKeyOnThePageAtTheDatabaseLevel()` are this
 * ticket's two DoD items that name this module specifically, verified the
 * same way `ProductTest`/`ProcessStepTest` verify their own equivalents: a
 * real payload through real HTMLPurifier, and a direct-SQL insert proving
 * the migration's constraint itself rejects the collision.
 */
final class PageBlockTest extends DatabaseTestCase
{
    private PageBlockService $blocks;
    private PageBlockRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PageBlockRepository($this->db);
        $this->blocks = new PageBlockService($this->repository);
    }

    public function testCreatingAPageBlock(): void
    {
        $block = $this->blocks->create([
            'page_key' => 'test-page', 'block_key' => 'intro', 'heading' => 'Welcome',
        ]);

        self::assertSame('test-page', $block['page_key']);
        self::assertSame('intro', $block['block_key']);
        self::assertSame('PUBLISHED', $block['status']);
    }

    public function testRichTextIsSanitisedBeforeStorage(): void
    {
        $block = $this->blocks->create([
            'page_key' => 'test-page', 'block_key' => 'body-block',
            'body' => '<p>Our story</p><script>alert(1)</script>',
        ]);

        self::assertSame('<p>Our story</p>', $block['body']);
    }

    public function testBulletPointsRoundTripAsAStringList(): void
    {
        $block = $this->blocks->create([
            'page_key' => 'test-page', 'block_key' => 'bullets',
            'bullet_points' => ['One', 'Two', 'Three'],
        ]);

        self::assertSame(['One', 'Two', 'Three'], $block['bullet_points']);
    }

    public function testADuplicateBlockKeyOnTheSamePageIsRejectedByThePreCheck(): void
    {
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'mission']);

        $error = $this->captureApiError(
            fn () => $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'mission'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    /** The same block_key on a *different* page is not a collision. */
    public function testTheSameBlockKeyOnADifferentPageIsFine(): void
    {
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'mission']);
        $other = $this->blocks->create(['page_key' => 'another-test-page', 'block_key' => 'mission']);

        self::assertSame('mission', $other['block_key']);
    }

    /**
     * Bypasses the service entirely — proves `uq_page_blocks` itself
     * rejects the collision, not just the application's pre-check.
     */
    public function testTwoBlocksCannotShareAKeyOnThePageAtTheDatabaseLevel(): void
    {
        $this->insertPageBlockDirectly('test-page', 'duplicate-key');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/uq_page_blocks/');

        $this->insertPageBlockDirectly('test-page', 'duplicate-key');
    }

    public function testUpdatingOnlyBlockKeyChecksUniquenessAgainstTheStoredPageKey(): void
    {
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'mission']);
        $vision = $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'vision']);

        $error = $this->captureApiError(fn () => $this->blocks->update($vision['id'], ['block_key' => 'mission']));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    public function testImageMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->blocks->create([
            'page_key' => 'test-page', 'block_key' => 'x', 'image_id' => UlidHelper::generate(),
        ]));

        self::assertSame('image_id', $error->details()[0]['field']);
    }

    public function testListingFiltersByPageKey(): void
    {
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'a']);
        $this->blocks->create(['page_key' => 'another-test-page', 'block_key' => 'b']);

        $keys = array_column($this->blocks->list(['page_key' => 'test-page']), 'block_key');

        self::assertSame(['a'], $keys);
    }

    public function testReorderIsScopedToOnePage(): void
    {
        $a = $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'a']);
        $b = $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'b']);

        $this->blocks->reorder(['page_key' => 'test-page', 'ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->blocks->find($b['id'])['sort_order']);
        self::assertSame(2, $this->blocks->find($a['id'])['sort_order']);
    }

    public function testDeletingActuallyRemovesTheRow(): void
    {
        $block = $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'temp']);

        $this->blocks->delete($block['id']);

        $error = $this->captureApiError(fn () => $this->blocks->find($block['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    // ─── public read (RTPP-67) ───────────────────────────────────────────

    public function testPublicByPageOnlyReturnsPublishedBlocksOnThatPageInSortOrder(): void
    {
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'second', 'heading' => 'Second', 'sort_order' => 2]);
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'first', 'heading' => 'First', 'sort_order' => 1]);
        $this->blocks->create(['page_key' => 'test-page', 'block_key' => 'draft', 'heading' => 'Draft', 'status' => 'DRAFT']);
        $this->blocks->create(['page_key' => 'another-test-page', 'block_key' => 'other-page', 'heading' => 'Wrong Page']);

        $headings = array_column($this->blocks->publicByPage('test-page'), 'heading');

        self::assertSame(['First', 'Second'], $headings);
    }

    public function testPublicByPageReturnsAnEmptyArrayNotNullForAPageWithNoPublishedBlocks(): void
    {
        self::assertSame([], $this->blocks->publicByPage('a-page-with-nothing-published'));
    }

    public function testPublicViewDecodesBulletPointsAndShapesTheImage(): void
    {
        $mediaId = $this->insertMediaAsset();
        $this->blocks->create([
            'page_key' => 'test-page', 'block_key' => 'story', 'heading' => 'Our Story',
            'bullet_points' => ['One', 'Two'], 'image_id' => $mediaId,
        ]);

        $block = $this->blocks->publicByPage('test-page')[0];

        self::assertArrayNotHasKey('id', $block);
        self::assertArrayNotHasKey('page_key', $block);
        self::assertArrayNotHasKey('image_id', $block);
        self::assertSame(['One', 'Two'], $block['bullet_points']);
        self::assertSame('https://example.test/img.jpg', $block['image']['url']);
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

    private function insertPageBlockDirectly(string $pageKey, string $blockKey): void
    {
        $this->db->prepare(
            'INSERT INTO page_blocks (id, page_key, block_key, sort_order, status)
             VALUES (:id, :page_key, :block_key, 0, \'PUBLISHED\')'
        )->execute([
            ':id'        => UlidHelper::generate(),
            ':page_key'  => $pageKey,
            ':block_key' => $blockKey,
        ]);
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
