<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use PDO;
use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Http\Request;
use Rajdhani\Middleware\AuditLog;
use Rajdhani\Repositories\AuditLogRepository;

/**
 * `AuditLog` middleware end to end against a real `audit_logs` table
 * (doc §3.1, §13; RTPP-35).
 *
 * The `state` closures below read a plain PHP variable rather than a real
 * repository — the middleware only cares that it gets *some* array back
 * before and after the handler runs, not where that array comes from, and a
 * captured variable is enough to prove the before/after/diff wiring without
 * depending on any one resource's schema.
 */
final class AuditLogTest extends DatabaseTestCase
{
    private AuditLogRepository $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = new AuditLogRepository($this->db);
    }

    public function testAnUpdateRecordsOnlyTheChangedFields(): void
    {
        $adminId = $this->insertAdmin();
        $state = ['title' => 'Old title', 'body' => 'A very long unrelated body'];

        $middleware = new AuditLog('Widget', state: function () use (&$state) {
            return $state;
        }, audit: $this->audit);

        $request = $this->request('PATCH');
        $request->setAttribute('id', 'widget-1');
        $request->setAttribute('admin_id', $adminId);

        $result = $middleware->handle($request, function () use (&$state) {
            $state = ['title' => 'New title', 'body' => 'A very long unrelated body'];

            return ['id' => 'widget-1', 'title' => 'New title', 'body' => 'A very long unrelated body'];
        });

        self::assertSame(['id' => 'widget-1', 'title' => 'New title', 'body' => 'A very long unrelated body'], $result);

        $row = $this->latestRow();
        self::assertSame('widget.update', $row['action']);
        self::assertSame('Widget', $row['entity_type']);
        self::assertSame('widget-1', $row['entity_id']);
        self::assertSame($adminId, $row['admin_id']);
        self::assertSame(['title' => 'Old title'], json_decode((string) $row['before_json'], true));
        self::assertSame(['title' => 'New title'], json_decode((string) $row['after_json'], true));
    }

    public function testACreateHasNoBeforeAndUsesTheHandlersReturnValueAsAfter(): void
    {
        $adminId = $this->insertAdmin();
        $middleware = new AuditLog('Widget', audit: $this->audit);

        $request = $this->request('POST');
        $request->setAttribute('admin_id', $adminId);

        $result = $middleware->handle($request, fn () => ['id' => 'widget-2', 'title' => 'Brand new']);

        self::assertSame(['id' => 'widget-2', 'title' => 'Brand new'], $result);

        $row = $this->latestRow();
        self::assertSame('widget.create', $row['action']);
        self::assertSame('widget-2', $row['entity_id']);
        self::assertNull($row['before_json']);
        self::assertSame(['id' => 'widget-2', 'title' => 'Brand new'], json_decode((string) $row['after_json'], true));
    }

    /** The same closure that found the row before deletion finds nothing the second time — no DELETE-specific branch needed. */
    public function testADeleteHasNoAfterOnceTheStateClosureNoLongerFindsTheRow(): void
    {
        $adminId = $this->insertAdmin();
        $state = ['id' => 'widget-3', 'title' => 'About to go'];

        $middleware = new AuditLog('Widget', state: function () use (&$state) {
            return $state;
        }, audit: $this->audit);

        $request = $this->request('DELETE');
        $request->setAttribute('id', 'widget-3');
        $request->setAttribute('admin_id', $adminId);

        $result = $middleware->handle($request, function () use (&$state) {
            $state = null;

            return ['deleted' => true];
        });

        self::assertSame(['deleted' => true], $result);

        $row = $this->latestRow();
        self::assertSame('widget.delete', $row['action']);
        self::assertSame('widget-3', $row['entity_id']);
        self::assertSame(['id' => 'widget-3', 'title' => 'About to go'], json_decode((string) $row['before_json'], true));
        self::assertNull($row['after_json']);
    }

    public function testASingletonResourceReReadsStateDespiteHavingNoIdAttribute(): void
    {
        $adminId = $this->insertAdmin();
        $state = ['name' => 'Old Co'];

        $middleware = new AuditLog('SiteProfile', state: function () use (&$state) {
            return $state;
        }, singleton: true, audit: $this->audit);

        $request = $this->request('PATCH');
        $request->setAttribute('admin_id', $adminId);

        $middleware->handle($request, function () use (&$state) {
            $state = ['name' => 'New Co'];

            return ['name' => 'New Co'];
        });

        $row = $this->latestRow();
        self::assertSame('siteprofile.update', $row['action']);
        self::assertNull($row['entity_id']);
        self::assertSame(['name' => 'Old Co'], json_decode((string) $row['before_json'], true));
        self::assertSame(['name' => 'New Co'], json_decode((string) $row['after_json'], true));
    }

    /** A reorder/bulk route has no id in its path and no single row to re-read — it still writes exactly one row. */
    public function testARouteWithNoStateAndAnOverriddenActionStillWritesOneRow(): void
    {
        $adminId = $this->insertAdmin();
        $middleware = new AuditLog('Widget', action: 'reorder', audit: $this->audit);

        $request = $this->request('PATCH');
        $request->setAttribute('admin_id', $adminId);

        $middleware->handle($request, fn () => ['data' => ['widget-1', 'widget-2']]);

        $row = $this->latestRow();
        self::assertSame('widget.reorder', $row['action']);
        self::assertNull($row['entity_id']);
        self::assertNull($row['before_json']);
    }

    public function testAMutationWithNoAuthenticatedAdminAttributeRecordsANullActor(): void
    {
        $middleware = new AuditLog('Widget', audit: $this->audit);

        $middleware->handle($this->request('POST'), fn () => ['id' => 'widget-4']);

        $row = $this->latestRow();
        self::assertNull($row['admin_id']);
    }

    private function request(string $method): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/api/v1/admin/widgets';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        unset($_SERVER['CONTENT_TYPE']);
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];

        return Request::capture();
    }

    /** @return array<string,mixed> */
    private function latestRow(): array
    {
        $row = $this->db->query('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
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
