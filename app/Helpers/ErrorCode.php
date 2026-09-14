<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * The closed set of error codes from section 9.1.
 *
 * These are part of the API contract: the front-end switches on `error.code`,
 * not on the message. Adding a value here is a contract change and belongs in
 * the document first.
 */
enum ErrorCode: string
{
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case UNAUTHENTICATED  = 'UNAUTHENTICATED';
    case TOKEN_EXPIRED    = 'TOKEN_EXPIRED';
    case FORBIDDEN        = 'FORBIDDEN';
    case NOT_FOUND        = 'NOT_FOUND';
    case CONFLICT         = 'CONFLICT';
    case RATE_LIMITED     = 'RATE_LIMITED';
    case UPLOAD_FAILED    = 'UPLOAD_FAILED';
    case INTERNAL_ERROR   = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::VALIDATION_ERROR => 422,
            self::UNAUTHENTICATED,
            self::TOKEN_EXPIRED    => 401,
            self::FORBIDDEN        => 403,
            self::NOT_FOUND        => 404,
            self::CONFLICT         => 409,
            self::RATE_LIMITED     => 429,
            self::UPLOAD_FAILED    => 400,
            self::INTERNAL_ERROR   => 500,
        };
    }
}
