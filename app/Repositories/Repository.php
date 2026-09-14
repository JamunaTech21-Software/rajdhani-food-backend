<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use PDO;
use PDOStatement;
use Rajdhani\Support\Database;
use RuntimeException;

/**
 * Base for the repository layer (doc 13).
 *
 * **Repositories are the only place SQL is written.** A service that builds a
 * query, or a controller that touches PDO, breaks the one rule that makes the
 * data access reviewable: every statement in the application is in this
 * directory, so "is anything interpolating user input into SQL?" is a question
 * with a bounded answer.
 *
 * The connection is injectable but defaults to the shared one, so production
 * code constructs a repository with no arguments and a test can hand it a
 * connection inside a transaction it intends to roll back.
 */
abstract class Repository
{
    protected PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::connection();
    }

    /**
     * @param array<string,scalar|null> $parameters
     *
     * @return array<string,mixed>|null
     */
    protected function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->statement($sql, $parameters)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,scalar|null> $parameters
     *
     * @return list<array<string,mixed>>
     */
    protected function all(string $sql, array $parameters = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->statement($sql, $parameters)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string,scalar|null> $parameters
     *
     * @return int rows affected
     */
    protected function run(string $sql, array $parameters = []): int
    {
        return $this->statement($sql, $parameters)->rowCount();
    }

    /** @param array<string,scalar|null> $parameters */
    protected function scalar(string $sql, array $parameters = []): mixed
    {
        $value = $this->statement($sql, $parameters)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** UTC with millisecond precision, matching the DATETIME(3) columns in section 8. */
    protected function now(int $offsetSeconds = 0): string
    {
        $moment = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($offsetSeconds !== 0) {
            $moment = $moment->modify(($offsetSeconds > 0 ? '+' : '-') . abs($offsetSeconds) . ' seconds');
        }

        return $moment->format('Y-m-d H:i:s.v');
    }

    /** @param array<string,scalar|null> $parameters */
    private function statement(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->db->prepare($sql);

        // ERRMODE_EXCEPTION makes this unreachable; the signature still admits it.
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare a statement.');
        }

        $statement->execute($this->bindable($parameters));

        return $statement;
    }

    /**
     * `PDOStatement::execute($array)` binds every value as a string unless told
     * otherwise, and PHP's `(string) false` is `''` — not `'0'`. Bound against a
     * `TINYINT` column under `PDO::ATTR_EMULATE_PREPARES = false`, that empty
     * string is a real value MySQL receives and strict mode rejects outright:
     * `Incorrect integer value: '' for column 'is_active'`. `true` is silently
     * safer (`'1'` parses as 1) which is exactly what makes this easy to miss —
     * a test that only ever sets a flag to true would never see it.
     *
     * Every column in this schema that stores a PHP boolean is a native
     * `TINYINT(1)`, so converting to int here is correct everywhere, not a
     * special case for one caller. Existing repositories route around the
     * problem by writing `$bool ? 1 : 0` at the call site; this is the same
     * fix, made once, so a future repository cannot reintroduce it.
     *
     * @param array<string,scalar|null> $parameters
     *
     * @return array<string,scalar|null>
     */
    private function bindable(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_bool($value)) {
                $parameters[$key] = (int) $value;
            }
        }

        return $parameters;
    }
}
