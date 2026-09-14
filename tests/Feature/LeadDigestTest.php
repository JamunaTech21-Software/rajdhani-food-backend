<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\LeadDigestRepository;

/**
 * `LeadDigestRepository::weeklyCounts()` (doc §13, §14.4; RTPP-33) — the
 * counts `Jobs\LeadDigest` sends. Each table gets one row inside the
 * 7-day window and one row 8 days ago, so the boundary itself is what's
 * under test, not just "some number came back".
 */
final class LeadDigestTest extends DatabaseTestCase
{
    private LeadDigestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new LeadDigestRepository($this->db);
    }

    public function testCountsOnlyRowsFromTheLastSevenDays(): void
    {
        $this->insertEnquiry($this->daysAgo(2));
        $this->insertEnquiry($this->daysAgo(8));

        $this->insertContactMessage($this->daysAgo(1));
        $this->insertContactMessage($this->daysAgo(10));

        $this->insertNewsletterSubscriber($this->daysAgo(3));
        $this->insertNewsletterSubscriber($this->daysAgo(9));

        [$districtId, $upazilaId] = $this->firstDistrictAndUpazila();
        $this->insertDealerApplication($districtId, $upazilaId, $this->daysAgo(4));
        $this->insertDealerApplication($districtId, $upazilaId, $this->daysAgo(9));

        $counts = $this->repository->weeklyCounts();

        self::assertSame(1, $counts['enquiries']);
        self::assertSame(1, $counts['applications']);
        self::assertSame(1, $counts['messages']);
        self::assertSame(1, $counts['subscribers']);
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s.v');
    }

    /** @return array{0:string,1:string} district id, upazila id */
    private function firstDistrictAndUpazila(): array
    {
        $districtId = (string) $this->db->query('SELECT id FROM districts ORDER BY name LIMIT 1')->fetchColumn();
        $statement = $this->db->prepare('SELECT id FROM upazilas WHERE district_id = :district_id ORDER BY name LIMIT 1');
        $statement->execute([':district_id' => $districtId]);

        return [$districtId, (string) $statement->fetchColumn()];
    }

    private function insertEnquiry(string $createdAt): void
    {
        $this->db->prepare(
            'INSERT INTO product_enquiries (id, reference_no, name, phone, email, city, message, created_at, updated_at)
             VALUES (:id, :ref, :name, :phone, :email, :city, :message, :created_at, :updated_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':ref' => 'RDFP-ENQ-TEST-' . bin2hex(random_bytes(3)),
            ':name' => 'Test', ':phone' => '+8801700000000', ':email' => 'test@example.test',
            ':city' => 'Dhaka', ':message' => 'x', ':created_at' => $createdAt, ':updated_at' => $createdAt,
        ]);
    }

    private function insertDealerApplication(string $districtId, string $upazilaId, string $createdAt): void
    {
        $this->db->prepare(
            'INSERT INTO dealer_applications
                (id, application_id, full_name, company_name, phone, email, district_id, upazila_id, created_at, updated_at)
             VALUES (:id, :app_id, :name, :company, :phone, :email, :district_id, :upazila_id, :created_at, :updated_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':app_id' => 'RDFP-DA-TEST-' . bin2hex(random_bytes(3)),
            ':name' => 'Test', ':company' => 'Test Co', ':phone' => '+8801700000000', ':email' => 'test@example.test',
            ':district_id' => $districtId, ':upazila_id' => $upazilaId,
            ':created_at' => $createdAt, ':updated_at' => $createdAt,
        ]);
    }

    private function insertContactMessage(string $createdAt): void
    {
        $this->db->prepare(
            'INSERT INTO contact_messages (id, name, email, message, created_at)
             VALUES (:id, :name, :email, :message, :created_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':name' => 'Test', ':email' => 'test@example.test',
            ':message' => 'x', ':created_at' => $createdAt,
        ]);
    }

    private function insertNewsletterSubscriber(string $subscribedAt): void
    {
        $this->db->prepare(
            'INSERT INTO newsletter_subscribers (id, email, unsubscribe_token, subscribed_at)
             VALUES (:id, :email, :token, :subscribed_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':email' => 'test-' . bin2hex(random_bytes(4)) . '@example.test',
            ':token' => bin2hex(random_bytes(32)), ':subscribed_at' => $subscribedAt,
        ]);
    }
}
