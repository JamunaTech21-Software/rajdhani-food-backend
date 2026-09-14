<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Services\SettingsService;

/**
 * `GET`/`PUT /admin/settings` (doc §8.2, §9.8; RTPP-32), against the real,
 * already-seeded `settings` table (`SettingsSeeder`).
 */
final class SettingsTest extends DatabaseTestCase
{
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingsService(new SettingsRepository($this->db));
    }

    public function testIndexReturnsTheSeededKeys(): void
    {
        $rows = $this->settings->index();
        $keys = array_column($rows, 'key');

        self::assertContains('enquiry_notify_emails', $keys);
        self::assertContains('dealer_notify_emails', $keys);
        self::assertContains('products_per_page', $keys);
    }

    public function testUpdatingAnExistingKeyChangesItsValue(): void
    {
        $this->settings->update(['settings' => [
            ['key' => 'enquiry_notify_emails', 'value' => 'sales@example.test,manager@example.test'],
        ]]);

        $rows = $this->settings->index();
        $row = current(array_filter($rows, static fn (array $r): bool => $r['key'] === 'enquiry_notify_emails'));

        self::assertSame('sales@example.test,manager@example.test', $row['value']);
    }

    public function testUpdatingSeveralKeysAtOnce(): void
    {
        $this->settings->update(['settings' => [
            ['key' => 'products_per_page', 'value' => '24'],
            ['key' => 'news_per_page', 'value' => '6'],
        ]]);

        $rows = $this->settings->index();
        $byKey = array_column($rows, 'value', 'key');

        self::assertSame('24', $byKey['products_per_page']);
        self::assertSame('6', $byKey['news_per_page']);
    }

    public function testAnUnknownKeyIsRejectedRatherThanSilentlyCreated(): void
    {
        $error = $this->captureApiError(fn () => $this->settings->update(['settings' => [
            ['key' => 'this_key_does_not_exist', 'value' => 'x'],
        ]]));

        self::assertSame(422, $error->status());

        $statement = $this->db->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :key');
        $statement->execute([':key' => 'this_key_does_not_exist']);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testUpdatingWithAnEmptyListIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->settings->update(['settings' => []]));

        self::assertSame(422, $error->status());
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
