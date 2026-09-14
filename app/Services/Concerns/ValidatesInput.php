<?php

declare(strict_types=1);

namespace Rajdhani\Services\Concerns;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;

/**
 * The scalar field validators every admin-write service needs.
 *
 * Extracted once `CategoryService` and `ProductService` both needed the same
 * eight checks — required text, optional text, optional int, optional bool,
 * a ULID reference, a hex colour. Copying them a second time would have been
 * the moment this stopped being coincidence and started being a pattern
 * nobody had named.
 *
 * Every method reports failure the same way: one `ApiError::validation()`
 * naming the field, so the caller (each service's own field loop) can collect
 * several before giving up, and the client sees every problem in one response
 * rather than one per round trip.
 */
trait ValidatesInput
{
    /** @param array<string,mixed> $input */
    private function requiredText(array $input, string $field, int $maxLength): string
    {
        $value = $input[$field] ?? null;

        if (!is_scalar($value) || trim((string) $value) === '') {
            throw $this->invalid($field, 'This field is required');
        }

        $text = trim((string) $value);

        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw $this->invalid($field, "Must be {$maxLength} characters or fewer");
        }

        return $text;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $field, int $maxLength): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw $this->invalid($field, 'Expected a string');
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw $this->invalid($field, "Must be {$maxLength} characters or fewer");
        }

        return $text;
    }

    /**
     * A required, syntactically valid email address — extracted once
     * `EnquiryService`, `DealerApplicationService` and a third caller
     * (`ContactService`/`NewsletterService`, RTPP-31) all needed the exact
     * same two lines: the same "copying it a second time" moment every other
     * trait method here was born from.
     *
     * @param array<string,mixed> $input
     */
    private function requiredEmail(array $input, string $field = 'email', int $maxLength = 255): string
    {
        $email = $this->requiredText($input, $field, $maxLength);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->invalid($field, 'Must be a valid email address');
        }

        return $email;
    }

    /** @param array<string,mixed> $input */
    private function optionalInt(array $input, string $field, int $default): int
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value)) {
            throw $this->invalid($field, 'Expected a number');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalBool(array $input, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return $default;
        }

        return (bool) $input[$field];
    }

    /**
     * A ULID reference to another table — checked for *shape* here, always.
     * Whether it points at a row that actually exists is the caller's job:
     * some references (an image, a category) are worth a friendly pre-check,
     * and this trait cannot know which.
     *
     * @param array<string,mixed> $input
     */
    private function optionalUlid(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !UlidHelper::isValid($value)) {
            throw $this->invalid($field, 'Expected a valid id');
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredUlid(array $input, string $field): string
    {
        $value = $input[$field] ?? null;

        if (!is_string($value) || !UlidHelper::isValid($value)) {
            throw $this->invalid($field, 'This field is required and must be a valid id');
        }

        return $value;
    }

    /**
     * `#RGB`, `#RRGGBB` or `#RRGGBBAA` — the same rule the site theme colours use.
     *
     * @param array<string,mixed> $input
     */
    private function optionalHexColour(array $input, string $field): ?string
    {
        $value = $this->optionalText($input, $field, 9);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
            throw $this->invalid($field, 'Use a hex colour such as #1B5E20');
        }

        return strtoupper($value);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string>        $allowed
     */
    private function optionalEnum(array $input, string $field, array $allowed, string $default): string
    {
        $value = $input[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw $this->invalid($field, 'Must be one of: ' . implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * A `Y-m-d` date or a full ISO 8601 timestamp — the two shapes a date-range
     * query filter needs to accept (RTPP-35's audit log query, and whatever
     * else eventually wants "from"/"to").
     *
     * @param array<string,mixed> $input
     */
    private function optionalDate(array $input, string $field): ?string
    {
        $value = $this->optionalText($input, $field, 32);

        if ($value === null) {
            return null;
        }

        $valid = \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false
            || \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value) !== false;

        if (!$valid) {
            throw $this->invalid($field, 'Expected a date as YYYY-MM-DD or a full ISO 8601 timestamp');
        }

        return $value;
    }

    private function invalid(string $field, string $message): ApiError
    {
        return ApiError::validation('Some fields need attention', [['field' => $field, 'message' => $message]]);
    }
}
