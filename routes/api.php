<?php

declare(strict_types=1);

/**
 * Mounts the three namespaces from section 9.
 *
 * Health endpoints sit outside them deliberately: an uptime monitor must be able
 * to reach /health without a token and without CORS mattering (doc 16.5).
 */

use Rajdhani\Http\Request;
use Rajdhani\Http\Router;
use Rajdhani\Support\Database;

$router = new Router();

$router->get('/health', static fn (Request $r): array => [
    'status'  => 'ok',
    'service' => 'rajdhani-api',
    'time'    => gmdate('c'),
]);

$router->get('/health/db', static function (Request $r): array {
    if (!Database::isReachable()) {
        // 503, not 500: the service is up, its dependency is not. Monitors and
        // load balancers treat those differently, so the status is set
        // explicitly rather than taken from the error code's default.
        \Rajdhani\Helpers\ApiResponse::error(
            \Rajdhani\Helpers\ErrorCode::INTERNAL_ERROR,
            'Database unreachable',
            [],
            503,
        );
    }

    return ['status' => 'ok', 'database' => 'reachable', 'time' => gmdate('c')];
});

/*
 * The API reference (§5.3). Outside the three namespaces for the same reason
 * health is: it is about the API rather than part of it. Protection lives in
 * the controller, because a browser navigating here sends no Authorization
 * header and a bearer-gated docs page is one nobody can open.
 */
$router->get('/docs', \Rajdhani\Http\Router::to(\Rajdhani\Controllers\DocsController::class, 'show'));
$router->get('/docs/openapi.yaml', \Rajdhani\Http\Router::to(\Rajdhani\Controllers\DocsController::class, 'spec'));

require __DIR__ . '/auth.php';
require __DIR__ . '/public.php';
require __DIR__ . '/admin.php';

return $router;
