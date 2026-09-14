<?php

declare(strict_types=1);

/**
 * Weekly lead digest runner (doc §13 `app/Jobs/`, §14.4; RTPP-33).
 *
 * Invoked by cPanel cron, not a daemon — shared hosting has no long-lived
 * process for this to run inside (§5.4). A typical crontab entry:
 *
 *   0 8 * * 1  php /path/to/backend/api/bin/lead-digest.php
 *
 * Sends once, unconditionally, whenever it is run — the schedule ("weekly")
 * is cron's job, not this script's; running it twice in the same week sends
 * the digest twice, the same way running `bin/seed.php` twice re-seeds.
 *
 * Usage:
 *   php bin/lead-digest.php
 */

use Rajdhani\Jobs\LeadDigest;
use Rajdhani\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

// A deliberately smaller bootstrap than Kernel::boot(): that one installs a
// shutdown handler which writes an HTTP error envelope, which is wrong for a
// process whose output is a terminal — the same reasoning bin/seed.php uses.
Env::load($basePath . '/.env');
date_default_timezone_set((string) config('app.timezone', 'UTC'));

$sent = (new LeadDigest())->run();

if ($sent) {
    echo "Lead digest sent.\n";
    exit(0);
}

// Not necessarily a failure: an empty recipient list and an empty
// notify-list-with-no-site-profile-fallback are both valid configurations
// (doc §19 item 4) that legitimately send nothing. Mailer::send() already
// logged the specific reason (skipped vs. a real SMTP failure).
echo "Lead digest did not send — check storage/logs for why (no recipients configured, or a mail failure).\n";
exit(1);
