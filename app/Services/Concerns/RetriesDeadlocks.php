<?php

declare(strict_types=1);

namespace Rajdhani\Services\Concerns;

use PDOException;

/**
 * Retries a closure that fails on a genuine InnoDB deadlock (SQLSTATE 40001).
 *
 * Extracted from `EnquiryService::submitWithDeadlockRetry()` (RTPP-29) once
 * `DealerApplicationService` needed the identical wrapper around its own
 * `reference_counters` insert — the same situation `ValidatesInput`'s class
 * doc describes: copying it a second time is the moment this stops being
 * coincidence and starts being a pattern nobody had named.
 *
 * Both callers lock the *same table*'s counter row (`reference_counters`,
 * keyed by `(type, year)`) inside a transaction that also inserts the row
 * that consumes the sequence number. A deadlock rolls back the whole
 * transaction, counter advance included, so retrying from here — a fresh
 * attempt, sequence number and all — loses no correctness: MySQL's own
 * documentation is explicit that an application must retry a deadlocked
 * transaction. `EnquiryConcurrencyTest`'s 50-process fork load test is what
 * proved this retry has a reason to exist, not reasoning about it.
 *
 * A small random backoff before each retry matters as much as the retry
 * itself: many contenders deadlock at once, and retrying all of them at the
 * same instant just re-collides them.
 */
trait RetriesDeadlocks
{
    /**
     * @template T
     *
     * @param callable(): T $attempt
     *
     * @return T
     */
    private function retryOnDeadlock(callable $attempt, int $attemptsLeft = 10): mixed
    {
        try {
            return $attempt();
        } catch (PDOException $e) {
            if ($attemptsLeft > 1 && $e->getCode() === '40001') {
                usleep(random_int(5_000, 30_000));

                return $this->retryOnDeadlock($attempt, $attemptsLeft - 1);
            }

            throw $e;
        }
    }
}
