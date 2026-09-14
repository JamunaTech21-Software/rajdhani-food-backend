<?php

declare(strict_types=1);

namespace Rajdhani\Controllers;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;

/**
 * The API reference (doc §5.3, RTPP-16).
 *
 * **Protection.** §5.3 asks for a protected docs path, and a public
 * specification hands an attacker the whole attack surface — every endpoint,
 * every field name, every auth scheme — without them having to find it.
 *
 * But it cannot be behind `RequireAdmin`: a browser navigating to a URL sends no
 * `Authorization` header, so a bearer-gated docs page is a page nobody can open.
 * So:
 *
 *   - outside production, open — a developer on their own machine
 *   - in production, `?token=` matching `DOCS_TOKEN`, compared in constant time
 *   - with no `DOCS_TOKEN` set, closed in production rather than open by default
 *
 * The raw YAML lives in the repository too, so the front-end developer can
 * import it into Postman or Insomnia without needing any of this.
 */
final class DocsController
{
    private const SPEC_RELATIVE = 'docs/openapi.yaml';

    /** Serves the reference page itself. */
    public function show(Request $request): never
    {
        $this->authorise($request);

        $specUrl = (string) config('app.api_prefix', '/api/v1') . '/docs/openapi.yaml';
        $token = $request->query('token');
        $query = is_string($token) && $token !== '' ? '?token=' . rawurlencode($token) : '';

        // The API's own CSP is `default-src 'none'`, correct for JSON and fatal
        // for a page that has to load a rendering library. Widened here and
        // here only, to the one CDN it uses.
        header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline' https://cdn.redoc.ly; "
            . "style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
            . "img-src data:; connect-src 'self'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');

        $escapedSpec = htmlspecialchars($specUrl . $query, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <meta name="robots" content="noindex, nofollow">
              <title>Rajdhani Tea Platform API</title>
              <style>body { margin: 0; font-family: system-ui, sans-serif; }</style>
            </head>
            <body>
              <redoc spec-url="{$escapedSpec}"></redoc>
              <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
            </body>
            </html>
            HTML;

        exit;
    }

    /**
     * Serves the specification itself.
     *
     * As YAML rather than converted to JSON: Redoc, Swagger UI, Postman and
     * Insomnia all read YAML, and converting would mean parsing it at request
     * time with a library that is deliberately dev-only.
     */
    public function spec(Request $request): never
    {
        $this->authorise($request);

        $path = base_path(self::SPEC_RELATIVE);
        $yaml = is_file($path) ? file_get_contents($path) : false;

        if ($yaml === false) {
            throw ApiError::notFound('The API specification is not available.');
        }

        header('Content-Type: application/yaml; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');

        echo $yaml;

        exit;
    }

    private function authorise(Request $request): void
    {
        if (config('app.env') !== 'production') {
            return;
        }

        $expected = env('DOCS_TOKEN');

        // Absent means closed. Defaulting to open would publish the whole
        // surface the first time someone deployed without reading this file.
        if (!is_string($expected) || $expected === '') {
            throw ApiError::notFound('Not found');
        }

        $presented = $request->query('token');

        if (!is_string($presented) || !hash_equals($expected, $presented)) {
            // 404 rather than 401: an unauthenticated caller should not learn
            // that a docs endpoint exists here at all.
            throw ApiError::notFound('Not found');
        }
    }
}
