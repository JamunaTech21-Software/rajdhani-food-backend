<?php

declare(strict_types=1);

/**
 * Forward-only migration runner (doc 16.4).
 *
 * There is no ORM and no framework tooling here, so this file is the whole
 * mechanism: it applies pending .sql files in filename order, records each in a
 * `migrations` table, and refuses to run one twice.
 *
 * Two properties matter more than convenience:
 *
 *   1. Re-running is a no-op. The Phase 1 Definition of Done requires that a
 *      virgin database reaches the full schema and that a second run changes
 *      nothing.
 *   2. An applied file is immutable. Each row stores the sha256 of the file as
 *      it was applied; if the file later differs, the runner stops rather than
 *      silently diverging from production. Doc 16.4: migrations are never
 *      edited after being applied.
 *
 * Usage:
 *   php bin/migrate.php status     show applied / pending
 *   php bin/migrate.php up         apply everything pending
 *   php bin/migrate.php verify     check applied files still match their checksums
 */

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';

function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $path = __DIR__ . '/../.env';
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }

    return $vars[$key] ?? getenv($key) ?: $default;
}

function connect(): PDO
{
    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $name = env('DB_DATABASE');
    $sock = env('DB_SOCKET');

    if ($name === null || $name === '') {
        fwrite(STDERR, "DB_DATABASE is not set. Copy .env.example to .env first.\n");
        exit(1);
    }

    $dsn = $sock
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $sock, $name, env('DB_CHARSET', 'utf8mb4'))
        : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, env('DB_CHARSET', 'utf8mb4'));

    try {
        return new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
        exit(1);
    }
}

/** @return string[] absolute paths, in apply order */
function migrationFiles(): array
{
    $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function ensureBookkeeping(PDO $db): void
{
    // 000_migrations_table.sql creates this, but it must exist before we can
    // read what has been applied — so it is applied unconditionally and is
    // written with IF NOT EXISTS.
    $bootstrap = MIGRATIONS_DIR . '/000_migrations_table.sql';

    if (!is_file($bootstrap)) {
        return;
    }

    $sql = file_get_contents($bootstrap);

    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$bootstrap}\n");
        exit(1);
    }

    $db->exec($sql);
}

/** @return array<string,string> filename => checksum */
function applied(PDO $db): array
{
    $statement = $db->query('SELECT filename, checksum FROM migrations ORDER BY id');

    if ($statement === false) {
        fwrite(STDERR, "Cannot read the migrations table.\n");
        exit(1);
    }

    /** @var array<int,array{filename:string,checksum:string}> $rows */
    $rows = $statement->fetchAll();

    return array_column($rows, 'checksum', 'filename');
}

function cmdStatus(PDO $db): int
{
    ensureBookkeeping($db);
    $done = applied($db);
    $pending = 0;
    foreach (migrationFiles() as $file) {
        $name = basename($file);
        if (isset($done[$name])) {
            $drift = $done[$name] === hash_file('sha256', $file) ? '' : '  ** CHANGED SINCE APPLIED **';
            printf("  applied  %s%s\n", $name, $drift);
        } else {
            printf("  PENDING  %s\n", $name);
            $pending++;
        }
    }
    printf("\n%d pending\n", $pending);
    return 0;
}

function cmdVerify(PDO $db, bool $quiet = false): int
{
    ensureBookkeeping($db);
    $done = applied($db);
    $bad = [];
    foreach (migrationFiles() as $file) {
        $name = basename($file);
        if (isset($done[$name]) && $done[$name] !== hash_file('sha256', $file)) {
            $bad[] = $name;
        }
    }
    if ($bad) {
        fwrite(STDERR, "Applied migrations have been edited:\n  " . implode("\n  ", $bad) . "\n");
        fwrite(STDERR, "Migrations are forward-only (doc 16.4). Add a new migration instead.\n");
        return 1;
    }
    if (!$quiet) {
        echo "all applied migrations match their recorded checksums\n";
    }
    return 0;
}

function cmdUp(PDO $db): int
{
    ensureBookkeeping($db);
    if (cmdVerify($db, quiet: true) !== 0) {
        return 1;
    }
    $done = applied($db);
    $insert = $db->prepare(
        'INSERT INTO migrations (filename, checksum, applied_at) VALUES (:f, :c, :t)'
    );

    $count = 0;
    foreach (migrationFiles() as $file) {
        $name = basename($file);
        if (isset($done[$name])) {
            continue;
        }
        $sql = file_get_contents($file);

        if ($sql === false) {
            fwrite(STDERR, "\n  Cannot read {$name}\n");

            return 1;
        }

        // MySQL cannot roll back DDL, so a failed migration leaves a partial
        // schema. Nothing is recorded in that case, and the file is re-attempted
        // on the next run — which is why every statement must be safe to reach
        // from a clean database rather than from a half-applied one.
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            fwrite(STDERR, "\n  FAILED  {$name}\n  {$e->getMessage()}\n");
            fwrite(STDERR, "\n  The schema may be partially applied. Inspect before retrying.\n");
            return 1;
        }

        $insert->execute([
            ':f' => $name,
            ':c' => hash_file('sha256', $file),
            ':t' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);
        printf("  applied  %s\n", $name);
        $count++;
    }

    printf("\n%s\n", $count === 0 ? 'nothing to do' : "{$count} migration(s) applied");
    return 0;
}

$command = $argv[1] ?? 'status';
$db = connect();

exit(match ($command) {
    'up'     => cmdUp($db),
    'status' => cmdStatus($db),
    'verify' => cmdVerify($db),
    default  => (function () {
        fwrite(STDERR, "usage: migrate.php {up|status|verify}\n");
        return 2;
    })(),
});
