<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Repositories\SiteProfileRepository;

/**
 * Resolves a `settings`-configured recipient list (doc §14.4, §19 item 4;
 * RTPP-33) — never hardcoded, and never empty-by-accident: a blank list
 * falls back to `site_profile.email_primary` rather than silently notifying
 * no one, exactly the resolution recorded for §19 item 4 ("stay empty by
 * default and fall back to `site_profile.email_primary`").
 */
final class NotificationRecipients
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly SiteProfileRepository $profiles = new SiteProfileRepository(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function forSetting(string $key): array
    {
        $value = trim((string) $this->settings->get($key, ''));

        if ($value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $e): bool => $e !== ''));
        }

        $profile = $this->profiles->find();
        $fallback = $profile['email_primary'] ?? null;

        return is_string($fallback) && $fallback !== '' ? [$fallback] : [];
    }
}
