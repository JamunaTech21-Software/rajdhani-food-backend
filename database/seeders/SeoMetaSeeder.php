<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * A default `seo_meta` row for every public page key (doc 8.2, 11).
 *
 * The SPA front-end cannot supply these to a social scraper — Facebook and
 * WhatsApp do not run JavaScript, so a shared link renders from whatever the
 * server puts in the HTML head. The PHP shell renderer (RTPP-73) reads this
 * table to fill that head, which means a page key with no row here produces a
 * blank preview rather than a poor one.
 *
 * Seeding one row per key up front is therefore not convenience: it is the
 * difference between an editor *editing* a page's metadata and having to know
 * that it needs creating first.
 */
final class SeoMetaSeeder extends Seeder
{
    /** page_key => [title, description] */
    private const PAGES = [
        'home'     => ['Rajdhani Food Products — Premium Tea from Bangladesh', 'Premium tea blended and packed in Bangladesh — gold blend, classic black, green tea and more.'],
        'about'    => ['About Us — Rajdhani Food Products', 'Twenty-five years of blending and packing tea from the finest gardens of Bangladesh.'],
        'products' => ['Our Tea — Rajdhani Food Products', 'Browse the full range: premium blends, classic black, green tea, tea bags and loose leaf.'],
        'quality'  => ['Quality & Certifications — Rajdhani Food Products', 'How we source, test and pack every batch, and the standards we hold ourselves to.'],
        'dealer'   => ['Become a Dealer — Rajdhani Food Products', 'Join a dealer network that reaches all 64 districts of Bangladesh.'],
        'gallery'  => ['Gallery — Rajdhani Food Products', 'Our gardens, our factory floor and the people behind every packet.'],
        'news'     => ['News & Updates — Rajdhani Food Products', 'Announcements, product launches and stories from Rajdhani Food Products.'],
        'contact'  => ['Contact Us — Rajdhani Food Products', 'Talk to our sales team about products, dealership or bulk supply.'],
        'privacy'  => ['Privacy Policy — Rajdhani Food Products', 'How we collect, use and protect the information you give us.'],
        'terms'    => ['Terms of Service — Rajdhani Food Products', 'The terms that govern use of this website.'],
    ];

    public function tables(): array
    {
        return ['seo_meta'];
    }

    public function run(): void
    {
        $ogImageId = $this->mediaId('og-image');

        foreach (self::PAGES as $pageKey => [$title, $description]) {
            $this->insertIfAbsent(
                'seo_meta',
                ['page_key' => $pageKey],
                [
                    'meta_title'       => $title,
                    'meta_description' => $description,
                    'meta_keywords'    => null,
                    'og_image_id'      => $ogImageId,

                    // Legal pages are indexable; nothing here is behind a login.
                    'no_index'      => 0,
                    'canonical_url' => null,
                ],
            );
        }
    }
}
