<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use RuntimeException;
use Throwable;

/**
 * An error that is safe to show a client, carrying its own HTTP status and
 * section 9.1 error code.
 *
 * Anything thrown that is *not* an ApiError is treated as a bug: the handler
 * logs it and returns a generic INTERNAL_ERROR, so an unexpected exception can
 * never leak a stack trace or a database message to a caller.
 */
class ApiError extends RuntimeException
{
    /** @var array<int,array<string,string>> */
    private array $details;

    // Not $code: Exception already declares that as a protected int.
    private ErrorCode $errorCode;

    /**
     * @param array<int,array<string,string>> $details
     */
    public function __construct(
        ErrorCode $code,
        string $message,
        array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code->status(), $previous);

        $this->errorCode = $code;
        $this->details = $details;
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    /** @return array<int,array<string,string>> */
    public function details(): array
    {
        return $this->details;
    }

    /** @param array<int,array<string,string>> $details */
    public static function validation(string $message = 'Invalid input', array $details = []): self
    {
        return new self(ErrorCode::VALIDATION_ERROR, $message, $details);
    }

    public static function unauthenticated(string $message = 'Authentication required'): self
    {
        return new self(ErrorCode::UNAUTHENTICATED, $message);
    }

    public static function tokenExpired(string $message = 'Token has expired'): self
    {
        return new self(ErrorCode::TOKEN_EXPIRED, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that'): self
    {
        return new self(ErrorCode::FORBIDDEN, $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(ErrorCode::NOT_FOUND, $message);
    }

    /** @param array<int,array<string,string>> $details */
    public static function conflict(string $message = 'Conflict', array $details = []): self
    {
        return new self(ErrorCode::CONFLICT, $message, $details);
    }

    public static function rateLimited(string $message = 'Too many requests'): self
    {
        return new self(ErrorCode::RATE_LIMITED, $message);
    }

    public static function uploadFailed(string $message = 'Upload failed'): self
    {
        return new self(ErrorCode::UPLOAD_FAILED, $message);
    }

    public static function internal(string $message = 'Something went wrong', ?Throwable $previous = null): self
    {
        return new self(ErrorCode::INTERNAL_ERROR, $message, [], $previous);
    }
}
