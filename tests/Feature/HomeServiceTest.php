<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Repositories\BannerRepository;
use Rajdhani\Repositories\FeatureItemRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\PageBlockRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\StatCounterRepository;
use Rajdhani\Repositories\TestimonialRepository;
use Rajdhani\Services\BannerService;
use Rajdhani\Services\HomeService;
use Rajdhani\Services\NewsService;
use Rajdhani\Services\ProductService;
use Rajdhani\Tests\Support\FakeCacheStore;

/** `GET /public/home`'s composition and caching (doc §9.3, §14.1; RTPP-36, RTPP-92). */
final class HomeServiceTest extends DatabaseTestCase
{
    private FakeCacheStore $cache;
    private HomeService $home;
    private FeatureItemRepository $featureItems;
    private PageBlockRepository $pageBlocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new FakeCacheStore();
        $this->featureItems = new FeatureItemRepository($this->db);
        $this->pageBlocks = new PageBlockRepository($this->db);
        $this->home = new HomeService(
            banners: new BannerService(new BannerRepository($this->db)),
            products: new ProductService(new ProductRepository($this->db)),
            news: new NewsService(new NewsRepository($this->db)),
            stats: new StatCounterRepository($this->db),
            testimonials: new TestimonialRepository($this->db),
            featureItems: $this->featureItems,
            pageBlocks: $this->pageBlocks,
            cache: $this->cache,
        );
    }

    public function testTheComposedPayloadHasEveryDocumentedIngredient(): void
    {
        $payload = $this->home->home();

        self::assertSame(
            ['banners', 'featured_products', 'stats', 'news', 'testimonials', 'usp_items', 'welcome', 'promo_banner'],
            array_keys($payload),
        );
        self::assertIsArray($payload['banners']);
        self::assertIsArray($payload['featured_products']);
        self::assertIsArray($payload['stats']);
        self::assertIsArray($payload['news']);
        self::assertIsArray($payload['testimonials']);
        self::assertIsArray($payload['usp_items']);
        self::assertIsArray($payload['promo_banner']);
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

    // ─── usp_items, welcome, promo_banner (RTPP-92) ─────────────────────────

    public function testUspItemsAreScopedToHomeUspAndOnlyActiveOnes(): void
    {
        $this->featureItems->create(['section' => 'HOME_USP', 'title' => 'Rich Taste', 'sort_order' => 1, 'is_active' => 1]);
        $this->featureItems->create(['section' => 'HOME_USP', 'title' => 'Hidden Item', 'sort_order' => 2, 'is_active' => 0]);
        $this->featureItems->create(['section' => 'ABOUT_VALUES', 'title' => 'Wrong Section', 'sort_order' => 1, 'is_active' => 1]);

        $payload = $this->home->home();

        $titles = array_column($payload['usp_items'], 'title');
        self::assertContains('Rich Taste', $titles);
        self::assertNotContains('Hidden Item', $titles);
        self::assertNotContains('Wrong Section', $titles);
    }

    public function testWelcomeIsThePublishedHomeWelcomeBlockWithDecodedBulletPoints(): void
    {
        $this->pageBlocks->create([
            'page_key'      => 'home',
            'block_key'     => 'welcome',
            'heading'       => 'Welcome to Rajdhani',
            'bullet_points' => json_encode(['Real milk', 'Rich taste', 'Hygienic', 'Nationwide delivery'], JSON_THROW_ON_ERROR),
        ]);

        $payload = $this->home->home();

        self::assertNotNull($payload['welcome']);
        self::assertSame('Welcome to Rajdhani', $payload['welcome']['heading']);
        self::assertSame(['Real milk', 'Rich taste', 'Hygienic', 'Nationwide delivery'], $payload['welcome']['bullet_points']);
    }

    public function testWelcomeIsNullWhenNoSuchBlockIsPublished(): void
    {
        $payload = $this->home->home();

        self::assertNull($payload['welcome']);
    }

    public function testADraftWelcomeBlockDoesNotAppear(): void
    {
        $this->pageBlocks->create([
            'page_key'  => 'home',
            'block_key' => 'welcome',
            'heading'   => 'Not yet published',
            'status'    => 'DRAFT',
        ]);

        $payload = $this->home->home();

        self::assertNull($payload['welcome']);
    }

    public function testPromoBannerIsScopedToTheHomeVideoCardPlacementNotHomeHero(): void
    {
        $banners = new BannerRepository($this->db);
        $banners->create(['placement' => 'HOME_VIDEO_CARD', 'title' => 'Watch our story', 'sort_order' => 0, 'status' => 'PUBLISHED']);
        $banners->create(['placement' => 'HOME_HERO', 'title' => 'Hero banner', 'sort_order' => 0, 'status' => 'PUBLISHED']);

        $payload = $this->home->home();

        $promoTitles = array_column($payload['promo_banner'], 'title');
        self::assertContains('Watch our story', $promoTitles);
        self::assertNotContains('Hero banner', $promoTitles);
    }
}
