<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Support\CacheStore;
use Rajdhani\Support\CacheStoreFactory;

/**
 * `POST /admin/cache/purge` (doc §9, §14.3; RTPP-36) — "called on publish".
 * Forgets the `/public/home` aggregate and regenerates `sitemap.xml`.
 *
 * `/public/layout` is deliberately not touched here — it belongs to RTPP-14
 * and is not cached (see `SiteProfileService`'s own class doc: an edit there
 * is already visible on the very next request, a stronger guarantee than
 * this ticket's TTL-based cache and one this endpoint has no need to weaken).
 *
 * Forgetting a key that was never cached (Redis unset, or nothing cached
 * yet) is a no-op, not an error — `CacheStore::forget()` guarantees that.
 */
final class CachePurgeService
{
    public function __construct(
        private readonly SitemapService $sitemap = new SitemapService(),
        private readonly ?CacheStore $cache = null,
    ) {
    }

    public function purge(): void
    {
        ($this->cache ?? CacheStoreFactory::make())->forget(HomeService::CACHE_KEY);

        $this->sitemap->regenerate();
    }
}
