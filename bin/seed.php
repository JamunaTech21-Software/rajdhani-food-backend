<?php

declare(strict_types=1);

/**
 * Seed runner (doc 8.9, RTPP-10).
 *
 * Companion to bin/migrate.php: migrations build the schema, this fills it with
 * the rows an empty database needs before anything works — the site profile the
 * header reads, the districts the dealer form offers, the Super Admin somebody
 * has to log in as.
 *
 * Three properties it has to hold:
 *
 *   1. Running twice changes nothing. Every seeder writes through the
 *      idempotent helpers on the Seeder base class; the report below prints
 *      inserted/updated/unchanged per table so that "nothing changed" is
 *      visible rather than assumed.
 *   2. All or nothing. The whole run is one transaction, so a seeder that
 *      fails half way cannot leave a category without its products.
 *   3. It refuses to run against a schema that is behind. Seeding a database
 *      with a pending migration produces a confusing "unknown column" rather
 *      than a useful message.
 *
 * Usage:
 *   php bin/seed.php                    run every seeder
 *   php bin/seed.php --only=NewsSeeder  run one (comma-separated for several)
 *   php bin/seed.php --list             show the seeders and their order
 */

use Rajdhani\Database\Seeders\AdminSeeder;
use Rajdhani\Database\Seeders\CategorySeeder;
use Rajdhani\Database\Seeders\ContentSeeder;
use Rajdhani\Database\Seeders\GallerySeeder;
use Rajdhani\Database\Seeders\LocationSeeder;
use Rajdhani\Database\Seeders\MediaSeeder;
use Rajdhani\Database\Seeders\NavigationSeeder;
use Rajdhani\Database\Seeders\NewsSeeder;
use Rajdhani\Database\Seeders\ProductSeeder;
use Rajdhani\Database\Seeders\Seeder;
use Rajdhani\Database\Seeders\SeoMetaSeeder;
use Rajdhani\Database\Seeders\SettingsSeeder;
use Rajdhani\Database\Seeders\SiteProfileSeeder;
use Rajdhani\Support\Database;
use Rajdhani\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

// A deliberately smaller bootstrap than Kernel::boot(): that one installs a
// shutdown handler which writes an HTTP error envelope, which is wrong for a
// process whose output is a terminal.
Env::load($basePath . '/.env');
date_default_timezone_set((string) config('app.timezone', 'UTC'));

/**
 * Foreign-key order, not alphabetical.
 *
 * Media first because most content points at it. Admin before news, which
 * records an author. Categories before products. Locations have no dependants
 * and could sit anywhere; they are last because they are also the slowest.
 *
 * @var class-string<Seeder>[] $order
 */
$order = [
    MediaSeeder::class,
    AdminSeeder::class,
    SiteProfileSeeder::class,
    SettingsSeeder::class,
    SeoMetaSeeder::class,
    NavigationSeeder::class,
    CategorySeeder::class,
    ProductSeeder::class,
    ContentSeeder::class,
    GallerySeeder::class,
    NewsSeeder::class,
    LocationSeeder::class,
];

/** @param class-string $class */
function shortName(string $class): string
{
    $parts = explode('\\', $class);

    return (string) end($parts);
}

/** @param class-string<Seeder>[] $order */
function cmdList(array $order): int
{
    foreach ($order as $index => $class) {
        printf("  %2d. %s\n", $index + 1, shortName($class));
    }

    return 0;
}

/**
 * Refuse to seed a schema that is behind the migration files. The alternative
 * is an "unknown column" from three layers down.
 */
function assertSchemaCurrent(PDO $db, string $basePath): void
{
    $files = glob($basePath . '/database/migrations/*.sql') ?: [];

    try {
        $statement = $db->query('SELECT filename FROM migrations');
    } catch (PDOException) {
        fwrite(STDERR, "No migrations table. Run: php bin/migrate.php up\n");
        exit(1);
    }

    /** @var string[] $applied */
    $applied = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
    $pending = array_diff(array_map('basename', $files), $applied);

    if ($pending !== []) {
        fwrite(STDERR, 'Pending migrations: ' . implode(', ', $pending) . "\n");
        fwrite(STDERR, "Run: php bin/migrate.php up\n");
        exit(1);
    }
}

/**
 * @param class-string<Seeder>[] $order
 * @param string[]               $only
 */
function cmdRun(PDO $db, string $basePath, array $order, array $only): int
{
    assertSchemaCurrent($db, $basePath);

    if ($only !== []) {
        $order = array_values(array_filter(
            $order,
            static fn (string $class): bool => in_array(shortName($class), $only, true),
        ));

        if (count($order) !== count($only)) {
            fwrite(STDERR, 'Unknown seeder in --only. Run --list to see the names.' . "\n");

            return 1;
        }
    }

    /** @var Seeder[] $seeders */
    $seeders = [];
    /** @var array<string,array{inserted:int,updated:int,unchanged:int}> $totals */
    $totals = [];

    $db->beginTransaction();

    try {
        foreach ($order as $class) {
            $seeder = new $class($db);
            $seeder->run();
            $seeders[] = $seeder;

            printf("  ran      %s\n", $seeder->label());

            foreach ($seeder->stats() as $table => $counts) {
                $totals[$table] ??= ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
                $totals[$table]['inserted'] += $counts['inserted'];
                $totals[$table]['updated'] += $counts['updated'];
                $totals[$table]['unchanged'] += $counts['unchanged'];
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "\n  FAILED  {$e->getMessage()}\n");
        fwrite(STDERR, "  Nothing was written; the transaction was rolled back.\n");

        return 1;
    }

    report($db, $totals);
    announceInvite($seeders);

    return 0;
}

/** @param array<string,array{inserted:int,updated:int,unchanged:int}> $totals */
function report(PDO $db, array $totals): void
{
    ksort($totals);

    printf("\n  %-22s %8s %8s %10s %8s\n", 'table', 'insert', 'update', 'unchanged', 'rows');
    printf("  %s\n", str_repeat('-', 60));

    $changed = 0;

    foreach ($totals as $table => $counts) {
        $statement = $db->query('SELECT COUNT(*) FROM `' . $table . '`');
        $rows = $statement === false ? '?' : (string) $statement->fetchColumn();

        printf(
            "  %-22s %8d %8d %10d %8s\n",
            $table,
            $counts['inserted'],
            $counts['updated'],
            $counts['unchanged'],
            $rows,
        );

        $changed += $counts['inserted'] + $counts['updated'];
    }

    printf("\n  %s\n", $changed === 0 ? 'nothing to do — already seeded' : "{$changed} row(s) written");
}

/** @param Seeder[] $seeders */
function announceInvite(array $seeders): void
{
    foreach ($seeders as $seeder) {
        if (!$seeder instanceof AdminSeeder) {
            continue;
        }

        $invite = $seeder->pendingInvite();

        if ($invite === null || $invite['token'] === null) {
            continue;
        }

        // Printed rather than emailed: SMTP is not configured yet (RTPP-17).
        // The account has no password and cannot be logged into until this
        // token is redeemed, so it is the only way in.
        printf("\n  Super Admin invite is unclaimed — this account has no password.\n");
        printf("    email  %s\n", $invite['email']);
        printf("    token  %s\n", $invite['token']);
    }
}

$only = [];
$command = 'run';

foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--list') {
        $command = 'list';
    } elseif (str_starts_with($argument, '--only=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($argument, 7)))));
    } else {
        fwrite(STDERR, "usage: seed.php [--only=SeederName,...] [--list]\n");
        exit(2);
    }
}

exit($command === 'list' ? cmdList($order) : cmdRun(Database::connection(), $basePath, $order, $only));
