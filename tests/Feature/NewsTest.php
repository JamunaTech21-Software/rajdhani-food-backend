<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Services\NewsService;

/**
 * Admin news CRUD, scheduled publishing, and rich-text sanitisation (doc
 * §8.7, §9.9, §10.4; RTPP-26), against a real database.
 *
 * `testPublishingWithNoDateDefaultsToNow()` and
 * `testUpdatingOnlyStatusToPublishedDefaultsPublishedAtFromStoredNull()`
 * exercise `NewsService::effectivePublishedAt()` — the "publish means publish
 * now unless a date was picked" rule the class doc explains.
 */
final class NewsTest extends DatabaseTestCase
{
    private NewsService $news;
    private NewsRepository $repository;
    private string $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new NewsRepository($this->db);
        $this->news = new NewsService($this->repository);
        $this->authorId = (string) $this->db->query('SELECT id FROM admin_users LIMIT 1')->fetchColumn();
    }

    public function testCreatingADraftPost(): void
    {
        $post = $this->news->create($this->authorId, [
            'title' => 'Test Draft Post', 'content' => '<p>Draft content.</p>',
        ]);

        self::assertSame('DRAFT', $post['status']);
        self::assertNull($post['published_at']);
        self::assertSame($this->authorId, $post['author_id']);
    }

    public function testPublishingWithNoDateDefaultsToNow(): void
    {
        $before = gmdate('Y-m-d H:i:s');

        $post = $this->news->create($this->authorId, [
            'title' => 'Test Publish Now', 'content' => '<p>Live now.</p>', 'status' => 'PUBLISHED',
        ]);

        self::assertNotNull($post['published_at']);
        self::assertGreaterThanOrEqual($before, substr((string) $post['published_at'], 0, 19));
    }

    public function testPublishingWithAFutureDateSchedulesIt(): void
    {
        $future = gmdate('Y-m-d H:i:s.v', strtotime('+30 days'));

        $post = $this->news->create($this->authorId, [
            'title' => 'Test Scheduled Post', 'content' => '<p>Coming soon.</p>',
            'status' => 'PUBLISHED', 'published_at' => $future,
        ]);

        self::assertStringStartsWith(substr($future, 0, 10), (string) $post['published_at']);
    }

    public function testUpdatingOnlyStatusToPublishedDefaultsPublishedAtFromStoredNull(): void
    {
        $post = $this->news->create($this->authorId, ['title' => 'Test Draft To Publish', 'content' => '<p>x</p>']);

        $updated = $this->news->update($post['id'], ['status' => 'PUBLISHED']);

        self::assertNotNull($updated['published_at']);
    }

    public function testExplicitSlugCollisionIsRejected(): void
    {
        $this->news->create($this->authorId, ['title' => 'Test A', 'content' => '<p>x</p>', 'slug' => 'test-news-slug']);

        $error = $this->captureApiError(fn () => $this->news->create(
            $this->authorId,
            ['title' => 'Test B', 'content' => '<p>x</p>', 'slug' => 'test-news-slug'],
        ));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    /** Bypasses the service entirely — proves `uq_news_posts_slug` itself rejects the collision. */
    public function testTwoPostsCannotShareASlugAtTheDatabaseLevel(): void
    {
        $this->insertPostDirectly('duplicate-news-slug');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/uq_news_posts_slug/');

        $this->insertPostDirectly('duplicate-news-slug');
    }

    public function testContentIsSanitisedBeforeStorage(): void
    {
        $post = $this->news->create($this->authorId, [
            'title' => 'Test Sanitised', 'content' => '<p>Real news</p><script>alert(1)</script>',
        ]);

        self::assertSame('<p>Real news</p>', $post['content']);
    }

    public function testContentThatSanitisesToNothingIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->news->create($this->authorId, [
            'title' => 'Test Empty After Sanitising', 'content' => '<script>alert(1)</script>',
        ]));

        self::assertSame('content', $error->details()[0]['field']);
    }

    public function testCoverImageMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->news->create($this->authorId, [
            'title' => 'Test Bad Cover', 'content' => '<p>x</p>', 'cover_image_id' => UlidHelper::generate(),
        ]));

        self::assertSame('cover_image_id', $error->details()[0]['field']);
    }

    public function testTagsRoundTripAsAStringList(): void
    {
        $post = $this->news->create($this->authorId, [
            'title' => 'Test Tags', 'content' => '<p>x</p>', 'tags' => ['Announcement', 'Dealer'],
        ]);

        self::assertSame(['Announcement', 'Dealer'], $post['tags']);
    }

    public function testAuthorIdIsStampedFromCallerNotClientInput(): void
    {
        $spoofed = UlidHelper::generate();

        $post = $this->news->create($this->authorId, [
            'title' => 'Test No Spoofing', 'content' => '<p>x</p>', 'author_id' => $spoofed,
        ]);

        self::assertSame($this->authorId, $post['author_id']);
        self::assertNotSame($spoofed, $post['author_id']);
    }

    public function testDeletingIsIdempotentSoftDelete(): void
    {
        $post = $this->news->create($this->authorId, ['title' => 'Test Delete Me', 'content' => '<p>x</p>']);

        $this->news->delete($post['id']);
        $this->news->delete($post['id']);

        $error = $this->captureApiError(fn () => $this->news->find($post['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function insertPostDirectly(string $slug): void
    {
        $now = $this->now();
        $this->db->prepare(
            'INSERT INTO news_posts (id, title, slug, content, status, is_featured, view_count, created_at, updated_at)
             VALUES (:id, :title, :slug, :content, \'DRAFT\', 0, 0, :created_at, :updated_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':title' => 'Direct Insert', ':slug' => $slug,
            ':content' => '<p>x</p>', ':created_at' => $now, ':updated_at' => $now,
        ]);
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
