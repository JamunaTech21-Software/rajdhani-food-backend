<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * Header, footer and social navigation (doc 8.2, 9.3).
 *
 * `GET /public/layout` (RTPP-14) returns these three collections in one call so
 * the front-end can paint a complete shell before any page data arrives. An
 * empty `menu_links` table would mean a site with no navigation at all, so the
 * defaults here mirror the page set in SeoMetaSeeder — the two lists have to
 * agree, and both are short enough to keep in step by eye.
 *
 * Social URLs are placeholders. Nothing verifies that a social handle exists,
 * so a wrong one fails silently in the footer; the client confirms them under
 * RTPP-17.
 */
final class NavigationSeeder extends Seeder
{
    /** location => [[label, url], ...] */
    private const MENUS = [
        'header' => [
            ['Home', '/'],
            ['About', '/about'],
            ['Products', '/products'],
            ['Quality', '/quality'],
            ['Dealership', '/dealer'],
            ['Gallery', '/gallery'],
            ['News', '/news'],
            ['Contact', '/contact'],
        ],
        'footer_quick' => [
            ['About Us', '/about'],
            ['Quality', '/quality'],
            ['Become a Dealer', '/dealer'],
            ['News', '/news'],
            ['Contact', '/contact'],
        ],
        'footer_products' => [
            ['Premium Tea', '/products?category=premium-tea'],
            ['Gold Blend', '/products?category=gold-blend'],
            ['Classic Black', '/products?category=classic-black'],
            ['Green Tea', '/products?category=green-tea'],
        ],
        'legal' => [
            ['Privacy Policy', '/privacy'],
            ['Terms of Service', '/terms'],
        ],
    ];

    /** platform => [url, icon_name] */
    private const SOCIALS = [
        'facebook'  => ['https://facebook.com/rajdhanifood', 'facebook'],
        'instagram' => ['https://instagram.com/rajdhanifood', 'instagram'],
        'youtube'   => ['https://youtube.com/@rajdhanifood', 'youtube'],
        'linkedin'  => ['https://linkedin.com/company/rajdhanifood', 'linkedin'],
        'whatsapp'  => ['https://wa.me/8801700000001', 'whatsapp'],
    ];

    public function tables(): array
    {
        return ['menu_links', 'social_links'];
    }

    public function run(): void
    {
        foreach (self::MENUS as $location => $items) {
            foreach ($items as $index => [$label, $url]) {
                // No unique constraint covers (location, label) — the table
                // allows the same label twice on purpose, for nested menus — so
                // the pair is treated as the natural key here by hand.
                $this->insertIfAbsent(
                    'menu_links',
                    ['location' => $location, 'label' => $label],
                    [
                        'url'             => $url,
                        'parent_id'       => null,
                        'sort_order'      => $index + 1,
                        'is_active'       => 1,
                        'open_in_new_tab' => 0,
                    ],
                );
            }
        }

        $order = 0;

        foreach (self::SOCIALS as $platform => [$url, $icon]) {
            $this->insertIfAbsent(
                'social_links',
                ['platform' => $platform],
                ['url' => $url, 'icon_name' => $icon, 'sort_order' => ++$order, 'is_active' => 1],
            );
        }
    }
}
