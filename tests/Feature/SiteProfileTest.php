<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Repositories\NavigationRepository;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Repositories\SiteProfileRepository;
use Rajdhani\Services\SiteProfileService;

/**
 * The site profile singleton and the layout payload (doc §6, §9.3, §18.2).
 *
 * These run inside the surrounding transaction, so a test that changes the
 * site's colours changes them back by rolling back rather than by remembering to.
 */
final class SiteProfileTest extends DatabaseTestCase
{
    private SiteProfileService $service;
    private SiteProfileRepository $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profiles = new SiteProfileRepository($this->db);
        $this->service = new SiteProfileService(
            $this->profiles,
            new NavigationRepository($this->db),
            new SettingsRepository($this->db),
        );

        if ($this->profiles->find() === null) {
            self::markTestSkipped('Site profile not seeded. Run: php bin/seed.php');
        }
    }

    public function testTheLayoutCarriesEverythingTheFrontEndBootsFrom(): void
    {
        $layout = $this->service->layout();

        self::assertSame(
            ['site', 'menus', 'social', 'newsletter'],
            array_keys($layout),
        );

        $site = $layout['site'];
        self::assertIsArray($site);
        self::assertNotSame('', $site['name']);

        foreach (['logos', 'theme', 'contact', 'map', 'footer', 'seo'] as $block) {
            self::assertArrayHasKey($block, $site, "layout.site.{$block} is missing");
        }

        self::assertSame(['primary', 'secondary', 'accent'], array_keys($site['theme']));
    }

    /**
     * §18.2, the acceptance criterion this endpoint exists to satisfy: the
     * client changes a colour and the site changes, with no deployment.
     */
    public function testChangingAColourChangesWhatTheEndpointReturns(): void
    {
        $before = $this->service->layout()['site']['theme']['primary'];

        $this->profiles->update(['primary_color' => '#8E24AA']);

        // A new service instance is what the next HTTP request gets: the memo
        // inside one instance is per-request and must not outlive it.
        $after = $this->freshService()->layout()['site']['theme']['primary'];

        self::assertNotSame($before, $after);
        self::assertSame('#8E24AA', $after);
    }

    /** The same, through the write path an editor actually uses. */
    public function testUpdatingThroughTheServiceIsVisibleImmediately(): void
    {
        $updated = $this->service->update(['primary_color' => '#0D47A1', 'tagline' => 'Changed by a test']);

        self::assertSame('#0D47A1', $updated['theme']['primary']);
        self::assertSame('Changed by a test', $updated['tagline']);

        // The memo was dropped, so the same instance reports the new value.
        self::assertSame('#0D47A1', $this->service->layout()['site']['theme']['primary']);
    }

    public function testOptionalFieldsComeBackAsNullRatherThanBreaking(): void
    {
        $this->profiles->update([
            'tagline'         => null,
            'logo_dark_id'    => null,
            'phone_secondary' => null,
            'map_latitude'    => null,
            'map_longitude'   => null,
            'map_embed_url'   => null,
        ]);

        $site = $this->freshService()->layout()['site'];

        self::assertNull($site['tagline']);
        self::assertNull($site['logos']['dark']);
        self::assertNull($site['contact']['phone_secondary']);

        // Null, not 0.0 — (0, 0) is a real place in the Gulf of Guinea, and a
        // map centred there is worse than one the front-end knows to hide.
        self::assertNull($site['map']['latitude']);
        self::assertNull($site['map']['longitude']);
    }

    public function testImagesCarryBothTheIdAndTheUrl(): void
    {
        $light = $this->service->layout()['site']['logos']['light'];

        self::assertIsArray($light, 'The seeded profile should have a light logo');
        self::assertSame(['id', 'url', 'alt'], array_keys($light));
        self::assertStringStartsWith('http', $light['url']);
    }

    public function testMenusAreGroupedAndAlwaysPresent(): void
    {
        $menus = $this->service->layout()['menus'];

        foreach (['header', 'footer_quick', 'footer_products', 'legal'] as $location) {
            self::assertArrayHasKey($location, $menus);
            self::assertIsArray($menus[$location]);
        }

        self::assertNotSame([], $menus['header']);
    }

    /**
     * A location whose links are all deactivated must still be an empty array,
     * not a missing key — the front-end indexes into it either way.
     */
    public function testAnEmptiedMenuLocationIsStillPresent(): void
    {
        $this->db->exec("UPDATE menu_links SET is_active = 0 WHERE location = 'legal'");

        $menus = $this->freshService()->layout()['menus'];

        self::assertArrayHasKey('legal', $menus);
        self::assertSame([], $menus['legal']);
    }

    public function testInactiveLinksAreExcluded(): void
    {
        $before = count($this->service->layout()['menus']['header']);

        $this->db->exec("UPDATE menu_links SET is_active = 0 WHERE location = 'header' LIMIT 1");

        self::assertSame($before - 1, count($this->freshService()->layout()['menus']['header']));
    }

    // ─── writes ─────────────────────────────────────────────────────────────

    /**
     * The singleton is never inserted into and never multiplied. `CHECK (id = 1)`
     * is the backstop; this asserts the code does not rely on it.
     */
    public function testUpdatingNeverAddsARow(): void
    {
        $this->service->update(['city' => 'Chattogram']);

        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM site_profile')?->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT id FROM site_profile')?->fetchColumn());
    }

    public function testOnlyAllowlistedColumnsAreWritable(): void
    {
        $this->service->update(['name' => 'Renamed', 'id' => 2, 'created_at' => '1999-01-01 00:00:00.000']);

        $row = $this->profiles->find();
        self::assertIsArray($row);
        self::assertSame('Renamed', $row['name']);
        self::assertSame(1, (int) $row['id']);
        self::assertStringStartsNotWith('1999', (string) $row['created_at']);
    }

    public function testSendingNothingEditableIsRejected(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->service->update(['id' => 2]);
    }

    /** @param array<string,mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(array $input): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->service->update($input);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidInput(): array
    {
        return [
            'colour without a hash'   => [['primary_color' => '1B5E20']],
            'colour that is a word'   => [['primary_color' => 'red']],
            // 5 and 7 digits are the lengths CSS has no meaning for; #1B5 is a
            // legal three-digit colour and is in the accepted set below.
            'colour of five digits'   => [['primary_color' => '#1B5E2']],
            'colour of seven digits'  => [['primary_color' => '#1B5E20F']],
            'malformed email'         => [['email_primary' => 'not-an-email']],
            'latitude out of range'   => [['map_latitude' => 999]],
            'longitude out of range'  => [['map_longitude' => -181]],
            'latitude not a number'   => [['map_latitude' => 'north']],
            'name cleared'            => [['name' => '']],
            'name too long'           => [['name' => str_repeat('a', 256)]],
            'array where text wanted' => [['city' => ['Dhaka']]],
        ];
    }

    /** @param array<string,mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('validColours')]
    public function testEveryHexColourFormatIsAccepted(string $colour, string $stored): void
    {
        self::assertSame($stored, $this->service->update(['primary_color' => $colour])['theme']['primary']);
    }

    /** @return array<string,array{string,string}> */
    public static function validColours(): array
    {
        return [
            'three digit'      => ['#1b5', '#1B5'],
            'six digit'        => ['#1b5e20', '#1B5E20'],
            'eight with alpha' => ['#1b5e20ff', '#1B5E20FF'],
        ];
    }

    /** An empty string clears an optional field rather than storing '' in it. */
    public function testAnEmptyStringClearsAnOptionalField(): void
    {
        $this->service->update(['tagline' => 'Something']);

        self::assertNull($this->service->update(['tagline' => ''])['tagline']);
    }

    public function testCoordinatesRoundTripAsNumbers(): void
    {
        $map = $this->service->update(['map_latitude' => '23.7937', 'map_longitude' => 90.4066])['map'];

        self::assertSame(23.7937, $map['latitude']);
        self::assertSame(90.4066, $map['longitude']);
    }

    public function testNewsletterVisibilityComesFromSettings(): void
    {
        self::assertTrue($this->service->layout()['newsletter']['enabled']);

        $this->db->exec("UPDATE settings SET value = '0' WHERE `key` = 'newsletter_enabled'");

        self::assertFalse($this->freshService()->layout()['newsletter']['enabled']);
    }

    private function freshService(): SiteProfileService
    {
        return new SiteProfileService(
            $this->profiles,
            new NavigationRepository($this->db),
            new SettingsRepository($this->db),
        );
    }
}
