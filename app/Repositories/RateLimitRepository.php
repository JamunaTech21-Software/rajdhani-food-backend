<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `rate_limits` — the shared counter behind §14.2.
 *
 * **Why the database and not memory.** PHP-FPM hands each request to whichever
 * worker process is free, and those processes share nothing. An in-memory
 * counter therefore counts only the fraction of requests that happened to land
 * on one worker, and the failure mode is silent: the limit simply never
 * triggers. This is the piece the stack change hurt most (doc §19, deviation 2)
 * — `express-rate-limit` held its counter in one long-lived process, and there
 * is no such process here.
 *
 * The increment has to be atomic, because two requests from the same IP
 * arriving together must produce 2 and not 1. The unique key on `bucket_key`
 * gives every bucket exactly one row to lock, and `SELECT … FOR UPDATE` inside a
 * transaction serialises them — the same idiom §8.2 uses for gapless reference
 * numbers, for the same reason.
 */
final class RateLimitRepository extends Repository
{
    /**
     * Count one request against a bucket and report the state afterwards.
     *
     * @return array{hits:int,resets_at:int} hits *including* this request
     */
    public function hit(string $key, int $windowSeconds): array
    {
        $key = mb_substr($key, 0, 191);

        // A test — or a service — may already have a transaction open. Nesting
        // begin() would throw, and committing here would end theirs early.
        $started = false;

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $started = true;
        }

        try {
            // Create the row if it is new, with hits at zero so that the single
            // UPDATE below is the only thing that ever counts. Seeding it at 1
            // would make the first request count twice under a race.
            $this->run(
                'INSERT IGNORE INTO rate_limits (id, bucket_key, window_start, hits, expires_at)
                 VALUES (:id, :bucket_key, :window_start, 0, :expires_at)',
                [
                    ':id'           => UlidHelper::generate(),
                    ':bucket_key'   => $key,
                    ':window_start' => $this->now(),
                    ':expires_at'   => $this->now($windowSeconds),
                ],
            );

            /** @var array{hits:int|string,expires_at:string}|null $row */
            $row = $this->one(
                'SELECT hits, expires_at FROM rate_limits WHERE bucket_key = :bucket_key FOR UPDATE',
                [':bucket_key' => $key],
            );

            if ($row === null) {
                // Unreachable after the INSERT IGNORE above, but a limiter that
                // throws on its own bookkeeping would take the site down.
                return ['hits' => 1, 'resets_at' => time() + $windowSeconds];
            }

            $expiresAt = strtotime((string) $row['expires_at'] . ' UTC');
            $expired = $expiresAt === false || $expiresAt <= time();

            if ($expired) {
                // The window rolled over. Start a new one at this request.
                $this->run(
                    'UPDATE rate_limits
                        SET hits = 1, window_start = :window_start, expires_at = :expires_at
                      WHERE bucket_key = :bucket_key',
                    [
                        ':window_start' => $this->now(),
                        ':expires_at'   => $this->now($windowSeconds),
                        ':bucket_key'   => $key,
                    ],
                );

                $hits = 1;
                $resetsAt = time() + $windowSeconds;
            } else {
                $this->run(
                    'UPDATE rate_limits SET hits = hits + 1 WHERE bucket_key = :bucket_key',
                    [':bucket_key' => $key],
                );

                $hits = (int) $row['hits'] + 1;
                $resetsAt = $expiresAt;
            }

            if ($started) {
                $this->db->commit();

                // Cleared before returning, so the catch below cannot try to
                // roll back a transaction that has already been committed —
                // commit() itself can throw.
                $started = false;
            }

            return ['hits' => $hits, 'resets_at' => $resetsAt];
        } catch (\Throwable $e) {
            if ($started) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * How many hits a bucket holds, without counting against it. Zero when the
     * bucket does not exist — an absent bucket and an empty one mean the same
     * thing to every caller.
     */
    public function hitsFor(string $key): int
    {
        $hits = $this->scalar(
            'SELECT hits FROM rate_limits WHERE bucket_key = :bucket_key',
            [':bucket_key' => mb_substr($key, 0, 191)],
        );

        return is_numeric($hits) ? (int) $hits : 0;
    }

    public function forget(string $key): void
    {
        $this->run('DELETE FROM rate_limits WHERE bucket_key = :bucket_key', [':bucket_key' => $key]);
    }

    /**
     * Nightly prune (§16.5). Without it the table gains a row per distinct IP
     * for ever, and on a shared account disk is the quota that bites first.
     */
    public function deleteExpired(): int
    {
        return $this->run('DELETE FROM rate_limits WHERE expires_at < :now', [':now' => $this->now()]);
    }
}
