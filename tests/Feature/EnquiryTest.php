<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\EnquiryRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReferenceCounterRepository;
use Rajdhani\Services\EnquiryService;

/**
 * Enquiry submission, gapless reference numbers, and admin moderation (doc
 * §2, §8.2, §8.8, §9.6, §9.10; RTPP-29), against a real database.
 *
 * `testARolledBackAttemptLeavesNoGapInTheSequence()` is this ticket's
 * headline design point: a native `AUTO_INCREMENT` burns a value the moment
 * a transaction rolls back, because the sequence advances outside the
 * transaction that consumes it. The locked-counter-row mechanism does not,
 * because the counter's `UPDATE` and the enquiry's `INSERT` are the same
 * transaction — this test forces exactly that failure and proves the next
 * successful submission reclaims the number the failed one would have taken.
 *
 * `reference_counters` is empty in the seeded database (confirmed via direct
 * query before writing this file), so every test here can assert an exact
 * sequence number starting from 1 without an unseeded-value workaround.
 */
final class EnquiryTest extends DatabaseTestCase
{
    private EnquiryService $enquiries;
    private EnquiryRepository $repository;
    private ReferenceCounterRepository $counters;
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EnquiryRepository($this->db);
        $this->counters = new ReferenceCounterRepository($this->db);
        $this->enquiries = new EnquiryService($this->repository, new ProductRepository($this->db), $this->counters, connection: $this->db);
        $this->year = (int) gmdate('Y');
    }

    public function testSubmittingCreatesAnEnquiryAndReturnsAReferenceNumber(): void
    {
        $result = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-ENQ-%d-00001', $this->year), $result['referenceNo']);
    }

    public function testTwoSubmissionsGetConsecutiveSequenceNumbers(): void
    {
        $first = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $second = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-ENQ-%d-00001', $this->year), $first['referenceNo']);
        self::assertSame(sprintf('RDFP-ENQ-%d-00002', $this->year), $second['referenceNo']);
    }

    /**
     * Forces a failure *after* the counter has already advanced, inside the
     * same transaction the real `submit()` uses (replicated here via a
     * `SAVEPOINT`, the same nesting idiom `HandlesTransactions` uses, since
     * `DatabaseTestCase` already has an outer transaction open) — then
     * proves the next real submission reclaims the wasted number.
     */
    public function testARolledBackAttemptLeavesNoGapInTheSequence(): void
    {
        $first = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        self::assertSame(sprintf('RDFP-ENQ-%d-00001', $this->year), $first['referenceNo']);

        $this->db->exec('SAVEPOINT sp_forced_failure');

        try {
            $this->counters->nextSequence('ENQ', $this->year);
            // A NOT NULL column ('phone') is missing — a genuine DB-level
            // failure, not a simulated one.
            $this->repository->create([
                'product_id' => null, 'customer_id' => null, 'name' => 'x', 'company_name' => null,
                'email' => 'x@example.test', 'city' => 'x', 'pack_size_label' => null, 'quantity' => null,
                'message' => 'x', 'source_page' => null, 'ip_address' => null, 'reference_no' => 'RDFP-ENQ-BAD',
            ]);

            self::fail('Expected the insert to fail on a missing NOT NULL column.');
        } catch (\PDOException) {
            $this->db->exec('ROLLBACK TO SAVEPOINT sp_forced_failure');
        }

        $retry = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());

        self::assertSame(sprintf('RDFP-ENQ-%d-00002', $this->year), $retry['referenceNo']);
    }

    public function testProductIdMustReferenceARealProduct(): void
    {
        $error = $this->captureApiError(fn () => $this->enquiries->submit(
            null,
            '203.0.113.5',
            $this->validInput() + ['product_id' => UlidHelper::generate()],
        ));

        self::assertSame('product_id', $error->details()[0]['field']);
    }

    public function testEmailMustLookLikeAnEmail(): void
    {
        // Override, not union: array `+` keeps the *left* side's value for a
        // duplicate key, so the replacement email has to come first.
        $error = $this->captureApiError(
            fn () => $this->enquiries->submit(null, '203.0.113.5', ['email' => 'not-an-email'] + $this->validInput())
        );

        self::assertSame('email', $error->details()[0]['field']);
    }

    public function testACustomerIdIsRecordedWhenTheVisitorIsSignedIn(): void
    {
        $customerId = $this->insertCustomer();

        $result = $this->enquiries->submit($customerId, '203.0.113.5', $this->validInput());
        $created = $this->repository->find($this->idForReference($result['referenceNo']));

        self::assertSame($customerId, $created['customer_id']);
    }

    public function testAdminFiltersByStatus(): void
    {
        $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $second = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $id = $this->idForReference($second['referenceNo']);
        $this->enquiries->update($id, ['status' => 'CLOSED']);

        $closed = $this->enquiries->paginate(['status' => 'CLOSED']);
        $new = $this->enquiries->paginate(['status' => 'NEW']);

        self::assertSame(1, $closed['meta']['total']);
        self::assertSame(1, $new['meta']['total']);
    }

    public function testAssignedToIdMustReferenceARealAdmin(): void
    {
        $result = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $id = $this->idForReference($result['referenceNo']);

        $error = $this->captureApiError(
            fn () => $this->enquiries->update($id, ['assigned_to_id' => UlidHelper::generate()])
        );

        self::assertSame('assigned_to_id', $error->details()[0]['field']);
    }

    public function testUpdatingStatusAndNotesTogether(): void
    {
        $result = $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $id = $this->idForReference($result['referenceNo']);

        $updated = $this->enquiries->update($id, ['status' => 'IN_PROGRESS', 'internal_notes' => 'Called, left a message.']);

        self::assertSame('IN_PROGRESS', $updated['status']);
        self::assertSame('Called, left a message.', $updated['internal_notes']);
    }

    public function testExportProducesACsvRowPerEnquiry(): void
    {
        $this->enquiries->submit(null, '203.0.113.5', $this->validInput());
        $this->enquiries->submit(null, '203.0.113.5', $this->validInput());

        $export = $this->enquiries->exportCsv([]);

        self::assertStringContainsString('enquiries-', $export['filename']);
        $lines = array_filter(explode("\n", trim($export['csv'])));
        self::assertCount(3, $lines); // header + 2 rows
        self::assertStringContainsString('Reference', $lines[0]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validInput(): array
    {
        return [
            'name' => 'Test Visitor', 'phone' => '+8801700000000', 'email' => 'visitor@example.test',
            'city' => 'Dhaka', 'message' => 'Interested in bulk pricing for this product.',
        ];
    }

    private function idForReference(string $referenceNo): string
    {
        $id = $this->db->prepare('SELECT id FROM product_enquiries WHERE reference_no = :ref');
        $id->execute([':ref' => $referenceNo]);

        return (string) $id->fetchColumn();
    }

    private function insertCustomer(): string
    {
        $id = UlidHelper::generate();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO customers (id, email, name, created_at, updated_at)
             VALUES (:id, :email, :name, :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':email' => 'test-' . bin2hex(random_bytes(6)) . '@example.test',
            ':name' => 'Test Customer', ':created_at' => $now, ':updated_at' => $now,
        ]);

        return $id;
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
