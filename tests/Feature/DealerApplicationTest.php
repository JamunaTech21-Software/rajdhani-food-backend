<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\DealerApplicationRepository;
use Rajdhani\Repositories\EnquiryRepository;
use Rajdhani\Repositories\LocationRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReferenceCounterRepository;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Services\DealerApplicationService;
use Rajdhani\Services\EnquiryService;

/**
 * Dealer applications, gapless `DA` application ids, and admin moderation
 * (doc §8.8, §9.6, §9.10, §10.3; RTPP-30), against a real database.
 *
 * The counter-row mechanism itself (locking, rollback-safe gapless
 * sequencing, deadlock retry under real concurrency) is exactly what
 * `EnquiryTest`/`EnquiryConcurrencyTest` already proved for `ENQ` — both
 * callers drive the same `ReferenceCounterRepository::nextSequence()` code
 * path, so re-running a second fork-based load test here would exercise the
 * identical lock, not a new one. What *is* specific to this ticket and
 * worth proving directly is the DoD's "`ENQ` and `DA` counters are
 * independent" — `testEnquiryAndDealerApplicationCountersAreIndependent()`.
 */
final class DealerApplicationTest extends DatabaseTestCase
{
    private DealerApplicationService $applications;
    private DealerApplicationRepository $repository;
    private ReferenceCounterRepository $counters;
    private int $year;
    private string $dhakaId;
    private string $dhakaUpazilaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DealerApplicationRepository($this->db);
        $this->counters = new ReferenceCounterRepository($this->db);
        $this->applications = new DealerApplicationService(
            $this->repository,
            new LocationRepository($this->db),
            $this->counters,
            new SettingsRepository($this->db),
            connection: $this->db,
        );
        $this->year = (int) gmdate('Y');

