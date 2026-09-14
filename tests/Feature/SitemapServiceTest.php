<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Services\SitemapService;

/**
 * `SitemapService` (doc §14.3; RTPP-36).
 *
 * Writes the real `public/sitemap.xml` — that is the point of the class,
 * and `.gitignore` already treats the file as generated, the same as
 * `storage/cache`. Nothing here needs to clean it up afterward: a sitemap
 * existing on disk after the suite runs is what production wants too.
 */
final class SitemapServiceTest extends DatabaseTestCase
{
    private SitemapService $sitemap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitemap = new SitemapService(
            products: new ProductRepository($this->db),
            categories: new CategoryRepository($this->db),
            news: new NewsRepository($this->db),
        );
    }

    public function testRegeneratesAWellFormedSitemapContainingEveryStaticPage(): void
    {
        $written = $this->sitemap->regenerate();

        self::assertTrue($written);

        $path = base_path('public/sitemap.xml');
        self::assertFileExists($path);

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'sitemap.xml must be well-formed XML');

        // `iterator_to_array()` defaults to keying by element name — every
        // child here is named "url", so without `false` only the last one
        // would survive the conversion.
        $locations = array_map(static fn ($url): string => (string) $url->loc, iterator_to_array($xml, false));

        foreach (['', 'products', 'about', 'quality', 'dealer', 'gallery', 'news', 'contact'] as $staticPath) {
            $expected = rtrim((string) config('app.site_url'), '/') . '/' . $staticPath;
            self::assertContains($expected, $locations, "Missing static page: {$staticPath}");
        }
    }

    public function testEveryUrlCarriesALastmodDate(): void
    {
        $this->sitemap->regenerate();

        $xml = simplexml_load_file(base_path('public/sitemap.xml'));
        self::assertNotFalse($xml);

        foreach ($xml as $url) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $url->lastmod);
        }
    }
}
