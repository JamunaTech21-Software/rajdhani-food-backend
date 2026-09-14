<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\SocialLinkRepository;
use Rajdhani\Services\SocialLinkService;

/**
 * `/admin/social-links` (doc §8.2, §9.8, §10.5; RTPP-32), against a real
 * database that already has `NavigationSeeder`'s rows in it.
 */
final class SocialLinkTest extends DatabaseTestCase
{
    private SocialLinkService $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->links = new SocialLinkService(new SocialLinkRepository($this->db));
    }

    public function testCreatingALink(): void
    {
        $created = $this->links->create($this->validInput());

        self::assertSame('facebook', $created['platform']);
        self::assertTrue($created['is_active']);
    }

    public function testPlatformAcceptsFreeTextNotJustTheExamples(): void
    {
        // The schema column is VARCHAR(64), not an ENUM — the examples in
        // its comment are not an enforced list. See SocialLinkService's
        // class doc.
        $created = $this->links->create(['platform' => 'tiktok'] + $this->validInput());

        self::assertSame('tiktok', $created['platform']);
    }

    public function testUpdatingChangesOnlyTheGivenFields(): void
    {
        $created = $this->links->create($this->validInput());
        $updated = $this->links->update($created['id'], ['is_active' => false]);

        self::assertFalse($updated['is_active']);
        self::assertSame('facebook', $updated['platform']);
    }

    public function testDeletingIsIdempotent(): void
    {
        $created = $this->links->create($this->validInput());
        $this->links->delete($created['id']);
        $this->links->delete($created['id']);

        $error = $this->captureApiError(fn () => $this->links->find($created['id']));
        self::assertSame(404, $error->status());
    }

    public function testReorderingUpdatesSortOrderAcrossTheWholeFlatList(): void
    {
        $a = $this->links->create(['platform' => 'a'] + $this->validInput());
        $b = $this->links->create(['platform' => 'b'] + $this->validInput());

        $result = $this->links->reorder(['ids' => [$b['id'], $a['id']]]);
        self::assertSame(2, $result['reordered']);

        // NavigationSeeder already seeds social links, so filter the
        // listing down to this test's own two ids before asserting order.
        $list = array_values(array_filter(
            $this->links->list(),
            static fn (array $link): bool => in_array($link['id'], [$a['id'], $b['id']], true),
        ));
        self::assertSame($b['id'], $list[0]['id']);
        self::assertSame($a['id'], $list[1]['id']);
    }

    public function testReorderingRejectsAMissingId(): void
    {
        $error = $this->captureApiError(
            fn () => $this->links->reorder(['ids' => [UlidHelper::generate()]])
        );

        self::assertSame(422, $error->status());
    }

    private function validInput(): array
    {
        return ['platform' => 'facebook', 'url' => 'https://facebook.com/rajdhaniteas'];
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
