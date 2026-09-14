<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\ProcessStepRepository;
use Rajdhani\Services\ProcessStepService;

/**
 * Process steps — numbered timeline entries (doc §8.7, §10.4; RTPP-24),
 * against a real database.
 *
 * `testTwoStepsCannotShareANumberWithinAGroupAtTheDatabaseLevel()` bypasses
 * the service's own pre-check entirely, inserting directly via SQL, because
 * the ticket's DoD says "rejected by the database" — proving the migration
 * itself is correct, not just the application-level guard in front of it.
 */
final class ProcessStepTest extends DatabaseTestCase
{
    private ProcessStepService $steps;
    private ProcessStepRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProcessStepRepository($this->db);
        $this->steps = new ProcessStepService($this->repository);
    }

    public function testCreatingAProcessStep(): void
    {
        $step = $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'Inspect']);

        self::assertSame('QUALITY_PROCESS', $step['group']);
        self::assertSame(1, $step['step_number']);
    }

    public function testAnInvalidGroupIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->steps->create([
            'group' => 'NOT_REAL', 'step_number' => 1, 'title' => 'X',
        ]));

        self::assertSame('group', $error->details()[0]['field']);
    }

    public function testStepNumberMustBePositive(): void
    {
        $error = $this->captureApiError(fn () => $this->steps->create([
            'group' => 'QUALITY_PROCESS', 'step_number' => 0, 'title' => 'X',
        ]));

        self::assertSame('step_number', $error->details()[0]['field']);
    }

    public function testADuplicateStepNumberWithinAGroupIsRejectedByThePreCheck(): void
    {
        $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'First']);

        $error = $this->captureApiError(fn () => $this->steps->create([
            'group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'Duplicate',
        ]));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
        self::assertSame('step_number', $error->details()[0]['field']);
    }

    /** The same number in a *different* group is not a collision. */
    public function testTheSameStepNumberInADifferentGroupIsFine(): void
    {
        $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'A']);
        $other = $this->steps->create(['group' => 'MANUFACTURING_PROCESS', 'step_number' => 1, 'title' => 'B']);

        self::assertSame(1, $other['step_number']);
    }

    /**
     * Bypasses the service entirely — proves `uq_process_steps_group_number`
     * itself rejects the collision, not just the application's pre-check.
     */
    public function testTwoStepsCannotShareANumberWithinAGroupAtTheDatabaseLevel(): void
    {
        $this->insertProcessStepDirectly('QUALITY_PROCESS', 3, 'Direct One');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/uq_process_steps_group_number/');

        $this->insertProcessStepDirectly('QUALITY_PROCESS', 3, 'Direct Two');
    }

    /**
     * A partial update touching only `step_number` must check uniqueness
     * against the row's own *stored* group, not treat it as absent.
     */
    public function testUpdatingOnlyStepNumberChecksUniquenessAgainstTheStoredGroup(): void
    {
        $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'A']);
        $b = $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 2, 'title' => 'B']);

        $error = $this->captureApiError(fn () => $this->steps->update($b['id'], ['step_number' => 1]));

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    public function testUpdatingAStepsOwnNumberToItselfIsNotAConflict(): void
    {
        $step = $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'A']);

        $updated = $this->steps->update($step['id'], ['title' => 'A renamed', 'step_number' => 1]);

        self::assertSame('A renamed', $updated['title']);
    }

    public function testReorderIsScopedToOneGroupAndDoesNotTouchStepNumber(): void
    {
        $a = $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 1, 'title' => 'A']);
        $b = $this->steps->create(['group' => 'QUALITY_PROCESS', 'step_number' => 2, 'title' => 'B']);

        $this->steps->reorder(['group' => 'QUALITY_PROCESS', 'ids' => [$b['id'], $a['id']]]);

        $refreshedA = $this->steps->find($a['id']);
        $refreshedB = $this->steps->find($b['id']);

        self::assertSame(1, $refreshedB['sort_order']);
        self::assertSame(2, $refreshedA['sort_order']);
        // step_number is untouched by reorder.
        self::assertSame(1, $refreshedA['step_number']);
        self::assertSame(2, $refreshedB['step_number']);
    }

    private function insertProcessStepDirectly(string $group, int $stepNumber, string $title): void
    {
        $this->db->prepare(
            'INSERT INTO process_steps (id, `group`, step_number, title, sort_order, is_active)
             VALUES (:id, :group, :step_number, :title, 0, 1)'
        )->execute([
            ':id'          => UlidHelper::generate(),
            ':group'       => $group,
            ':step_number' => $stepNumber,
            ':title'       => $title,
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
