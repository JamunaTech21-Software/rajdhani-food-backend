<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Services\TestimonialService;

/**
 * Testimonials — flat, `status`-gated (doc §8.7, §10.4; RTPP-24), against a
 * real database that already carries real seeded rows.
 */
final class TestimonialTest extends DatabaseTestCase
{
    private TestimonialService $testimonials;
    private TestimonialRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TestimonialRepository($this->db);
        $this->testimonials = new TestimonialService($this->repository);
    }

    public function testCreatingATestimonialDefaultsStatusToPublished(): void
    {
        $testimonial = $this->testimonials->create([
            'author_name' => 'A Distinctive New Reviewer', 'quote' => 'Great tea.',
        ]);

        self::assertSame('PUBLISHED', $testimonial['status']);
    }

    public function testAuthorNameAndQuoteAreRequired(): void
    {
        $error = $this->captureApiError(fn () => $this->testimonials->create(['author_name' => 'X']));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testRatingMustBeBetweenOneAndFive(): void
    {
        $error = $this->captureApiError(fn () => $this->testimonials->create([
            'author_name' => 'X', 'quote' => 'Y', 'rating' => 6,
        ]));

        self::assertSame('rating', $error->details()[0]['field']);
    }

    public function testAvatarMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->testimonials->create([
            'author_name' => 'X', 'quote' => 'Y', 'avatar_id' => UlidHelper::generate(),
        ]));

        self::assertSame('avatar_id', $error->details()[0]['field']);
    }

    public function testFilteringByStatusExcludesDraftsFromThePublishedList(): void
    {
        $this->testimonials->create(['author_name' => 'Draft Author Unique', 'quote' => 'Q', 'status' => 'DRAFT']);
        $this->testimonials->create(['author_name' => 'Published Author Unique', 'quote' => 'Q', 'status' => 'PUBLISHED']);

        $names = array_column($this->testimonials->list(['status' => 'PUBLISHED']), 'author_name');

        self::assertContains('Published Author Unique', $names);
        self::assertNotContains('Draft Author Unique', $names);
    }

    public function testDeletingActuallyRemovesTheRow(): void
    {
        $testimonial = $this->testimonials->create(['author_name' => 'Temp', 'quote' => 'Q']);

        $this->testimonials->delete($testimonial['id']);

        $error = $this->captureApiError(fn () => $this->testimonials->find($testimonial['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testReorderAppliesPositionsAcrossTheWholeList(): void
    {
        $a = $this->testimonials->create(['author_name' => 'Reorder A', 'quote' => 'Q']);
        $b = $this->testimonials->create(['author_name' => 'Reorder B', 'quote' => 'Q']);

        $this->testimonials->reorder(['ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->testimonials->find($b['id'])['sort_order']);
        self::assertSame(2, $this->testimonials->find($a['id'])['sort_order']);
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
