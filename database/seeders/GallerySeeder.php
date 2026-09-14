<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

use Rajdhani\Helpers\SlugHelper;

/**
 * Gallery categories and a few images in each (doc 8.7).
 *
 * `gallery_images.media_id` is NOT NULL, so an image row cannot exist without a
 * media asset. Where MediaSeeder has not run, the category is still created and
 * the images are skipped rather than the seeder failing — a gallery with empty
 * categories is a working page, and this is the only table in the set where the
 * image *is* the content.
 */
final class GallerySeeder extends Seeder
{
    /** category name => [icon, [MediaSeeder key => caption, ...]] */
    private const CATEGORIES = [
        'Tea Gardens' => ['mountain', [
            'garden-01' => 'Second-flush plucking in Moulvibazar',
            'garden-02' => 'Morning on the estate',
        ]],
        'Manufacturing' => ['factory', [
            'factory-01' => 'Blending floor',
        ]],
        'Events' => ['calendar', []],
        'Team'   => ['users', [
            'team-01' => 'The people behind the packet',
        ]],
    ];

    public function tables(): array
    {
        return ['gallery_categories', 'gallery_images'];
    }

    public function run(): void
    {
        $now = $this->now();
        $order = 0;

        foreach (self::CATEGORIES as $name => [$icon, $images]) {
            $categoryId = $this->insertIfAbsent(
                'gallery_categories',
                ['slug' => SlugHelper::make($name)],
                [
                    'name'        => $name,
                    'description' => null,
                    'icon_name'   => $icon,

                    // The cover is the first image in the category, which does
                    // not exist yet on the first pass; the admin panel sets it.
                    'cover_image_id' => null,
                    'sort_order'     => ++$order,
                    'is_active'      => 1,
                ],
            );

            $imageOrder = 0;

            foreach ($images as $key => $caption) {
                $mediaId = $this->mediaId($key);

                if ($mediaId === null) {
                    continue;
                }

                $this->insertIfAbsent(
                    'gallery_images',
                    ['category_id' => $categoryId, 'media_id' => $mediaId],
                    [
                        'title'       => $caption,
                        'description' => null,
                        'sort_order'  => ++$imageOrder,
                        'is_active'   => 1,
                        'created_at'  => $now,
                    ],
                );
            }
        }
    }
}
