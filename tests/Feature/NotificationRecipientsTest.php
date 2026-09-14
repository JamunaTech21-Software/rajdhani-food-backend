<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Repositories\SiteProfileRepository;
use Rajdhani\Support\NotificationRecipients;

/**
 * Recipient resolution (doc §14.4, §19 item 4; RTPP-33) against the real,
 * already-seeded `settings` and `site_profile` tables.
 */
final class NotificationRecipientsTest extends DatabaseTestCase
{
    private NotificationRecipients $recipients;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipients = new NotificationRecipients(
            new SettingsRepository($this->db),
            new SiteProfileRepository($this->db),
        );
    }

    public function testACommaSeparatedListIsSplitAndTrimmed(): void
    {
        $this->setSetting('enquiry_notify_emails', 'sales@example.test, manager@example.test ,  ');

        $result = $this->recipients->forSetting('enquiry_notify_emails');

        self::assertSame(['sales@example.test', 'manager@example.test'], $result);
    }

    /**
     * The exact §19 item 4 resolution: "stay empty by default and fall
     * back to `site_profile.email_primary`."
     */
    public function testAnEmptyListFallsBackToTheSiteProfilePrimaryEmail(): void
    {
        $this->setSetting('enquiry_notify_emails', '');

        $result = $this->recipients->forSetting('enquiry_notify_emails');

        $profileEmail = $this->db->query('SELECT email_primary FROM site_profile LIMIT 1')->fetchColumn();

        if (is_string($profileEmail) && $profileEmail !== '') {
            self::assertSame([$profileEmail], $result);
        } else {
            self::assertSame([], $result);
        }
    }

    private function setSetting(string $key, string $value): void
    {
        // MySQL's UPDATE only counts a row as "affected" when the value
        // actually changes, so rowCount() cannot tell "not found" apart
        // from "already had this value" — check existence separately.
        $exists = $this->db->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :key');
        $exists->execute([':key' => $key]);
        self::assertSame(1, (int) $exists->fetchColumn(), "Fixture setting '{$key}' was not found — check SettingsSeeder.");

        $this->db->prepare('UPDATE settings SET value = :value WHERE `key` = :key')
            ->execute([':value' => $value, ':key' => $key]);
    }
}
