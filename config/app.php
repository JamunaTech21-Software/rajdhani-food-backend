<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

return [
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => Env::get('APP_URL', 'http://localhost:8000'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),

    // The admin dashboard's own base URL — a separate front-end, not this
    // API (doc §19 deviation 7: scope split, backend only). Needed only to
    // build the action links §14.4's admin-invite and password-reset
    // emails carry (RTPP-33); nothing else in this codebase reads it.
    // §19 item 1 already settled that no domain is fixed in code — "the
    // admin origin is read... at runtime, not hardcoded" — this is that
    // same treatment applied to an outbound link instead of an inbound
    // CORS check. The default matches this repo's local dev admin port.
    'admin_url' => Env::get('ADMIN_URL', 'http://localhost:5174'),

    // The customer site's own base URL — same reasoning as `admin_url`
    // above. Needed only to build absolute `<loc>` URLs in the `sitemap.xml`
    // this backend generates (RTPP-36, §14.3).
    'site_url' => Env::get('SITE_URL', 'http://localhost:5173'),

    // Every route in section 9 sits under this prefix.
    'api_prefix' => '/api/v1',

    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'log_path'  => base_path('storage/logs'),

    // Guards the cron-invoked HTTP endpoints (section 16.4). Only required when
    // the host has no SSH and migrations must run over HTTP.
    'cron_token' => Env::get('CRON_TOKEN'),
];
