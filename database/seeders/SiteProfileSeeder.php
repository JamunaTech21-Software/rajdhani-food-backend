<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * The one `site_profile` row (doc 8.2).
 *
 * The table is a singleton pinned by `CHECK (id = 1)`, which is what replaced
 * the `brands` table when the project dropped to a single site (doc 19,
 * deviation 5). Everything the header, footer and contact page need — name,
 * colours, address, map, copyright — lives here rather than in code, so the
 * client can change it without a deployment.
 *
 * Written insert-if-absent. Once someone has typed a real phone number into the
 * admin panel, a re-run must not put the placeholder back.
 */
final class SiteProfileSeeder extends Seeder
{
    public function tables(): array
    {
        return ['site_profile'];
    }

    public function run(): void
    {
        /** @var array{site_profile:array<string,scalar|null>} $content */
        $content = $this->seedData('site-content.json');
        $profile = $content['site_profile'];
        $now = $this->now();

        $this->upsertWithKey('site_profile', [
            'id'      => 1,
            'name'    => $profile['name'],
            'tagline' => $profile['tagline'],

            'logo_light_id' => $this->mediaId('logo-light'),
            'logo_dark_id'  => $this->mediaId('logo-dark'),
            'favicon_id'    => $this->mediaId('favicon'),
            'og_image_id'   => $this->mediaId('og-image'),

            // The green and gold of the surviving brand. The withdrawn red/gold
            // site is not part of this project (doc 19, deviation 5).
            'primary_color'   => $profile['primary_color'],
            'secondary_color' => $profile['secondary_color'],
            'accent_color'    => $profile['accent_color'],

            'address_line'    => $profile['address_line'],
            'city'            => $profile['city'],
            'country'         => $profile['country'],
            'phone_primary'   => $profile['phone_primary'],
            'phone_secondary' => $profile['phone_secondary'],
            'email_primary'   => $profile['email_primary'],
            'email_secondary' => $profile['email_secondary'],
            'website_url'     => $profile['website_url'],
            'business_hours'  => $profile['business_hours'],
            'map_latitude'    => $profile['map_latitude'],
            'map_longitude'   => $profile['map_longitude'],
            'map_embed_url'   => null,

            'footer_about'   => $profile['footer_about'],
            'copyright_text' => sprintf(
                '© %s %s. All rights reserved.',
                date('Y'),
                is_scalar($profile['name']) ? (string) $profile['name'] : 'Rajdhani Food Products',
            ),

            'meta_title'       => $profile['meta_title'],
            'meta_description' => $profile['meta_description'],

            'created_at' => $now,
            'updated_at' => $now,
        // No updatable columns: on a second run the statement resolves to a
        // no-op rather than overwriting whatever the client has since entered.
        ], updatable: []);
    }
}
