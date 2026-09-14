<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\AuditLogRepository;
use Rajdhani\Services\AuditService;

/** The `GET /admin/audit-logs` query API — filters and pagination (RTPP-35). */
final class AuditServiceTest extends DatabaseTestCase
{
    private AuditLogRepository $logs;
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new AuditLogRepository($this->db);
        $this->audit = new AuditService($this->logs);
    }

    public function testFiltersByResourceAndExposesTheDiffAsBeforeAndAfter(): void
    {
        $admin = $this->insertAdmin();
        $this->logs->record($admin, 'banner.create', 'Banner', 'b1', null, ['title' => 'A'], '203.0.113.1');
        $this->logs->record($admin, 'product.update', 'Product', 'p1', ['title' => 'Old'], ['title' => 'New'], '203.0.113.1');

        $result = $this->audit->paginate(['resource' => 'Banner']);

        self::assertCount(1, $result['data']);
        self::assertSame('Banner', $result['data'][0]['entity_type']);
        self::assertSame('banner.create', $result['data'][0]['action']);
        self::assertNull($result['data'][0]['before']);
        self::assertSame(['title' => 'A'], $result['data'][0]['after']);
    }

    public function testFiltersByActor(): void
    {
        $adminA = $this->insertAdmin();
        $adminB = $this->insertAdmin();
        $this->logs->record($adminA, 'banner.update', 'Banner', 'b1', [], [], '203.0.113.1');
        $this->logs->record($adminB, 'banner.update', 'Banner', 'b2', [], [], '203.0.113.1');

        $result = $this->audit->paginate(['actor' => $adminA]);

        self::assertCount(1, $result['data']);
        self::assertSame($adminA, $result['data'][0]['admin_id']);
    }

    public function testFiltersByAction(): void
    {
        $admin = $this->insertAdmin();
        $this->logs->record($admin, 'banner.create', 'Banner', 'b1', null, [], '203.0.113.1');
        $this->logs->record($admin, 'banner.delete', 'Banner', 'b1', [], null, '203.0.113.1');

        $result = $this->audit->paginate(['action' => 'banner.delete']);

        self::assertCount(1, $result['data']);
        self::assertSame('banner.delete', $result['data'][0]['action']);
    }

    /** A bare `to` date must include the whole day it names, not stop at its midnight. */
    public function testABareToDateIncludesTheWholeDay(): void
    {
        $admin = $this->insertAdmin();
        $this->logs->record($admin, 'banner.update', 'Banner', 'b1', [], [], '203.0.113.1');

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $result = $this->audit->paginate(['from' => $today, 'to' => $today]);

        self::assertCount(1, $result['data']);
    }

    public function testADateRangeThatExcludesTodayFindsNothing(): void
    {
        $admin = $this->insertAdmin();
        $this->logs->record($admin, 'banner.update', 'Banner', 'b1', [], [], '203.0.113.1');

        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d');

        $result = $this->audit->paginate(['from' => $yesterday, 'to' => $yesterday]);

        self::assertCount(0, $result['data']);
    }

    public function testRejectsAMalformedDate(): void
    {
        $this->expectException(ApiError::class);

        $this->audit->paginate(['from' => 'not-a-date']);
    }

    public function testResultsAreNewestFirst(): void
    {
        $admin = $this->insertAdmin();
        $this->logs->record($admin, 'banner.create', 'Banner', 'first', null, [], '203.0.113.1');
        usleep(2000);
        $this->logs->record($admin, 'banner.create', 'Banner', 'second', null, [], '203.0.113.1');

        $result = $this->audit->paginate(['resource' => 'Banner']);

        self::assertSame('second', $result['data'][0]['entity_id']);
        self::assertSame('first', $result['data'][1]['entity_id']);
    }

    private function insertAdmin(): string
    {
        $id = UlidHelper::generate();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO admin_users (id, name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:id, :name, :email, :hash, :role, 1, :created_at, :updated_at)'
        )->execute([
            ':id'         => $id,
            ':name'       => 'Test Admin',
            ':email'      => 'test-' . bin2hex(random_bytes(6)) . '@example.test',
            ':hash'       => PasswordHelper::hash('Rajdhani#Tea2026'),
            ':role'       => 'SUPER_ADMIN',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $id;
    }
}
