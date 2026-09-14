<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Services\CachePurgeService;
use Rajdhani\Services\HomeService;
use Rajdhani\Services\SitemapService;
use Rajdhani\Tests\Support\FakeCacheStore;

/** `POST /admin/cache/purge` (doc §9, §14.3; RTPP-36). */
final class CachePurgeServiceTest extends DatabaseTestCase
{
    public function testForgetsTheHomeCacheKeyAndRegeneratesTheSitemap(): void
    {
        $cache = new FakeCacheStore();
        $cache->entries[HomeService::CACHE_KEY] = ['banners' => ['stale']];

        $sitemapPath = base_path('public/sitemap.xml');
        @unlink($sitemapPath);

        $purge = new CachePurgeService(
            sitemap: new SitemapService(
                products: new ProductRepository($this->db),
                categories: new CategoryRepository($this->db),
                news: new NewsRepository($this->db),
            ),
            cache: $cache,
        );

        $purge->purge();

        self::assertArrayNotHasKey(HomeService::CACHE_KEY, $cache->entries);
        self::assertFileExists($sitemapPath);
    }

    public function testForgettingAKeyThatWasNeverCachedIsNotAnError(): void
    {
        $cache = new FakeCacheStore();

        $purge = new CachePurgeService(
            sitemap: new SitemapService(
                products: new ProductRepository($this->db),
                categories: new CategoryRepository($this->db),
                news: new NewsRepository($this->db),
            ),
            cache: $cache,
        );

        $purge->purge();

        self::assertArrayNotHasKey(HomeService::CACHE_KEY, $cache->entries);
    }
}
