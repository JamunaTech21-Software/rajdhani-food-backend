<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Auth\Role;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\DashboardRepository;
use Rajdhani\Services\DashboardService;

/** `GET /admin/dashboard/summary` (doc §9, §11; RTPP-36). */
final class DashboardServiceTest extends DatabaseTestCase
{
    private DashboardService $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = new DashboardService(new DashboardRepository($this->db));
    }

    public function testCountsReflectOnlyEachTablesNotYetActionedStatus(): void
    {
        $this->insertEnquiry('NEW');
        $this->insertEnquiry('CLOSED');

        [$districtId, $upazilaId] = $this->firstDistrictAndUpazila();
        $this->insertDealerApplication($districtId, $upazilaId, 'SUBMITTED');
        $this->insertDealerApplication($districtId, $upazilaId, 'APPROVED');

        $this->insertContactMessage('UNREAD');
        $this->insertContactMessage('READ');

        $summary = $this->dashboard->summary(Role::SUPER_ADMIN);

        self::assertSame(1, $summary['counts']['new_enquiries']);
        self::assertSame(1, $summary['counts']['new_applications']);
        self::assertSame(1, $summary['counts']['unread_messages']);
    }

    public function testSalesSeesNoPendingReviewsCountButEveryoneElseDoes(): void
    {
        $sales = $this->dashboard->summary(Role::SALES);
        $editor = $this->dashboard->summary(Role::EDITOR);
        $superAdmin = $this->dashboard->summary(Role::SUPER_ADMIN);

        self::assertArrayNotHasKey('pending_reviews', $sales['counts']);
        self::assertArrayHasKey('pending_reviews', $editor['counts']);
        self::assertArrayHasKey('pending_reviews', $superAdmin['counts']);
    }

    public function testTheChartIsZeroFilledAcrossTheWholeWindowNotJustDaysWithData(): void
    {
        $this->insertEnquiry('NEW');

        $summary = $this->dashboard->summary(Role::SUPER_ADMIN);

        self::assertCount(30, $summary['chart']);
        self::assertSame(
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $summary['chart'][29]['date'],
        );

        foreach ($summary['chart'] as $day) {
            self::assertArrayHasKey('enquiries', $day);
            self::assertArrayHasKey('applications', $day);
            self::assertArrayHasKey('messages', $day);
        }
    }

    public function testRecentLeadsAreMergedFromAllThreeTablesNewestFirst(): void
    {
        $this->insertContactMessage('UNREAD', name: 'Older Lead', createdAt: $this->minutesAgo(10));
        $this->insertEnquiry('NEW', name: 'Newer Lead', createdAt: $this->minutesAgo(1));

        $summary = $this->dashboard->summary(Role::SUPER_ADMIN);

        $names = array_column($summary['recent_leads'], 'name');
        $newerPosition = array_search('Newer Lead', $names, true);
        $olderPosition = array_search('Older Lead', $names, true);

        self::assertNotFalse($newerPosition);
        self::assertNotFalse($olderPosition);
        self::assertLessThan($olderPosition, $newerPosition);
    }

    private function minutesAgo(int $minutes): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$minutes} minutes")
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

    private function insertEnquiry(string $status, string $name = 'Test', ?string $createdAt = null): void
    {
        $now = $createdAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO product_enquiries (id, reference_no, name, phone, email, city, message, status, created_at, updated_at)
             VALUES (:id, :ref, :name, :phone, :email, :city, :message, :status, :created_at, :updated_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':ref' => 'RDFP-ENQ-TEST-' . bin2hex(random_bytes(3)),
            ':name' => $name, ':phone' => '+8801700000000', ':email' => 'test@example.test',
            ':city' => 'Dhaka', ':message' => 'x', ':status' => $status, ':created_at' => $now, ':updated_at' => $now,
        ]);
    }

    private function insertDealerApplication(string $districtId, string $upazilaId, string $status): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO dealer_applications
                (id, application_id, full_name, company_name, phone, email, district_id, upazila_id, status, created_at, updated_at)
             VALUES (:id, :app_id, :name, :company, :phone, :email, :district_id, :upazila_id, :status, :created_at, :updated_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':app_id' => 'RDFP-DA-TEST-' . bin2hex(random_bytes(3)),
            ':name' => 'Test', ':company' => 'Test Co', ':phone' => '+8801700000000', ':email' => 'test@example.test',
            ':district_id' => $districtId, ':upazila_id' => $upazilaId, ':status' => $status,
            ':created_at' => $now, ':updated_at' => $now,
        ]);
    }

    private function insertContactMessage(string $status, string $name = 'Test', ?string $createdAt = null): void
    {
        $now = $createdAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO contact_messages (id, name, email, message, status, created_at)
             VALUES (:id, :name, :email, :message, :status, :created_at)'
        )->execute([
            ':id' => UlidHelper::generate(), ':name' => $name, ':email' => 'test@example.test',
            ':message' => 'x', ':status' => $status, ':created_at' => $now,
        ]);
    }
}
