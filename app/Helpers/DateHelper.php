<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * MySQL's own `DATETIME(3)` string to the wire format doc §7's Conventions
 * table already promises: "`DATETIME(3)` UTC, application-managed; ISO 8601
 * UTC over the wire."
 *
 * Every `view()`/`adminView()` method in this codebase had instead been
 * sending the column back untouched — `"2026-09-13 08:34:28.127"`, MySQL's
 * own space-separated format, never converted to ISO 8601's `T` and `Z`.
 * Chrome's `Date` parser tolerates the space; Safari's does not and silently
 * returns `Invalid Date`, so every timestamp on the customer site would have
 * rendered as blank on an iPhone while looking fine in Chrome-based local
 * development — precisely the kind of gap that survives every environment
 * an engineer actually tests in.
 *
 * A pure string transform, not a `DateTimeImmutable` round trip: every value
 * this ever receives already came from a `DATETIME(3)` column via
 * `Repository::now()`'s own format, so there is nothing here to parse,
 * validate or get wrong by re-parsing.
 */
final class DateHelper
{
    public static function iso(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', $mysqlDatetime) . 'Z';
    }
}
