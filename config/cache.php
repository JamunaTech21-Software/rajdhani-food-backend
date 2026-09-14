<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * RTPP-36 — the optional Redis layer behind `/public/home` and
 * `/public/layout`, and the one setting that turns it on. `redis_url`
 * blank (the expected case on shared cPanel hosting — doc §5.4, §16.6) is
 * `CacheStoreFactory`'s cue to fall back to `FileCache` instead.
 */
return [
    'redis_url' => Env::get('REDIS_URL'),
];
