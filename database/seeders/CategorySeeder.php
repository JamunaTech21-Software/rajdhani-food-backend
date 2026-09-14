<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

use Rajdhani\Helpers\SlugHelper;

/**
 * Product categories (doc 8.5).
 *
 * These are the buttons on the products page filter bar, so the list and its
 * order are a design decision rather than a data one — it comes from the
 * salvaged `site-content.json` and matches the filter bar in the approved
 * screens.
 *
 * Slugs are derived rather than stored in the JSON so that they cannot drift
 * from the names, and they are the natural key: `/products?category=green-tea`
 * is a public URL, and renaming a category must not silently break it. That is
 * also why a re-run does not rewrite an existing row — an editor who renamed a
 * category owns that slug now.
 */
final class CategorySeeder extends Seeder
{
    public function tables(): array
    {
        return ['categories'];
    }

    public function run(): void
    {
        /** @var array{categories:list<array{name:string,iconName:string}>} $content */
        $content = $this->seedData('site-content.json');
        $now = $this->now();
        $order = 0;

        foreach ($content['categories'] as $category) {
            $this->insertIfAbsent(
                'categories',
                ['slug' => SlugHelper::make($category['name'])],
                [
                    'name'             => $category['name'],
                    'description'      => null,
                    'icon_name'        => $category['iconName'],
                    'image_id'         => null,
                    'sort_order'       => ++$order,
                    'is_active'        => 1,
                    'meta_title'       => $category['name'] . ' — Rajdhani Food Products',
                    'meta_description' => null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                    'deleted_at'       => null,
                ],
            );
        }
    }
}
