<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Repositories\FeatureItemRepository;
use Rajdhani\Repositories\PageBlockRepository;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Support\CacheStore;
use Rajdhani\Support\CacheStoreFactory;

/**
 * `GET /public/home` (doc §9.3, §10.1, §14.1; RTPP-36, RTPP-92) — one
 * composed payload so the customer site's first paint needs one round
 * trip, not five.
 *
 * **Ingredients originally matched only the route's own doc row** —
 * "banners, featured products, stats, news, testimonials" — deliberately
 * narrower than §10.1's full page description, which also lists the
 * `HOME_USP` feature-item strip and a `PageBlock` welcome block (with its
 * own `HOME_VIDEO_CARD` promo banner). Neither had a public read path when
 * `/public/home` first shipped: RTPP-24 deliberately deferred all
 * public-facing exposure of feature items, process steps, certifications
 * and page blocks (see `plan.md`), and RTPP-36's own scope named only
 * `/public/home`, not that backlog.
 *
 * RTPP-92 closes the two pieces RTPP-59 (the customer Home page) actually
 * needed to stop being blocked — `usp_items`, `welcome`, `promo_banner` —
 * without building the fuller standalone backlog those other resources
 * still lack (`/public/features`, `/public/page-blocks/:pageKey`, etc.,
 * still open, still out of this endpoint's scope).
 *
 * Cached as one unit — a single `home` key, not eight — because that is
 * what the doc pairs with `/public/layout` under "aggregated payloads"
 * (§14.1) and what `/admin/cache/purge` forgets as one call.
 */
final class HomeService
{
    /** Public so `CachePurgeService` can forget exactly the key this class writes. */
    public const CACHE_KEY = 'public:home';
    private const CACHE_TTL_SECONDS = 120;
    private const FEATURED_PRODUCT_LIMIT = 8;
    private const TESTIMONIAL_LIMIT = 6;

    public function __construct(
        private readonly BannerService $banners = new BannerService(),
        private readonly ProductService $products = new ProductService(),
        private readonly NewsService $news = new NewsService(),
        private readonly StatCounterRepository $stats = new StatCounterRepository(),
        private readonly TestimonialRepository $testimonials = new TestimonialRepository(),
        private readonly FeatureItemRepository $featureItems = new FeatureItemRepository(),
        private readonly PageBlockRepository $pageBlocks = new PageBlockRepository(),
        private readonly ?CacheStore $cache = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function home(): array
    {
        $store = $this->cache ?? CacheStoreFactory::make();
        $cached = $store->get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $payload = $this->compose();
        $store->put(self::CACHE_KEY, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function compose(): array
    {
        $welcome = $this->pageBlocks->publicFind('home', 'welcome');

        return [
            'banners'          => $this->banners->publicList(['placement' => 'HOME_HERO']),
            'featured_products' => $this->products->publicPaginate([
                'featured' => 'true',
                'limit'    => self::FEATURED_PRODUCT_LIMIT,
            ])['data'],
            'stats'            => array_map($this->statView(...), $this->stats->publicByGroup('HOME')),
            'news'             => $this->news->publicFeatured(),
            'testimonials'     => array_map($this->testimonialView(...), $this->testimonials->publicPublished(self::TESTIMONIAL_LIMIT)),
            'usp_items'        => array_map($this->featureItemView(...), $this->featureItems->publicBySection('HOME_USP')),
            'welcome'          => $welcome === null ? null : $this->pageBlockView($welcome),
            'promo_banner'     => $this->banners->publicList(['placement' => 'HOME_VIDEO_CARD']),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function statView(array $row): array
    {
        return [
            'id'        => (string) $row['id'],
            'value'     => (string) $row['value'],
            'label'     => (string) $row['label'],
            'icon_name' => $row['icon_name'] === null ? null : (string) $row['icon_name'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function testimonialView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'author_name' => (string) $row['author_name'],
            'author_role' => $row['author_role'] === null ? null : (string) $row['author_role'],
            'quote'       => (string) $row['quote'],
            'rating'      => $row['rating'] === null ? null : (int) $row['rating'],
            'avatar'      => $row['avatar_url'] === null ? null : [
                'url' => (string) $row['avatar_url'],
                'alt' => $row['avatar_alt'] === null ? null : (string) $row['avatar_alt'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function featureItemView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'title'       => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'icon_name'   => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            'icon_bg_color' => $row['icon_bg_color'] === null ? null : (string) $row['icon_bg_color'],
            'icon'        => $row['icon_url'] === null ? null : [
                'url' => (string) $row['icon_url'],
                'alt' => $row['icon_alt'] === null ? null : (string) $row['icon_alt'],
            ],
        ];
    }

    /**
     * The "four-item benefit list" doc §10.1 describes is `bullet_points` —
     * stored as a JSON string array, decoded here the same way
     * `NewsService`/`SettingsService` decode their own JSON columns rather
     * than handing a raw JSON *string* to a JSON API response.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function pageBlockView(array $row): array
    {
        $bulletPoints = [];

        if (is_string($row['bullet_points']) && $row['bullet_points'] !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($row['bullet_points'], true);
            $bulletPoints = is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
        }

        return [
            'id'            => (string) $row['id'],
            'eyebrow'       => $row['eyebrow'] === null ? null : (string) $row['eyebrow'],
            'heading'       => $row['heading'] === null ? null : (string) $row['heading'],
            'subheading'    => $row['subheading'] === null ? null : (string) $row['subheading'],
            'body'          => $row['body'] === null ? null : (string) $row['body'],
            'bullet_points' => $bulletPoints,
            'cta_label'     => $row['cta_label'] === null ? null : (string) $row['cta_label'],
            'cta_url'       => $row['cta_url'] === null ? null : (string) $row['cta_url'],
            'image'         => $row['image_url'] === null ? null : [
                'url' => (string) $row['image_url'],
                'alt' => $row['image_alt'] === null ? null : (string) $row['image_alt'],
            ],
        ];
    }
}
