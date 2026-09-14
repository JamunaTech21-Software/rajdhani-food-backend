<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * The section 9.1 envelope, and the only thing that should ever write a body.
 *
 * Every response is one of exactly two shapes:
 *
 *   { "success": true,  "data": …, "meta": … }
 *   { "success": false, "error": { "code", "message", "details" } }
 *
 * Consistency here is what lets the front-end write one response handler
 * instead of one per endpoint.
 */
final class ApiResponse
{
    /** @var array<string,string> */
    private static array $headers = [];

    public static function header(string $name, string $value): void
    {
        self::$headers[$name] = $value;
    }

    /**
     * @param array<string,mixed>|null $meta
     */
    public static function success(mixed $data = null, ?array $meta = null, int $status = 200): never
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        self::send($payload, $status);
    }

    public static function created(mixed $data = null): never
    {
        self::success($data, null, 201);
    }

    public static function noContent(): never
    {
        self::send(null, 204);
    }

    /**
     * @param array<int,array<string,string>> $details
     */
    public static function error(ErrorCode $code, string $message, array $details = [], ?int $status = null): never
    {
        $error = ['code' => $code->value, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        self::send(['success' => false, 'error' => $error], $status ?? $code->status());
    }

    public static function fromApiError(ApiError $e): never
    {
        self::error($e->errorCode(), $e->getMessage(), $e->details());
    }

    private static function send(mixed $payload, int $status): never
    {
        if (!headers_sent()) {
            http_response_code($status);

            foreach (self::$headers as $name => $value) {
                header("{$name}: {$value}");
            }

            if ($status !== 204) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }

        if ($status !== 204 && $payload !== null) {
            echo json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        exit;
    }
}
