<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Support\CacheStore;
use Rajdhani\Support\CacheStoreFactory;

/**
 * `GET /public/home` (doc §9.3, §10.1, §14.1; RTPP-36) — one composed payload
 * so the customer site's first paint needs one round trip, not five.
 *
 * **Ingredients match the route's own doc row** — "banners, featured
 * products, stats, news, testimonials" — not §10.1's full page description,
 * which also lists the `HOME_USP` feature-item strip and a `PageBlock`
 * welcome block. Neither of those has a public read path yet: RTPP-24
 * deliberately deferred all public-facing exposure of feature items,
 * process steps, certifications and page blocks (see `plan.md`), and this
 * ticket's own scope names only `/public/home`, not that backlog. Building
 * it here would be scope this ticket was never asked to carry.
 *
 * Cached as one unit — a single `home` key, not five — because that is what
 * the doc pairs with `/public/layout` under "aggregated payloads" (§14.1) and
 * what `/admin/cache/purge` forgets as one call.
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
        return [
            'banners'          => $this->banners->publicList(['placement' => 'HOME_HERO']),
            'featured_products' => $this->products->publicPaginate([
                'featured' => 'true',
                'limit'    => self::FEATURED_PRODUCT_LIMIT,
            ])['data'],
            'stats'            => array_map($this->statView(...), $this->stats->publicByGroup('HOME')),
            'news'             => $this->news->publicFeatured(),
            'testimonials'     => array_map($this->testimonialView(...), $this->testimonials->publicPublished(self::TESTIMONIAL_LIMIT)),
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
}
