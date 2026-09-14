<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

use PDO;
use Rajdhani\Helpers\UlidHelper;
use RuntimeException;

/**
 * Base class for the seeders in this directory (doc 8.9, RTPP-10).
 *
 * Seeding runs against databases that already contain data — a developer's
 * working copy, a staging site the client has been clicking through — so the
 * only acceptable behaviour is that a second run changes nothing. "Delete
 * everything and reinsert" is specifically forbidden: products, categories and
 * admins are the targets of foreign keys, and truncating them would either fail
 * or cascade into content that was not seeded.
 *
 * That leaves two write strategies, and which one a seeder picks is a decision
 * about ownership of the data:
 *
 *   upsert()          Reference data this project owns — the 64 districts, the
 *                     default SEO rows, the settings keys. Re-running corrects
 *                     drift, which is the point: if a district name was fixed
 *                     here, the next run should fix it there.
 *
 *   insertIfAbsent()  Content the client owns once it exists — the site
 *                     profile, products, banners, copy. Seeded values are
 *                     scaffolding (doc 19: placeholder copy, replaced by
 *                     RTPP-86). Re-running must never overwrite what an editor
 *                     typed into the admin panel.
 *
 * Both return the id the row *actually* has, which on a conflict is the
 * existing row's id and not the ULID generated for the attempt. Child rows must
 * use that return value, never a locally generated one.
 */
abstract class Seeder
{
    /** Cached per process: the row-alias form of ON DUPLICATE KEY UPDATE needs MySQL 8.0.19+. */
    private static ?bool $rowAliasSupported = null;

    /** @var array<string,array{inserted:int,updated:int,unchanged:int}> */
    private array $stats = [];

    public function __construct(protected readonly PDO $db)
    {
    }

    abstract public function run(): void;

    /**
     * Tables this seeder writes, so the runner can report row counts — the
     * evidence that a second run added nothing.
     *
     * @return string[]
     */
    abstract public function tables(): array;

    public function label(): string
    {
        $parts = explode('\\', static::class);

        return (string) end($parts);
    }

    /** @return array<string,array{inserted:int,updated:int,unchanged:int}> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Insert, or correct the existing row in place.
     *
     * @param array<string,scalar|null> $natural the unique key that identifies the row across runs
     * @param array<string,scalar|null> $values  everything else
     */
    protected function upsert(string $table, array $natural, array $values): string
    {
        $this->write($table, ['id' => UlidHelper::generate()] + $natural + $values, array_keys($values));

        $id = $this->findId($table, $natural);

        if ($id === null) {
            throw new RuntimeException("Upsert into {$table} produced no row.");
        }

        return $id;
    }

    /**
     * Same, for a table whose primary key is supplied rather than generated —
     * `site_profile`, whose key is pinned to 1 by a CHECK constraint.
     *
     * @param array<string,scalar|null> $row
     * @param string[]                  $updatable columns to correct on conflict
     */
    protected function upsertWithKey(string $table, array $row, array $updatable): void
    {
        $this->write($table, $row, $updatable);
    }

    /**
     * Insert only when the natural key is absent. An existing row is left
     * exactly as it is, whoever last edited it.
     *
     * @param array<string,scalar|null> $natural
     * @param array<string,scalar|null> $values
     */
    protected function insertIfAbsent(string $table, array $natural, array $values): string
    {
        $existing = $this->findId($table, $natural);

        if ($existing !== null) {
            $this->record($table, 'unchanged');

            return $existing;
        }

        $row = ['id' => UlidHelper::generate()] + $natural + $values;
        $columns = array_keys($row);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        $this->execute($sql, $this->parameters($row));
        $this->record($table, 'inserted');

        /** @var string $id */
        $id = $row['id'];

        return $id;
    }

