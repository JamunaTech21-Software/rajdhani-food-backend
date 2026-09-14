<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use DateTimeImmutable;
use DateTimeZone;
use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Repositories\NewsRepository;
use Rajdhani\Repositories\ProductRepository;

/**
 * `sitemap.xml`, generated from published content (doc §14.3; RTPP-36) and
 * written to `public/sitemap.xml` — the one directory the web server
 * exposes (doc §13), so a plain `GET /sitemap.xml` serves the file with no
 * PHP involved once it exists.
 *
 * **Static paths are a judgement call, not confirmed literals.** The
 * document confirms three page keys directly (`page_blocks.page_key`:
 * `'about' | 'quality' | 'dealer'`) and the module names for the rest
 * (Gallery, News, Contact, Products); the front-end's actual route slugs
 * are outside this backend's scope to confirm (doc §19 deviation 7). This
 * list uses the obvious lowercase-of-name path for each — wrong only if the
 * eventual front-end chooses a different scheme, in which case this list is
 * a one-line fix, not a redesign.
 */
final class SitemapService
{
    /** @var list<string> */
    private const STATIC_PATHS = ['', 'products', 'about', 'quality', 'dealer', 'gallery', 'news', 'contact'];

    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository(),
        private readonly NewsRepository $news = new NewsRepository(),
    ) {
    }

    /**
     * Regenerates `public/sitemap.xml` in place. Never throws — a write
     * failure (a read-only `public/`, a full disk) is reported through the
     * return value, not an exception; the cache-purge request that triggers
     * this should not itself fail over a filesystem problem it cannot fix.
     */
    public function regenerate(): bool
    {
        $xml = $this->build();
        $path = base_path('public/sitemap.xml');
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // Write-then-rename, same reasoning as FileCache::put(): a concurrent
        // request reading sitemap.xml sees either the old file or the new
        // one, never a half-written one.
        if (@file_put_contents($temporary, $xml, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    private function build(): string
    {
        $base = rtrim((string) config('app.site_url'), '/');
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $urls = [];

        foreach (self::STATIC_PATHS as $path) {
            $urls[] = [$base . '/' . $path, $today];
        }

        foreach ($this->products->publishedSlugs() as $row) {
            $urls[] = ["{$base}/products/{$row['slug']}", $this->dateOnly($row['updated_at'])];
        }

        foreach ($this->categories->activeSlugs() as $row) {
            $urls[] = ["{$base}/products?category={$row['slug']}", $this->dateOnly($row['updated_at'])];
        }

        foreach ($this->news->publishedSlugs() as $row) {
            $urls[] = ["{$base}/news/{$row['slug']}", $this->dateOnly($row['updated_at'])];
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as [$loc, $lastmod]) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    private function dateOnly(string $datetime): string
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $datetime);

        if ($parsed === false) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);
        }

        return $parsed !== false ? $parsed->format('Y-m-d') : substr($datetime, 0, 10);
    }
}
