<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\NewsletterSubscriberRepository;
use Rajdhani\Services\NewsletterService;

/**
 * Newsletter subscribe/unsubscribe (doc §2, §8.8, §9.6, §9.10, §11;
 * RTPP-31). The DoD is two specific behaviours, and each gets its own test
 * that proves the row-level mechanics, not just the returned shape:
 * `testResubscribingAnActiveAddressDoesNotDuplicateOrError()` and
 * `testTheUnsubscribeLinkWorksWithoutAuthenticationAndIsNotGuessable()`.
 */
final class NewsletterTest extends DatabaseTestCase
{
    private NewsletterService $newsletter;
    private NewsletterSubscriberRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new NewsletterSubscriberRepository($this->db);
        $this->newsletter = new NewsletterService($this->repository);
    }

    public function testSubscribingCreatesAnActiveSubscriberWithAToken(): void
    {
        $result = $this->newsletter->subscribe(['email' => 'reader@example.test', 'source' => 'footer']);

        self::assertSame('reader@example.test', $result['email']);
        self::assertTrue($result['subscribed']);

        $row = $this->repository->findByEmail('reader@example.test');
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['is_subscribed']);
        self::assertSame(64, strlen((string) $row['unsubscribe_token']));
    }

    /**
     * The DoD's own wording: "re-subscribing an existing address does not
     * create a duplicate or error the visitor." Proved at the row level —
     * one row, same token, `email`'s own unique constraint never tripped —
     * not just that the call didn't throw.
     */
    public function testResubscribingAnActiveAddressDoesNotDuplicateOrError(): void
    {
        $this->newsletter->subscribe(['email' => 'reader@example.test']);
        $originalToken = $this->repository->findByEmail('reader@example.test')['unsubscribe_token'];

        $result = $this->newsletter->subscribe(['email' => 'reader@example.test']);

        self::assertTrue($result['subscribed']);

        $rows = $this->countRowsForEmail('reader@example.test');
        self::assertSame(1, $rows);

        $row = $this->repository->findByEmail('reader@example.test');
        self::assertSame($originalToken, $row['unsubscribe_token']);
    }

    public function testResubscribingAPreviouslyUnsubscribedAddressReactivatesTheSameRow(): void
    {
        $this->newsletter->subscribe(['email' => 'reader@example.test']);
        $token = (string) $this->repository->findByEmail('reader@example.test')['unsubscribe_token'];
        $this->newsletter->unsubscribe($token);

        self::assertSame(0, (int) $this->repository->findByEmail('reader@example.test')['is_subscribed']);

        $this->newsletter->subscribe(['email' => 'reader@example.test']);

        $row = $this->repository->findByEmail('reader@example.test');
        self::assertSame(1, (int) $row['is_subscribed']);
        self::assertSame($token, $row['unsubscribe_token']);
        self::assertNull($row['unsubscribed_at']);
        self::assertSame(1, $this->countRowsForEmail('reader@example.test'));
    }

    /**
     * "Not guessable" is the token's own 64 random hex characters
     * (`bin2hex(random_bytes(32))`), not a signed-in session — this test
     * proves the *unauthenticated* call, with only the token, both succeeds
     * on the real one and 404s on a made-up one.
     */
    public function testTheUnsubscribeLinkWorksWithoutAuthenticationAndIsNotGuessable(): void
    {
        $this->newsletter->subscribe(['email' => 'reader@example.test']);
        $token = (string) $this->repository->findByEmail('reader@example.test')['unsubscribe_token'];

        $result = $this->newsletter->unsubscribe($token);

        self::assertSame('reader@example.test', $result['email']);
        self::assertFalse($result['subscribed']);
        self::assertSame(0, (int) $this->repository->findByEmail('reader@example.test')['is_subscribed']);

        $error = $this->captureApiError(fn () => $this->newsletter->unsubscribe(str_repeat('0', 64)));
        self::assertSame(404, $error->status());
    }

    public function testUnsubscribingTwiceIsIdempotent(): void
    {
        $this->newsletter->subscribe(['email' => 'reader@example.test']);
        $token = (string) $this->repository->findByEmail('reader@example.test')['unsubscribe_token'];

        $this->newsletter->unsubscribe($token);
        $second = $this->newsletter->unsubscribe($token);

        self::assertFalse($second['subscribed']);
    }

    public function testEmailMustLookLikeAnEmail(): void
    {
        $error = $this->captureApiError(fn () => $this->newsletter->subscribe(['email' => 'not-an-email']));

        self::assertSame('email', $error->details()[0]['field']);
    }

    public function testAdminFiltersByIsSubscribed(): void
    {
        $this->newsletter->subscribe(['email' => 'active@example.test']);
        $this->newsletter->subscribe(['email' => 'gone@example.test']);
        $token = (string) $this->repository->findByEmail('gone@example.test')['unsubscribe_token'];
        $this->newsletter->unsubscribe($token);

        $active = $this->newsletter->paginate(['isSubscribed' => 'true']);
        $inactive = $this->newsletter->paginate(['isSubscribed' => 'false']);

        self::assertSame(1, $active['meta']['total']);
        self::assertSame(1, $inactive['meta']['total']);
        self::assertSame('active@example.test', $active['data'][0]['email']);
    }

    public function testExportProducesACsvRowPerSubscriber(): void
    {
        $this->newsletter->subscribe(['email' => 'one@example.test']);
        $this->newsletter->subscribe(['email' => 'two@example.test']);

        $export = $this->newsletter->exportCsv([]);

        self::assertStringContainsString('newsletter-subscribers-', $export['filename']);
        $lines = array_filter(explode("\n", trim($export['csv'])));
        self::assertCount(3, $lines); // header + 2 rows
        self::assertStringContainsString('Email', $lines[0]);
    }

    public function testDeletingASubscriberRemovesTheRow(): void
    {
        $this->newsletter->subscribe(['email' => 'reader@example.test']);
        $id = (string) $this->repository->findByEmail('reader@example.test')['id'];

        $this->newsletter->delete($id);

        self::assertNull($this->repository->findByEmail('reader@example.test'));
    }

    public function testDeletingAMissingSubscriberIsIdempotent(): void
    {
        // No exception — see NewsletterService::delete()'s class doc.
        $this->newsletter->delete('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        self::assertTrue(true);
    }

    private function countRowsForEmail(string $email): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :email');
        $statement->execute([':email' => $email]);

        return (int) $statement->fetchColumn();
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
