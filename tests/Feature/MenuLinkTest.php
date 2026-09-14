<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\MenuLinkRepository;
use Rajdhani\Services\MenuLinkService;

/**
 * `/admin/menu-links` (doc §8.2, §9.8, §10.5; RTPP-32), against a real
 * database that already has `NavigationSeeder`'s rows in it.
 */
final class MenuLinkTest extends DatabaseTestCase
{
    private MenuLinkService $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->links = new MenuLinkService(new MenuLinkRepository($this->db));
    }

    public function testCreatingALink(): void
    {
        $created = $this->links->create($this->validInput());

        self::assertSame('header', $created['location']);
        self::assertSame('Contact', $created['label']);
        self::assertTrue($created['is_active']);
        self::assertFalse($created['open_in_new_tab']);
    }

    public function testLocationMustBeOneOfTheFourKnownValues(): void
    {
        $error = $this->captureApiError(
            fn () => $this->links->create(['location' => 'sidebar'] + $this->validInput())
        );

        self::assertSame('location', $error->details()[0]['field']);
    }

    public function testListingFiltersByLocation(): void
    {
        $this->links->create($this->validInput());
        $this->links->create(['location' => 'legal'] + $this->validInput());

        $header = $this->links->list(['location' => 'header']);
        $legal = $this->links->list(['location' => 'legal']);

        self::assertNotEmpty($header);
        self::assertNotEmpty($legal);

        foreach ($header as $link) {
            self::assertSame('header', $link['location']);
        }
    }

    public function testParentIdMustReferenceARealLink(): void
    {
        $error = $this->captureApiError(
            fn () => $this->links->create(['parent_id' => UlidHelper::generate()] + $this->validInput())
        );

        self::assertSame('parent_id', $error->details()[0]['field']);
    }

    public function testAChildLinkCanBeCreatedUnderARealParent(): void
    {
        $parent = $this->links->create($this->validInput());
        $child = $this->links->create(['parent_id' => $parent['id'], 'label' => 'Sub Item'] + $this->validInput());

        self::assertSame($parent['id'], $child['parent_id']);
    }

    public function testDeletingALinkWithChildrenIsAConflict(): void
    {
        $parent = $this->links->create($this->validInput());
        $this->links->create(['parent_id' => $parent['id'], 'label' => 'Sub Item'] + $this->validInput());

        $error = $this->captureApiError(fn () => $this->links->delete($parent['id']));

        self::assertSame(409, $error->status());
    }

    public function testDeletingALeafLinkSucceeds(): void
    {
        $link = $this->links->create($this->validInput());
        $this->links->delete($link['id']);

        $error = $this->captureApiError(fn () => $this->links->find($link['id']));
        self::assertSame(404, $error->status());
    }

    public function testDeletingAMissingLinkIsIdempotent(): void
    {
        $this->links->delete(UlidHelper::generate());
        self::assertTrue(true);
    }

    public function testReorderingIsScopedToOneLocation(): void
    {
        $a = $this->links->create(['label' => 'A'] + $this->validInput());
        $b = $this->links->create(['label' => 'B'] + $this->validInput());
        $legalLink = $this->links->create(['location' => 'legal'] + $this->validInput());

        $result = $this->links->reorder(['location' => 'header', 'ids' => [$b['id'], $a['id']]]);
        self::assertSame(2, $result['reordered']);

        // NavigationSeeder already seeds "header" rows, so filter the
        // listing down to this test's own two ids before asserting order.
        $reordered = array_values(array_filter(
            $this->links->list(['location' => 'header']),
            static fn (array $link): bool => in_array($link['id'], [$a['id'], $b['id']], true),
        ));
        self::assertSame($b['id'], $reordered[0]['id']);
        self::assertSame($a['id'], $reordered[1]['id']);

        // The legal link is untouched by a reorder scoped to "header".
        self::assertSame(0, $this->links->find($legalLink['id'])['sort_order']);
    }

    public function testReorderingRejectsAnIdFromAnotherLocation(): void
    {
        $header = $this->links->create($this->validInput());
        $legalLink = $this->links->create(['location' => 'legal'] + $this->validInput());

        $error = $this->captureApiError(
            fn () => $this->links->reorder(['location' => 'header', 'ids' => [$header['id'], $legalLink['id']]])
        );

        self::assertSame(422, $error->status());
    }

    private function validInput(): array
    {
        return ['location' => 'header', 'label' => 'Contact', 'url' => '/contact'];
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