        $this->dhakaId = $this->districtIdByName('Dhaka');
        $this->dhakaUpazilaId = $this->firstUpazilaIdForDistrict($this->dhakaId);
    }

    public function testSubmittingCreatesAnApplicationAndReturnsTheModalPayload(): void
    {
        $result = $this->applications->submit('203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-DA-%d-00001', $this->year), $result['applicationId']);
        self::assertNotSame('', $result['submittedAt']);
        self::assertNotSame('', $result['expectedResponseWindow']);
    }

    public function testTwoSubmissionsGetConsecutiveSequenceNumbers(): void
    {
        $first = $this->applications->submit('203.0.113.5', $this->validInput());
        $second = $this->applications->submit('203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-DA-%d-00001', $this->year), $first['applicationId']);
        self::assertSame(sprintf('RDFP-DA-%d-00002', $this->year), $second['applicationId']);
    }

    /**
     * The doc's "`ENQ` and `DA` counters are independent" DoD line, proved
     * directly: interleaved submissions to both forms each get their own
     * gapless run starting at 1, because `reference_counters` keys on
     * `(type, year)` and `'ENQ'` and `'DA'` are different `type` values.
     */
    public function testEnquiryAndDealerApplicationCountersAreIndependent(): void
    {
        $enquiries = new EnquiryService(
            new EnquiryRepository($this->db),
            new ProductRepository($this->db),
            $this->counters,
            connection: $this->db,
        );

        $firstEnquiry = $enquiries->submit(null, '203.0.113.5', [
            'name' => 'Visitor', 'phone' => '+8801700000000', 'email' => 'visitor@example.test',
            'city' => 'Dhaka', 'message' => 'Interested in bulk pricing.',
        ]);
        $firstApplication = $this->applications->submit('203.0.113.5', $this->validInput());
        $secondEnquiry = $enquiries->submit(null, '203.0.113.5', [
            'name' => 'Visitor', 'phone' => '+8801700000000', 'email' => 'visitor@example.test',
            'city' => 'Dhaka', 'message' => 'Interested in bulk pricing.',
        ]);
        $secondApplication = $this->applications->submit('203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-ENQ-%d-00001', $this->year), $firstEnquiry['referenceNo']);
        self::assertSame(sprintf('RDFP-ENQ-%d-00002', $this->year), $secondEnquiry['referenceNo']);
        self::assertSame(sprintf('RDFP-DA-%d-00001', $this->year), $firstApplication['applicationId']);
        self::assertSame(sprintf('RDFP-DA-%d-00002', $this->year), $secondApplication['applicationId']);
    }

    public function testARolledBackAttemptLeavesNoGapInTheSequence(): void
    {
        $first = $this->applications->submit('203.0.113.5', $this->validInput());
        self::assertSame(sprintf('RDFP-DA-%d-00001', $this->year), $first['applicationId']);

        $this->db->exec('SAVEPOINT sp_forced_failure');

        try {
            $this->counters->nextSequence('DA', $this->year);
            // A NOT NULL column ('phone') is missing — a genuine DB-level
            // failure, not a simulated one.
            $this->repository->create([
                'full_name' => 'x', 'company_name' => 'x', 'email' => 'x@example.test',
                'district_id' => $this->dhakaId, 'upazila_id' => $this->dhakaUpazilaId,
                'address_line' => null, 'has_trade_license' => 0, 'has_tin_certificate' => 0,
                'years_of_experience' => null, 'message' => null, 'ip_address' => null,
                'application_id' => 'RDFP-DA-BAD',
            ]);

            self::fail('Expected the insert to fail on a missing NOT NULL column.');
        } catch (\PDOException) {
            $this->db->exec('ROLLBACK TO SAVEPOINT sp_forced_failure');
        }

        $retry = $this->applications->submit('203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-DA-%d-00002', $this->year), $retry['applicationId']);
    }

    public function testDistrictIdMustReferenceARealDistrict(): void
    {
        $error = $this->captureApiError(fn () => $this->applications->submit(
            '203.0.113.5',
            ['district_id' => UlidHelper::generate()] + $this->validInput(),
        ));

        self::assertSame('district_id', $error->details()[0]['field']);
    }

    public function testUpazilaIdMustBelongToTheGivenDistrict(): void
    {
        $khulna = $this->districtIdByName('Khulna');
        $khulnaUpazila = $this->firstUpazilaIdForDistrict($khulna);

        // Dhaka district, but an upazila that belongs to Khulna.
        $error = $this->captureApiError(fn () => $this->applications->submit(
            '203.0.113.5',
            ['upazila_id' => $khulnaUpazila] + $this->validInput(),
        ));

        self::assertSame('upazila_id', $error->details()[0]['field']);
    }

    public function testEmailMustLookLikeAnEmail(): void
    {
        // Override, not union: array `+` keeps the *left* side's value for a
        // duplicate key, so the replacement email has to come first.
        $error = $this->captureApiError(
            fn () => $this->applications->submit('203.0.113.5', ['email' => 'not-an-email'] + $this->validInput())
        );

        self::assertSame('email', $error->details()[0]['field']);
    }

    public function testYearsOfExperienceIsNullWhenNotProvided(): void
    {
        $result = $this->applications->submit('203.0.113.5', $this->validInput());
        $created = $this->repository->find($this->idForApplication($result['applicationId']));

        self::assertNull($created['years_of_experience']);
    }

    public function testYearsOfExperienceIsRecordedWhenProvided(): void
    {
        $result = $this->applications->submit(
            '203.0.113.5',
            ['years_of_experience' => 7] + $this->validInput(),
        );
        $created = $this->repository->find($this->idForApplication($result['applicationId']));

        self::assertSame(7, $created['years_of_experience']);
    }

    public function testAdminFiltersByStatus(): void
    {
        $this->applications->submit('203.0.113.5', $this->validInput());
        $second = $this->applications->submit('203.0.113.5', $this->validInput());
        $id = $this->idForApplication($second['applicationId']);
        $this->applications->update($id, ['status' => 'UNDER_REVIEW']);

        $underReview = $this->applications->paginate(['status' => 'UNDER_REVIEW']);
        $submitted = $this->applications->paginate(['status' => 'SUBMITTED']);

        self::assertSame(1, $underReview['meta']['total']);
        self::assertSame(1, $submitted['meta']['total']);
    }

    public function testAssignedToIdMustReferenceARealAdmin(): void
    {
        $result = $this->applications->submit('203.0.113.5', $this->validInput());
        $id = $this->idForApplication($result['applicationId']);

        $error = $this->captureApiError(
            fn () => $this->applications->update($id, ['assigned_to_id' => UlidHelper::generate()])
        );

        self::assertSame('assigned_to_id', $error->details()[0]['field']);
    }

    public function testMovingStatusAwayFromSubmittedStampsReviewedAt(): void
    {
        $result = $this->applications->submit('203.0.113.5', $this->validInput());
        $id = $this->idForApplication($result['applicationId']);

        $before = $this->applications->find($id);
        self::assertNull($before['reviewed_at']);

        $updated = $this->applications->update($id, ['status' => 'UNDER_REVIEW']);

        self::assertSame('UNDER_REVIEW', $updated['status']);
        self::assertNotNull($updated['reviewed_at']);
    }

    public function testExportProducesACsvRowPerApplication(): void
    {
        $this->applications->submit('203.0.113.5', $this->validInput());
        $this->applications->submit('203.0.113.5', $this->validInput());

        $export = $this->applications->exportCsv([]);

        self::assertStringContainsString('dealer-applications-', $export['filename']);
        $lines = array_filter(explode("\n", trim($export['csv'])));
        self::assertCount(3, $lines); // header + 2 rows
        self::assertStringContainsString('Application ID', $lines[0]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validInput(): array
    {
        return [
            'full_name' => 'Test Applicant', 'company_name' => 'Test Traders', 'phone' => '+8801700000000',
            'email' => 'applicant@example.test', 'district_id' => $this->dhakaId, 'upazila_id' => $this->dhakaUpazilaId,
            'message' => 'Interested in becoming a dealer.',
        ];
    }

    private function districtIdByName(string $name): string
    {
        $statement = $this->db->prepare('SELECT id FROM districts WHERE name = :name');
        $statement->execute([':name' => $name]);

        $id = $statement->fetchColumn();
        self::assertIsString($id, "Fixture district '{$name}' was not found — check bd-locations.json.");

        return $id;
    }

    private function firstUpazilaIdForDistrict(string $districtId): string
    {
        $statement = $this->db->prepare('SELECT id FROM upazilas WHERE district_id = :district_id ORDER BY name ASC LIMIT 1');
        $statement->execute([':district_id' => $districtId]);

        $id = $statement->fetchColumn();
        self::assertIsString($id, 'Expected at least one upazila for this district.');

        return $id;
    }

    private function idForApplication(string $applicationId): string
    {
        $statement = $this->db->prepare('SELECT id FROM dealer_applications WHERE application_id = :application_id');
        $statement->execute([':application_id' => $applicationId]);

        return (string) $statement->fetchColumn();
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
