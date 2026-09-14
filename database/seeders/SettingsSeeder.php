<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * Default rows in `settings` (doc 8.2).
 *
 * The table is a key/value store for the handful of values that are neither
 * secrets nor content: who gets notified about a submission, which analytics
 * ids are in use, how many products fill a page. Secrets stay in `.env`
 * (doc 15) — nothing here is credential material, because these values are
 * editable from the admin panel and readable by anyone who can reach it.
 *
 * Upserted rather than insert-if-absent for the *keys* only: a missing key would
 * make the reading code fall back to a hard-coded default, which is exactly the
 * behaviour this table exists to remove. Values default to empty and are set by
 * the client; because a key that already exists keeps its value, re-running adds
 * newly introduced keys without disturbing configured ones.
 */
final class SettingsSeeder extends Seeder
{
    /** key => [value, group] */
    private const DEFAULTS = [
        // Recipients for the four public forms (doc 10). Comma-separated;
        // empty means "fall back to site_profile.email_primary".
        'enquiry_notify_emails'    => ['', 'notifications'],
        'dealer_notify_emails'     => ['', 'notifications'],
        'contact_notify_emails'    => ['', 'notifications'],
        'newsletter_notify_emails' => ['', 'notifications'],

        'gtm_id'             => ['', 'analytics'],
        'ga4_measurement_id' => ['', 'analytics'],
        'facebook_pixel_id'  => ['', 'analytics'],

        // Read by GET /public/layout so the footer knows whether to render
        // the subscribe form (doc §9.3, "newsletter config").
        'newsletter_enabled' => ['1', 'general'],

        'products_per_page'        => ['12', 'general'],
        'news_per_page'            => ['9', 'general'],
        'gallery_images_per_page'  => ['24', 'general'],

        // Shown in the dealer application success modal (doc §10.3, RTPP-30).
        // Not hard-coded: the document names no confirmed SLA text, and this
        // is client-facing copy the same way the notify-email lists are.
        'dealer_application_response_window' => ['2-3 business days', 'general'],

        // reCAPTCHA v3's score is 0.0 (bot) to 1.0 (human); the document
        // says only "a score threshold" (§14.2) with no confirmed number.
        // Admin-configurable rather than a code constant, the same "not
        // hard-coded" treatment every other undecided business value in
        // this table gets. 0.5 is a conventional starting point, not a
        // number the client has confirmed.
        'recaptcha_score_threshold' => ['0.5', 'general'],

        // Reviews are moderated by default (doc 10.5): a review appears only
        // after an editor approves it.
        'reviews_require_approval' => ['1', 'general'],
        'maintenance_mode'         => ['0', 'general'],
    ];

    public function tables(): array
    {
        return ['settings'];
    }

    public function run(): void
    {
        $now = $this->now();

        foreach (self::DEFAULTS as $key => [$value, $group]) {
            $this->insertIfAbsent(
                'settings',
                ['key' => $key],
                ['value' => $value, 'group' => $group, 'updated_at' => $now],
            );
        }
    }
}
