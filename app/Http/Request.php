<?php

declare(strict_types=1);

namespace Rajdhani\Http;

use Rajdhani\Helpers\ApiError;

/**
 * An immutable snapshot of the incoming request.
 *
 * Services and controllers read this, never the superglobals. Section 13 makes
 * that a layering rule rather than a style preference: a service that reaches
 * for $_POST cannot be unit-tested and cannot be reused from a cron job.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Method override, for clients behind proxies that drop PATCH/DELETE.
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($method === 'POST' && is_string($override) && $override !== '') {
            $method = strtoupper($override);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $prefix = (string) config('app.api_prefix', '/api/v1');
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        $path = '/' . trim($path, '/');

        return new self(
            $method,
            $path === '/' ? '/' : rtrim($path, '/'),
            $_GET,
            self::parseBody(),
            self::parseHeaders(),
            $_COOKIE,
            self::clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    private static function parseBody(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');

            if ($raw === false || trim($raw) === '') {
                return [];
            }

            try {
                /** @var array<string,mixed>|scalar|null $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw ApiError::validation('Request body is not valid JSON', [
                    ['field' => '', 'message' => $e->getMessage()],
                ]);
            }

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** @return array<string,string> */
    private static function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function clientIp(): string
    {
        // cPanel sits behind the host's own proxy layer, so REMOTE_ADDR is often
        // the load balancer. X-Forwarded-For is client-controllable and must not
        // be trusted for anything security-critical — it is recorded for
        // diagnostics and rate limiting, not authorisation.
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);

            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) ? $remote : '';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');

        if ($auth === null || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** Middleware attaches resolved state here — the authenticated admin, say. */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
