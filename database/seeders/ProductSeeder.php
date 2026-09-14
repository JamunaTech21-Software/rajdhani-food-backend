<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

use Rajdhani\Helpers\SlugHelper;
use RuntimeException;

/**
 * Demo products with their pack sizes, highlights and images (doc 8.5).
 *
 * Three products is enough to exercise every shape the product endpoints have
 * to return: one with three pack sizes and a discount, one with two, one with a
 * single size and no badge. A front-end built against a single product
 * invariably assumes a single pack size.
 *
 * Copy is placeholder (doc 19) and is replaced under RTPP-86.
 *
 * Two ordering rules that are easy to get wrong:
 *
 *   - the category id comes from a lookup by slug, not from CategorySeeder's
 *     return value, so this seeder can be run on its own;
 *   - child rows hang off the id `insertIfAbsent()` returned, which on a
 *     re-run is the *existing* product's id. Using a freshly generated ULID
 *     there would insert a second set of pack sizes against nothing.
 */
final class ProductSeeder extends Seeder
{
    public function tables(): array
    {
        return ['products', 'product_pack_sizes', 'product_highlights', 'product_images'];
    }

    public function run(): void
    {
        /** @var array{products:list<array<string,mixed>>} $content */
        $content = $this->seedData('site-content.json');
        $now = $this->now();
        $order = 0;

        /** @var array<string,string> $imageKeys product slug => MediaSeeder key */
        $imageKeys = [
            'rajdhani-premium-tea' => 'product-premium',
            'rajdhani-gold-blend'  => 'product-gold',
            'rajdhani-green-tea'   => 'product-green',
        ];

        foreach ($content['products'] as $product) {
            $name = (string) $product['name'];
            $slug = SlugHelper::make($name);
            $categorySlug = SlugHelper::make((string) $product['category']);
            $categoryId = $this->findId('categories', ['slug' => $categorySlug]);

            if ($categoryId === null) {
                throw new RuntimeException(
                    "Product '{$name}' needs category '{$categorySlug}'. Run CategorySeeder first."
                );
            }

            $productId = $this->insertIfAbsent(
                'products',
                ['slug' => $slug],
                [
                    'category_id'       => $categoryId,
                    'name'              => $name,
                    'short_description' => $this->str($product, 'shortDescription'),
                    'tagline'           => $this->str($product, 'tagline'),
                    'description'       => $this->str($product, 'description'),
                    'ingredients'       => $this->str($product, 'ingredients'),
                    'nutrition_info'    => null,
                    'brewing_guide'     => $this->str($product, 'brewingGuide'),
                    'packaging_info'    => null,
                    'key_features'      => null,
                    'badge_text'        => $this->str($product, 'badgeText'),
                    'badge_color'       => isset($product['badgeText']) ? '#C9A227' : null,
                    'status'            => 'PUBLISHED',
                    'is_featured'       => ($product['isFeatured'] ?? false) === true ? 1 : 0,
                    'sort_order'        => ++$order,

                    // Counters start at zero and are owned by the application
                    // from here on; a re-run must never reset them, which
                    // insert-if-absent guarantees.
                    'view_count'       => 0,
                    'rating_average'   => 0.0,
                    'rating_count'     => 0,
                    'meta_title'       => $name . ' — Rajdhani Food Products',
                    'meta_description' => $this->str($product, 'shortDescription'),
                    'created_at'       => $now,
                    'updated_at'       => $now,
                    'deleted_at'       => null,
                ],
            );

            $this->seedPackSizes($productId, $product);
            $this->seedHighlights($productId, $product);

            $mediaId = isset($imageKeys[$slug]) ? $this->mediaId($imageKeys[$slug]) : null;

            if ($mediaId !== null) {
                $this->insertIfAbsent(
                    'product_images',
                    ['product_id' => $productId, 'media_id' => $mediaId],
                    ['is_primary' => 1, 'sort_order' => 1],
                );
            }
        }
    }

    /** @param array<string,mixed> $product */
    private function seedPackSizes(string $productId, array $product): void
    {
        /** @var list<array<string,mixed>> $sizes */
        $sizes = is_array($product['packSizes'] ?? null) ? $product['packSizes'] : [];
        $order = 0;

        foreach ($sizes as $size) {
            $price = (float) $size['price'];
            $comparePrice = isset($size['comparePrice']) ? (float) $size['comparePrice'] : null;

            $this->insertIfAbsent(
                'product_pack_sizes',
                // SKU rather than (product_id, label): it is unique across the
                // whole table, so a SKU accidentally reused on another product
                // fails loudly here instead of at the first stock report.
                ['sku' => (string) $size['sku']],
                [
                    'product_id'    => $productId,
                    'label'         => (string) $size['label'],
                    'price'         => number_format($price, 2, '.', ''),
                    'compare_price' => $comparePrice === null ? null : number_format($comparePrice, 2, '.', ''),

                    // Derived, not stored in the JSON: a percentage that
                    // disagrees with the two prices beside it is a support call.
                    'discount_percent' => $comparePrice !== null && $comparePrice > $price
                        ? (int) round((($comparePrice - $price) / $comparePrice) * 100)
                        : null,
                    'price_includes_vat' => 1,
                    'is_default'         => ($size['isDefault'] ?? false) === true ? 1 : 0,
                    'is_available'       => 1,
                    'sort_order'         => ++$order,
                ],
            );
        }
    }

    /** @param array<string,mixed> $product */
    private function seedHighlights(string $productId, array $product): void
    {
        /** @var list<array<string,mixed>> $highlights */
        $highlights = is_array($product['highlights'] ?? null) ? $product['highlights'] : [];
        $order = 0;

        foreach ($highlights as $highlight) {
            $this->insertIfAbsent(
                'product_highlights',
                ['product_id' => $productId, 'title' => (string) $highlight['title']],
                [
                    'subtitle'   => isset($highlight['subtitle']) ? (string) $highlight['subtitle'] : null,
                    'icon_name'  => isset($highlight['iconName']) ? (string) $highlight['iconName'] : null,
                    'sort_order' => ++$order,
                ],
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function str(array $row, string $key): ?string
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : null;
    }
}
