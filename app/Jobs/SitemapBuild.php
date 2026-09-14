<?php

declare(strict_types=1);

namespace Rajdhani\Jobs;

use Rajdhani\Services\SitemapService;

/**
 * Nightly `sitemap.xml` regeneration (doc §13 `app/Jobs/`, §14.3, §16.5;
 * RTPP-36) — the belt to `POST /admin/cache/purge`'s suspenders. A publish
 * that forgets to purge, or a purge that never reaches the API (an admin
 * editing the database directly, a batch import), still gets picked up by
 * the next nightly run — the same "cron as backstop" reasoning `LeadDigest`
 * and `TokenCleanup` already use elsewhere in this directory.
 */
final class SitemapBuild
{
    public function __construct(
        private readonly SitemapService $sitemap = new SitemapService(),
    ) {
    }

    public function run(): bool
    {
        return $this->sitemap->regenerate();
    }
}
