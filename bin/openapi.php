<?php

declare(strict_types=1);

/**
 * OpenAPI drift check (RTPP-16).
 *
 * The specification is maintained by hand — there is no swagger-jsdoc for PHP
 * without an annotation library and a build step, and this project deploys by
 * uploading files (doc §16). A hand-written spec drifts from the code, silently,
 * and a wrong API document is worse than none: the front-end developer trusts it.
 *
 * So the spec is checked against the live routing table in both directions:
 *
 *   - a route with no entry in the spec       → undocumented
 *   - an entry with no matching route         → documents something that is gone
 *
 * Both fail. CI runs this, so the build breaks on the commit that caused the
 * drift rather than on the day someone notices.
 *
 * Path parameters differ in notation — the router writes `:token`, OpenAPI
 * writes `{token}` — so both sides are normalised before comparison.
 *
 * Usage:
 *   php bin/openapi.php check      compare the spec against the routes
 *   php bin/openapi.php routes     print the routing table
 *
 * The command functions are named to avoid colliding with bin/seed.php's — the
 * two never load together at runtime, but PHPStan analyses both and global
 * function names are shared.
 */

use Rajdhani\Support\Env;
use Symfony\Component\Yaml\Yaml;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

if (!class_exists(Yaml::class)) {
    fwrite(STDERR, "symfony/yaml is a dev dependency; run composer install without --no-dev.\n");
    exit(1);
}

Env::load($basePath . '/.env');

const SPEC_PATH = __DIR__ . '/../docs/openapi.yaml';

/**
 * `/auth/admin/invite/:token/accept` and `/auth/admin/invite/{token}/accept`
 * are the same endpoint. Parameter *names* are not compared — only position —
 * because a rename is a documentation change, not a contract change.
 */
function normalise(string $path): string
{
    $path = preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;
    $path = preg_replace('/:[^\/]+/', '{}', $path) ?? $path;

    return '/' . trim($path, '/');
}

/** @return array<string,true> "METHOD path" => true */
function routesFromCode(string $basePath): array
{
    /** @var \Rajdhani\Http\Router $router */
    $router = require $basePath . '/routes/api.php';
    $found = [];

    foreach ($router->table() as $route) {
        $found[strtoupper($route['method']) . ' ' . normalise($route['path'])] = true;
    }

    return $found;
}

/** @return array<string,true> */
function routesFromSpec(): array
{
    /** @var array<string,mixed> $spec */
    $spec = Yaml::parseFile(SPEC_PATH);
    $found = [];

    /** @var array<string,array<string,mixed>> $paths */
    $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

    foreach ($paths as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            // Keys such as `parameters` and `summary` sit alongside the verbs.
            if (!in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }

            $found[strtoupper((string) $method) . ' ' . normalise((string) $path)] = true;
        }
    }

    return $found;
}

function cmdCheck(string $basePath): int
{
    if (!is_file(SPEC_PATH)) {
        fwrite(STDERR, "No specification at docs/openapi.yaml\n");

        return 1;
    }

    $code = routesFromCode($basePath);
    $spec = routesFromSpec();

    $undocumented = array_diff_key($code, $spec);
    $phantom = array_diff_key($spec, $code);

    printf("  %d routes registered, %d documented\n\n", count($code), count($spec));

    foreach (array_keys($undocumented) as $route) {
        printf("  UNDOCUMENTED  %s\n", $route);
    }

    foreach (array_keys($phantom) as $route) {
        printf("  NOT A ROUTE   %s\n", $route);
    }

    if ($undocumented !== [] || $phantom !== []) {
        fwrite(STDERR, "\n  The specification and the routes disagree.\n");
        fwrite(STDERR, "  Update docs/openapi.yaml — a wrong API document is worse than none.\n");

        return 1;
    }

    echo "  the specification matches the routing table\n";

    return 0;
}

function cmdRoutes(string $basePath): int
{
    foreach (array_keys(routesFromCode($basePath)) as $route) {
        printf("  %s\n", $route);
    }

    return 0;
}

exit(match ($argv[1] ?? 'check') {
    'check'  => cmdCheck($basePath),
    'routes' => cmdRoutes($basePath),
    default => (function (): int {
        fwrite(STDERR, "usage: openapi.php {check|routes}\n");

        return 2;
    })(),
});
