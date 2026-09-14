<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Services\BannerService;
use Rajdhani\Services\HomeService;
use Rajdhani\Services\NewsService;
use Rajdhani\Services\ProductService;
use Rajdhani\Tests\Support\FakeCacheStore;

/** `GET /public/home`'s composition and caching (doc §9.3, §14.1; RTPP-36). */
final class HomeServiceTest extends DatabaseTestCase
{
    private FakeCacheStore $cache;
    private HomeService $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new FakeCacheStore();
        $this->home = new HomeService(
            banners: new BannerService(new BannerRepository($this->db)),
            products: new ProductService(new ProductRepository($this->db)),
            news: new NewsService(new NewsRepository($this->db)),
            stats: new StatCounterRepository($this->db),
            testimonials: new TestimonialRepository($this->db),
            cache: $this->cache,
        );
    }

    public function testTheComposedPayloadHasEveryDocumentedIngredient(): void
    {
        $payload = $this->home->home();

        self::assertSame(
            ['banners', 'featured_products', 'stats', 'news', 'testimonials'],
            array_keys($payload),
        );
        self::assertIsArray($payload['banners']);
        self::assertIsArray($payload['featured_products']);
        self::assertIsArray($payload['stats']);
        self::assertIsArray($payload['news']);
        self::assertIsArray($payload['testimonials']);
    }

    public function testASecondCallReadsFromTheCacheRatherThanRecomputing(): void
    {
        $first = $this->home->home();

        // Corrupt the cached entry in a way that only the cache, never a
        // fresh computation, would produce — proves the second call served
        // the cache rather than recomputing from the (still perfectly
        // healthy) database.
        $this->cache->entries[HomeService::CACHE_KEY]['banners'] = ['marker' => 'from-cache'];

        $second = $this->home->home();

        self::assertSame(['marker' => 'from-cache'], $second['banners']);
        self::assertNotSame($first['banners'], $second['banners']);
    }

    public function testANewComputationIsStoredUnderTheDocumentedKey(): void
    {
        $this->home->home();

        self::assertArrayHasKey(HomeService::CACHE_KEY, $this->cache->entries);
    }

    public function testStatsAreScopedToTheHomeGroupAndOnlyActiveOnes(): void
    {
        $stats = new StatCounterRepository($this->db);
        $stats->create(['group' => 'HOME', 'value' => '25+', 'label' => 'Years', 'sort_order' => 1, 'is_active' => 1]);
        $stats->create(['group' => 'HOME', 'value' => '99+', 'label' => 'Hidden', 'sort_order' => 2, 'is_active' => 0]);
        $stats->create(['group' => 'ABOUT', 'value' => '5', 'label' => 'Other group', 'sort_order' => 1, 'is_active' => 1]);

        $payload = $this->home->home();

        $labels = array_column($payload['stats'], 'label');
        self::assertContains('Years', $labels);
        self::assertNotContains('Hidden', $labels);
        self::assertNotContains('Other group', $labels);
    }
}
