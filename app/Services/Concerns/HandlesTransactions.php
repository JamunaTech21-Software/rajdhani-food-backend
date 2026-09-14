<?php

declare(strict_types=1);

namespace Rajdhani\Services\Concerns;

/**
 * A nested-transaction-safe `beginTransaction()`/`commit()`/`rollBack()`
 * wrapper, extracted once `ProductService` (RTPP-19) and `MediaService`
 * (RTPP-22) both needed the identical thing.
 *
 * A caller already inside a transaction is not a hypothetical — the test
 * suite wraps every test in one so it can roll back after, and a future bulk
 * job might legitimately wrap several service calls in one transaction of its
 * own. Naively skipping `beginTransaction()` when already nested (the way
 * `RateLimitRepository` does, correctly, for its own simpler case) would
 * silently drop the atomicity this method exists to guarantee: a failure
 * partway through would leave whatever ran before it committed as part of
 * the outer transaction, with no rollback ever having run. This is exactly
 * the bug `ProductService::transaction()` had on its first draft, caught by
 * a test running inside `DatabaseTestCase`'s own wrapping transaction before
 * it ever reached a real caller.
 *
 * `SAVEPOINT` is what makes both cases correct with the same code: at the top
 * level it behaves like an ordinary transaction; nested, a failure rolls back
 * only this method's own writes and leaves the outer transaction exactly as
 * it was, free to continue or fail on its own terms.
 *
 * A using class must declare `private readonly \PDO $db;`.
 */
trait HandlesTransactions
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $nested = $this->db->inTransaction();
        $savepoint = 'sp_' . bin2hex(random_bytes(8));

        if ($nested) {
            $this->db->exec("SAVEPOINT {$savepoint}");
        } else {
            $this->db->beginTransaction();
        }

        try {
            $result = $work();

            if ($nested) {
                $this->db->exec("RELEASE SAVEPOINT {$savepoint}");
            } else {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($nested) {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            } else {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
