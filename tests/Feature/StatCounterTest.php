<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Services\StatCounterService;

/**
 * Stat counters — animated count-up bands (doc §8.7, §10.1; RTPP-24),
 * against a real database. The dev database seeds real `HOME` rows; tests
 * needing an exact result use an unseeded group (`TEA_GARDEN`).
 */
final class StatCounterTest extends DatabaseTestCase
{
    private StatCounterService $stats;
    private StatCounterRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new StatCounterRepository($this->db);
        $this->stats = new StatCounterService($this->repository);
    }

    public function testCreatingAStatCounter(): void
    {
        $stat = $this->stats->create(['group' => 'TEA_GARDEN', 'value' => '12', 'label' => 'Gardens']);

        self::assertSame('12', $stat['value']);
        self::assertSame('Gardens', $stat['label']);
        self::assertTrue($stat['is_active']);
    }

    public function testAnInvalidGroupIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->stats->create([
            'group' => 'NOT_REAL', 'value' => '1', 'label' => 'X',
        ]));

        self::assertSame('group', $error->details()[0]['field']);
    }

    public function testValueAndLabelAreRequired(): void
    {
        $error = $this->captureApiError(fn () => $this->stats->create(['group' => 'TEA_GARDEN']));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testReorderIsScopedToOneGroup(): void
    {
        $a = $this->stats->create(['group' => 'TEA_GARDEN', 'value' => '1', 'label' => 'A']);
        $b = $this->stats->create(['group' => 'TEA_GARDEN', 'value' => '2', 'label' => 'B']);

        $this->stats->reorder(['group' => 'TEA_GARDEN', 'ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->stats->find($b['id'])['sort_order']);
        self::assertSame(2, $this->stats->find($a['id'])['sort_order']);
    }

    public function testReorderRejectsAnIdFromADifferentGroup(): void
    {
        $inGroup = $this->stats->create(['group' => 'TEA_GARDEN', 'value' => '1', 'label' => 'A']);
        $otherGroup = $this->stats->create(['group' => 'GALLERY', 'value' => '2', 'label' => 'B']);

        $error = $this->captureApiError(fn () => $this->stats->reorder([
            'group' => 'TEA_GARDEN', 'ids' => [$inGroup['id'], $otherGroup['id']],
        ]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testDeletingActuallyRemovesTheRow(): void
    {
        $stat = $this->stats->create(['group' => 'TEA_GARDEN', 'value' => '1', 'label' => 'Temp']);

        $this->stats->delete($stat['id']);

        $error = $this->captureApiError(fn () => $this->stats->find($stat['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testDeletingANonExistentStatIsNotAnError(): void
    {
        $this->stats->delete(UlidHelper::generate());

        self::assertTrue(true);
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