    /** @param array<string,scalar|null> $natural */
    protected function findId(string $table, array $natural): ?string
    {
        $where = [];

        foreach (array_keys($natural) as $column) {
            $where[] = $this->quote($column) . ' = :' . $column;
        }

        $statement = $this->execute(
            sprintf('SELECT id FROM %s WHERE %s LIMIT 1', $this->quote($table), implode(' AND ', $where)),
            $this->parameters($natural),
        );

        $value = $statement->fetchColumn();

        // fetchColumn() returns false for "no row" — and false is a scalar, so
        // a is_scalar() test here silently turns a miss into an empty string and
        // then into a foreign key violation three seeders later.
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * The placeholder media asset MediaSeeder created under this key, or null
     * when it has not run. Content seeders attach images this way rather than
     * being handed ids, so each one can be run on its own.
     */
    protected function mediaId(string $key): ?string
    {
        return $this->findId('media_assets', ['public_id' => "rajdhani/demo/{$key}"]);
    }

    /**
     * Decode one of the JSON files in database/seed-data/.
     *
     * They live inside backend/api rather than at the project root so that the
     * seeders still work from the deployed directory, which is the only part of
     * the repository that reaches the server (doc 16.4).
     *
     * @return array<mixed>
     */
    protected function seedData(string $file): array
    {
        $path = base_path("database/seed-data/{$file}");
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new RuntimeException("Cannot read seed data at {$path}.");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException("{$file} does not decode to an array.");
        }

        return $decoded;
    }

    /** UTC, millisecond precision, to match the DATETIME(3) columns in section 8. */
    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * @param array<string,mixed> $parameters
     */
    protected function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);

        // ERRMODE_EXCEPTION makes this unreachable, but the signature admits it.
        if ($statement === false) {
            throw new RuntimeException("Could not prepare: {$sql}");
        }

        $statement->execute($parameters);

        return $statement;
    }

    /**
     * The ON DUPLICATE KEY UPDATE write shared by both upsert entry points.
     *
     * @param array<string,scalar|null> $row
     * @param string[]                  $updatable
     */
    private function write(string $table, array $row, array $updatable): void
    {
        $columns = array_keys($row);

        // created_at records when the row first appeared; a correcting run must
        // not rewrite it. updated_at is expected to move and is left in.
        $updatable = array_values(array_diff($updatable, ['id', 'created_at']));

        $assignments = $this->rowAliasSupported()
            ? array_map(fn (string $c): string => $this->quote($c) . ' = seeded.' . $this->quote($c), $updatable)
            : array_map(fn (string $c): string => $this->quote($c) . ' = VALUES(' . $this->quote($c) . ')', $updatable);

        if ($assignments === []) {
            // Nothing to correct. Assigning a column to itself keeps the
            // statement a legal no-op rather than a duplicate-key error.
            $assignments = [$this->quote('id') . ' = ' . $this->quote($table) . '.' . $this->quote('id')];
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)%s ON DUPLICATE KEY UPDATE %s',
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
            $this->rowAliasSupported() ? ' AS seeded' : '',
            implode(', ', $assignments),
        );

        $statement = $this->execute($sql, $this->parameters($row));

        // MySQL reports 1 for an insert, 2 for an update that changed something,
        // 0 for a conflict that changed nothing. That is exactly the signal the
        // idempotency check needs.
        $this->record($table, match ($statement->rowCount()) {
            1       => 'inserted',
            0       => 'unchanged',
            default => 'updated',
        });
    }

    /**
     * MySQL 8.0.20 deprecated VALUES() inside ON DUPLICATE KEY UPDATE in favour
     * of a row alias, and warns on every statement that uses it. The alias form
     * is not available before 8.0.19 and not at all on MariaDB, which shared
     * cPanel accounts sometimes ship instead (RTPP-90 is still unconfirmed), so
     * the syntax is chosen from the server rather than assumed.
     */
    private function rowAliasSupported(): bool
    {
        if (self::$rowAliasSupported !== null) {
            return self::$rowAliasSupported;
        }

        $version = $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = is_string($version) ? $version : '';

        self::$rowAliasSupported = stripos($version, 'mariadb') === false
            && version_compare($version, '8.0.19', '>=');

        return self::$rowAliasSupported;
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return array<string,scalar|null>
     */
    private function parameters(array $row): array
    {
        $bound = [];

        foreach ($row as $column => $value) {
            $bound[':' . $column] = is_bool($value) ? (int) $value : $value;
        }

        return $bound;
    }

    /** @param 'inserted'|'updated'|'unchanged' $outcome */
    private function record(string $table, string $outcome): void
    {
        $counts = $this->stats[$table] ?? ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        $counts[$outcome]++;

        $this->stats[$table] = $counts;
    }

    /** Column and table names are literals in this directory, never user input. */
    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
