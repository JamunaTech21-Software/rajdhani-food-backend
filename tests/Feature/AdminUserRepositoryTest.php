<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\PasswordHelper;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\AdminUserRepository;

/**
 * `AdminUserRepository::activeEmailsForRole()` (RTPP-33) — the live
 * "Editor list" the "review submitted" notification (doc §14.4) reads.
 */
final class AdminUserRepositoryTest extends DatabaseTestCase
{
    private AdminUserRepository $admins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admins = new AdminUserRepository($this->db);
    }

    public function testReturnsOnlyActiveAdminsOfTheGivenRole(): void
    {
        $activeEditor = $this->insertAdmin('EDITOR', true);
        $inactiveEditor = $this->insertAdmin('EDITOR', false);
        $activeSales = $this->insertAdmin('SALES', true);

        $emails = $this->admins->activeEmailsForRole('EDITOR');

        self::assertContains($activeEditor, $emails);
        self::assertNotContains($inactiveEditor, $emails);
        self::assertNotContains($activeSales, $emails);
    }

    /** @return string the new admin's email */
    private function insertAdmin(string $role, bool $isActive): string
    {
        $id = UlidHelper::generate();
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.test';
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $this->db->prepare(
            'INSERT INTO admin_users (id, name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:id, :name, :email, :hash, :role, :is_active, :created_at, :updated_at)'
        )->execute([
            ':id' => $id, ':name' => 'Test Admin', ':email' => $email,
            ':hash' => PasswordHelper::hash('Rajdhani#Tea2026'), ':role' => $role,
            ':is_active' => $isActive ? 1 : 0, ':created_at' => $now, ':updated_at' => $now,
        ]);

        return $email;
    }
}
