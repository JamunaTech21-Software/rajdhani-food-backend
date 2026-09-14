<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Services\NewsService;

/**
 * `GET /public/news`, `/public/news/featured`, `/public/news/{slug}` (doc
 * §9.5, §10.1, §10.4; RTPP-26), against a real database.
 *
 * `testFutureScheduledPostsAreInvisibleUntilTheirDate()` is this ticket's
 * headline DoD item. `testPrevNextAreCorrectAtBothEndsOfTheList()` is the
 * other — see `NewsRepository::previous()`/`next()`'s class doc for what
 * "before"/"after" mean in this ordering.
 */
final class PublicNewsTest extends DatabaseTestCase
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

        // The seeded database already carries a real, currently-visible post
        // (`NewsSeeder`). Ordering and boundary assertions ("previous is null
        // at the oldest end") are only meaningful against a known, closed set
        // of posts, so every pre-existing one is archived for the scope of
        // this test file — rolled back like everything else `DatabaseTestCase`
        // does, never touching the real row outside this transaction.
        $this->db->exec("UPDATE news_posts SET status = 'ARCHIVED'");
    }

    public function testDraftPostsAreInvisible(): void
    {
        $this->news->create($this->authorId, ['title' => 'Test Draft Invisible', 'content' => '<p>x</p>']);

        $slugs = array_column($this->news->publicList([])['data'], 'slug');

        self::assertNotContains('test-draft-invisible', $slugs);
    }

    public function testFutureScheduledPostsAreInvisibleUntilTheirDate(): void
    {
        $future = gmdate('Y-m-d H:i:s.v', strtotime('+30 days'));

        $this->news->create($this->authorId, [
            'title' => 'Test Future Post', 'content' => '<p>x</p>', 'status' => 'PUBLISHED', 'published_at' => $future,
        ]);

        $slugs = array_column($this->news->publicList([])['data'], 'slug');
        self::assertNotContains('test-future-post', $slugs);

        $error = $this->captureApiError(fn () => $this->news->publicShow('test-future-post'));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testArchivedPostsAreInvisible(): void
    {
        $post = $this->news->create($this->authorId, [
            'title' => 'Test Archived Post', 'content' => '<p>x</p>', 'status' => 'PUBLISHED',
        ]);
        $this->news->update($post['id'], ['status' => 'ARCHIVED']);

        $slugs = array_column($this->news->publicList([])['data'], 'slug');
        self::assertNotContains('test-archived-post', $slugs);
    }

    public function testTagFilterMatchesOnlyPostsWithThatTag(): void
    {
        $this->publishAt('Test Tagged Post', $this->secondsAgo(20), ['unusual-test-tag']);
        $this->publishAt('Test Untagged Post', $this->secondsAgo(10), []);

        $result = $this->news->publicList(['tag' => 'unusual-test-tag']);

        self::assertSame(1, $result['meta']['total']);
        self::assertSame('test-tagged-post', $result['data'][0]['slug']);
    }

    /**
     * Timestamps are seconds-ago-from-now, not fixed calendar dates: the
     * seeded database already carries a real, currently-visible post
     * (`NewsSeeder`, published at seed time), and "three most recent" is
     * only a meaningful assertion if these fixtures are guaranteed newer
     * than it — the same "avoid an already-seeded value" lesson RTPP-20/23/24
     * applied to an enum column, applied here to a timestamp instead.
     */
    public function testFeaturedReturnsThreeMostRecentRegardlessOfIsFeaturedFlag(): void
    {
        $this->publishAt('Test Featured Old', $this->secondsAgo(40));
        $second = $this->publishAt('Test Featured Mid', $this->secondsAgo(30));
        $this->publishAt('Test Featured New', $this->secondsAgo(20));
        $this->publishAt('Test Featured Newest', $this->secondsAgo(10));
        $this->news->update($second['id'], ['is_featured' => false]);

        $slugs = array_column($this->news->publicFeatured(), 'slug');

        self::assertSame(
            ['test-featured-newest', 'test-featured-new', 'test-featured-mid'],
            $slugs,
        );
        self::assertNotContains('test-featured-old', $slugs);
    }

    public function testDetailIncrementsViewCount(): void
    {
        $post = $this->publishAt('Test View Count', $this->secondsAgo(10));
        self::assertSame(0, $post['view_count']);

        $shown = $this->news->publicShow('test-view-count');
        self::assertSame(1, $shown['view_count']);

        $shownAgain = $this->news->publicShow('test-view-count');
        self::assertSame(2, $shownAgain['view_count']);
    }

    public function testDetailOnANonVisibleSlugIs404(): void
    {
        $error = $this->captureApiError(fn () => $this->news->publicShow('no-such-slug-at-all'));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testPrevNextAreCorrectAtBothEndsOfTheList(): void
    {
        $this->publishAt('Test Chain Oldest', '2021-01-01 00:00:00.000');
        $this->publishAt('Test Chain Middle', '2021-01-02 00:00:00.000');
        $this->publishAt('Test Chain Newest', '2021-01-03 00:00:00.000');

        $oldest = $this->news->publicShow('test-chain-oldest');
        self::assertNull($oldest['previous']);
        self::assertSame('test-chain-middle', $oldest['next']['slug']);

        $middle = $this->news->publicShow('test-chain-middle');
        self::assertSame('test-chain-oldest', $middle['previous']['slug']);
        self::assertSame('test-chain-newest', $middle['next']['slug']);

        $newest = $this->news->publicShow('test-chain-newest');
        self::assertSame('test-chain-middle', $newest['previous']['slug']);
        self::assertNull($newest['next']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * @param list<string> $tags
     *
     * @return array<string,mixed>
     */
    private function publishAt(string $title, string $publishedAt, array $tags = []): array
    {
        $post = $this->news->create($this->authorId, [
            'title' => $title, 'content' => '<p>x</p>', 'status' => 'PUBLISHED',
            'published_at' => $publishedAt, 'tags' => $tags,
        ]);

        // create()'s effectivePublishedAt() only defaults a *missing* date —
        // an explicit past one passes through untouched, so this reload just
        // confirms the fixture landed exactly where the test needs it.
        return $this->news->find($post['id']);
    }

    private function secondsAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s.v', time() - $seconds);
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
