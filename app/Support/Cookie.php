<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * The refresh-token cookie (doc 7.1, 7.2).
 *
 * Why a cookie at all, when the access token is a bearer header: a refresh token
 * is long-lived, and anything long-lived in JavaScript's reach is one XSS away
 * from a permanent session takeover. `HttpOnly` puts it somewhere script cannot
 * read. The access token stays in memory and dies in twenty minutes.
 *
 * `SameSite=None` is required because the dashboard and the API are different
 * origins (doc 4.1) and the browser will not attach the cookie to a
 * cross-site request otherwise. Browsers refuse `SameSite=None` without
 * `Secure`, which means **over plain HTTP the cookie is silently dropped** — so
 * local development, which runs on http://localhost, falls back to
 * `SameSite=Lax`. That is a development accommodation and nothing else: the
 * moment APP_URL is https, the production pair is used.
 *
 * The path is scoped to the auth namespace so the cookie is not attached to
 * every request in the application — a refresh token has no business travelling
 * with a request for a product list.
 */
final class Cookie
{
    public static function name(string $audience): string
    {
        // Distinct names per audience: a browser signed into both the customer
        // site and the dashboard holds two refresh tokens, and one must not
        // overwrite the other.
        return "rajdhani_{$audience}_refresh";
    }

    public static function setRefresh(string $audience, string $token, int $expiresAtUnix): void
    {
        self::write(self::name($audience), $token, $expiresAtUnix);
    }

    public static function clearRefresh(string $audience): void
    {
        // Same attributes as when it was set — a browser matches on name, path
        // and domain, and a mismatched delete leaves the cookie in place.
        self::write(self::name($audience), '', time() - 3600);
    }

    /** True when the connection is HTTPS, which is what SameSite=None requires. */
    public static function secure(): bool
    {
        $url = (string) config('app.url', '');

        return str_starts_with($url, 'https://') || config('app.env') === 'production';
    }

    public static function path(): string
    {
        return (string) config('app.api_prefix', '/api/v1') . '/auth';
    }

    private static function write(string $name, string $value, int $expires): void
    {
        $secure = self::secure();
        $domain = config('cors.cookie_domain');

        $options = [
            'expires'  => $expires,
            'path'     => self::path(),
            'secure'   => $secure,
            'httponly' => true,

            // Lax is not a weaker choice made for convenience — it is the only
            // value a browser will accept without Secure. Cross-origin refresh
            // does not work under it, which is exactly why production must be
            // HTTPS.
            'samesite' => $secure ? 'None' : 'Lax',
        ];

        if (is_string($domain) && $domain !== '') {
            $options['domain'] = $domain;
        }

        // Guarded so that a unit test, or any code that has already written a
        // body, does not emit a "headers already sent" warning.
        if (!headers_sent()) {
            setcookie($name, $value, $options);
        }
    }
}
