<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * Placeholder rows in `media_assets` so the demo content has something to point
 * at (doc 8.1).
 *
 * Almost every content table carries a nullable image foreign key, and
 * `downloads.file_id` is not nullable at all. Without media rows the demo
 * content would either be image-less or unseedable, and the front-end developer
 * consuming these endpoints would have no way to see the shape of an image
 * payload.
 *
 * These are **not** Cloudinary uploads. Cloudinary is not configured yet
 * (RTPP-17 is still waiting on the credentials), so `public_id` uses a
 * `rajdhani/demo/` prefix that no real upload will ever collide with, and
 * `secure_url` points at a placeholder image service. Every one of these rows is
 * replaced when the client's assets arrive under RTPP-86; the `demo/` folder
 * makes them trivial to find and delete.
 */
final class MediaSeeder extends Seeder
{
    /** Key => [width, height, label on the placeholder, folder]. */
    private const ASSETS = [
        'logo-light'        => [512, 512, 'Rajdhani', 'rajdhani/demo/brand'],
        'logo-dark'         => [512, 512, 'Rajdhani', 'rajdhani/demo/brand'],
        'favicon'           => [64, 64, 'R', 'rajdhani/demo/brand'],
        'og-image'          => [1200, 630, 'Rajdhani Food Products', 'rajdhani/demo/brand'],
        'hero-home'         => [1920, 1080, 'Home Hero', 'rajdhani/demo/banners'],
        'hero-about'        => [1920, 720, 'About Hero', 'rajdhani/demo/banners'],
        'hero-products'     => [1920, 720, 'Products Hero', 'rajdhani/demo/banners'],
        'hero-dealer'       => [1920, 720, 'Dealership Hero', 'rajdhani/demo/banners'],
        'product-premium'   => [1000, 1000, 'Premium Tea', 'rajdhani/demo/products'],
        'product-gold'      => [1000, 1000, 'Gold Blend', 'rajdhani/demo/products'],
        'product-green'     => [1000, 1000, 'Green Tea', 'rajdhani/demo/products'],
        'garden-01'         => [1200, 800, 'Tea Garden', 'rajdhani/demo/gallery'],
        'garden-02'         => [1200, 800, 'Tea Garden', 'rajdhani/demo/gallery'],
        'factory-01'        => [1200, 800, 'Manufacturing', 'rajdhani/demo/gallery'],
        'team-01'           => [1200, 800, 'Our Team', 'rajdhani/demo/gallery'],
        'news-cover-01'     => [1200, 675, 'News', 'rajdhani/demo/news'],
        'about-story'       => [1000, 700, 'Our Story', 'rajdhani/demo/pages'],
    ];

    public function tables(): array
    {
        return ['media_assets'];
    }

    public function run(): void
    {
        $now = $this->now();

        foreach (self::ASSETS as $key => [$width, $height, $label, $folder]) {
            $publicId = "rajdhani/demo/{$key}";

            $this->insertIfAbsent(
                'media_assets',
                ['public_id' => $publicId],
                [
                    'secure_url' => sprintf(
                        'https://placehold.co/%dx%d/1B5E20/FFFFFF/png?text=%s',
                        $width,
                        $height,
                        rawurlencode($label),
                    ),
                    'type'           => 'IMAGE',
                    'format'         => 'png',
                    'width'          => $width,
                    'height'         => $height,
                    'bytes'          => null,
                    'folder'         => $folder,
                    'alt_text'       => $label,
                    'caption'        => null,
                    'uploaded_by_id' => null,
                    'created_at'     => $now,
                ],
            );
        }
    }
}
