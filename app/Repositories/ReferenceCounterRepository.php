<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `reference_counters` (doc §8.2, RTPP-29) — one locked row per `(type, year)`,
 * the mechanism that makes a reference series gapless where an
 * `AUTO_INCREMENT` cannot: a rolled-back insert burns an `AUTO_INCREMENT`
 * value permanently, but a counter row only advances when the surrounding
 * transaction actually commits, because the `UPDATE` that advances it is
 * inside that same transaction (see `EnquiryService::submit()`).
 *
 * `nextSequence()` must always be called from inside a transaction the
 * caller controls — it does not open one itself, because the number it
 * hands back is only meaningful paired with the insert that consumes it,
 * and the two have to commit or roll back together.
 *
 * The locking idiom — seed the row at zero with `INSERT IGNORE` so a fresh
 * `(type, year)` never double-counts its first caller under a race, then
 * `SELECT … FOR UPDATE` to serialise concurrent callers, then `UPDATE` —
 * is similar to `RateLimitRepository::hit()`, for the same reason: two
 * requests must never see the same next value. One difference from that
 * repository, found by load-testing this one specifically (a 50-process
 * fork test, `EnquiryConcurrencyTest`): `SELECT … FOR UPDATE` is tried
 * *first*, and `INSERT IGNORE` only runs when that comes back empty. Every
 * call issuing `INSERT IGNORE` unconditionally — the `rate_limits` shape —
 * means every concurrent caller takes an insert-intention lock on the same
 * not-yet-existing key at once, which is exactly the InnoDB gap-lock
 * pattern that produces a genuine deadlock under real contention. Once the
 * row exists (true for all but the very first caller in a given year),
 * every subsequent call is a plain record lock on an existing row instead —
 * the ordinary, non-deadlock-prone case. `EnquiryService::submitWithDeadlockRetry()`
 * is the remaining safety net for the row's genuine first creation, which
 * this reordering narrows but cannot eliminate.
 */
final class ReferenceCounterRepository extends Repository
{
    /** Assumes an open transaction; advances `(type, year)` by one and returns the new value. */
    public function nextSequence(string $type, int $year): int
    {
        /** @var array{last_seq:int|string}|null $row */
        $row = $this->one(
            'SELECT last_seq FROM reference_counters WHERE type = :type AND year = :year FOR UPDATE',
            [':type' => $type, ':year' => $year],
        );

        if ($row === null) {
            $this->run(
                'INSERT IGNORE INTO reference_counters (id, type, year, last_seq, updated_at)
                 VALUES (:id, :type, :year, 0, :updated_at)',
                [':id' => UlidHelper::generate(), ':type' => $type, ':year' => $year, ':updated_at' => $this->now()],
            );

            /** @var array{last_seq:int|string}|null $row */
            $row = $this->one(
                'SELECT last_seq FROM reference_counters WHERE type = :type AND year = :year FOR UPDATE',
                [':type' => $type, ':year' => $year],
            );
        }

        $next = ((int) ($row['last_seq'] ?? 0)) + 1;

        $this->run(
            'UPDATE reference_counters SET last_seq = :next, updated_at = :updated_at
              WHERE type = :type AND year = :year',
            [':next' => $next, ':updated_at' => $this->now(), ':type' => $type, ':year' => $year],
        );

        return $next;
    }
}
