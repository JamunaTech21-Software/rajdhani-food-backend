<?php

declare(strict_types=1);

/**
 * The single front controller (doc 13).
 *
 * This directory is the only one the web server exposes. Everything else —
 * app/, config/, database/, storage/, vendor/, .env — sits one level up and is
 * unreachable over HTTP, enforced by pointing the domain's document root here.
 * Where a host will not allow that, the .htaccess deny at the project root is
 * the weaker fallback and has to be security-reviewed (doc 16.2 item 4).
 */

use Rajdhani\Kernel;
use Rajdhani\Middleware\Cors;
use Rajdhani\Middleware\GuardQueryParameters;
use Rajdhani\Middleware\RateLimit;
use Rajdhani\Middleware\SecurityHeaders;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

Kernel::boot($basePath);

/** @var \Rajdhani\Http\Router $router */
$router = require $basePath . '/routes/api.php';

Kernel::handle($router, [
    // Order matters, and each position is a decision:
    //
    //   Cors                  answers the preflight before anything else can
    //                         reject the request — a 429 or a 403 without CORS
    //                         headers reaches the browser as an opaque network
    //                         error rather than as the status it is.
    //   SecurityHeaders       set before a handler can emit a body, and the
    //                         place plaintext is refused in production.
    //   GuardQueryParameters  rejects polluted query strings before any code
    //                         reads $request->query() expecting a string.
    //   RateLimit            counts last of the four, so a request refused by
    //                         the guards above does not consume the caller's
    //                         budget — but still before routing, so an unknown
    //                         path cannot be hammered for free.
    Cors::class,
    SecurityHeaders::class,
    GuardQueryParameters::class,
    RateLimit::class,
]);
