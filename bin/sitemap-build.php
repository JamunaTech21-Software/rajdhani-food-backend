<?php

declare(strict_types=1);

/**
 * Nightly sitemap regeneration runner (doc §13 `app/Jobs/`, §14.3, §16.5;
 * RTPP-36).
 *
 * Invoked by cPanel cron, not a daemon — shared hosting has no long-lived
 * process for this to run inside (§5.4). A typical crontab entry:
 *
 *   0 2 * * *  php /path/to/backend/api/bin/sitemap-build.php
 *
 * Usage:
 *   php bin/sitemap-build.php
 */

use Rajdhani\Jobs\SitemapBuild;
use Rajdhani\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

// A deliberately smaller bootstrap than Kernel::boot() — same reasoning as
// bin/lead-digest.php and bin/seed.php: no shutdown handler that writes an
// HTTP error envelope for a process whose output is a terminal.
Env::load($basePath . '/.env');
date_default_timezone_set((string) config('app.timezone', 'UTC'));

$written = (new SitemapBuild())->run();

if ($written) {
    echo "sitemap.xml regenerated.\n";
    exit(0);
}

echo "sitemap.xml regeneration failed — check that public/ is writable.\n";
exit(1);
