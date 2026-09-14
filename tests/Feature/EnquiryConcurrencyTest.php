<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Rajdhani\Repositories\EnquiryRepository;
use Rajdhani\Repositories\ProductRepository;
use Rajdhani\Repositories\ReferenceCounterRepository;
use Rajdhani\Services\EnquiryService;
use Rajdhani\Support\Database;
use Rajdhani\Support\Env;

/**
 * The ticket's own DoD, taken literally: "50 concurrent submissions produce
 * 50 unique, gapless references — load-tested, not reasoned about." A
 * sequential PHPUnit test proves the transaction logic is correct but proves
 * nothing about the `SELECT … FOR UPDATE` lock actually serialising
 * concurrent writers, because a single PHP process talking to one PDO
 * connection never has two transactions open at once.
 *
 * This test forks 50 real OS processes with `pcntl_fork()`, each opening its
 * own MySQL connection, each racing to call `EnquiryService::submit()` at
 * the same moment.
 *
 * **The parent closes its own database connection before forking, and does
 * not reopen one until every child has exited.** `fork()` duplicates file
 * descriptors, not sockets — a connection open in the parent at fork time
 * is the *same* live TCP session in every child, and destructing a PDO
 * object sends a real `COM_QUIT` over that socket. A child exiting while
 * still holding a reference to the parent's connection object sends that
 * `COM_QUIT` to the server, which then closes the *shared* session — killing
 * the parent's connection too ("MySQL server has gone away", reproduced
 * while first writing this test). Never having a connection open at fork
 * time removes the shared session entirely, rather than trying to prevent
 * each child from ever destructing its inherited copy.
 *
 * Deliberately **not** a `DatabaseTestCase`: that base class wraps the whole
 * test in one transaction it rolls back at the end, which is exactly wrong
 * here — each forked child needs its own real, independently-committing
 * transaction for the lock to mean anything. This test commits real rows to
 * the real database and cleans up manually afterward instead.
 */
final class EnquiryConcurrencyTest extends TestCase
{
    private const CONCURRENT_SUBMISSIONS = 50;

    public function testFiftyConcurrentSubmissionsProduceFiftyUniqueGaplessReferences(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available — cannot fork for a genuine concurrency test.');
        }

        Env::load(TEST_ENV_PATH);

        if (!Database::isReachable()) {
            self::markTestSkipped('No database. Start one with ./bin/mysql8.sh start and apply migrations.');
        }

        $year = (int) gmdate('Y');
        $marker = 'concurrency-' . bin2hex(random_bytes(4));
        $resultDir = sys_get_temp_dir() . '/enquiry_' . $marker;
        mkdir($resultDir);

        $before = (int) ((Database::connection())->query(
            "SELECT last_seq FROM reference_counters WHERE type = 'ENQ' AND year = {$year}"
        )->fetchColumn() ?: 0);

        // No connection may be open in this process when fork() runs — see
        // the class doc on why a live connection at fork time is unsafe.
        Database::reset();

        $childPids = [];

        for ($i = 0; $i < self::CONCURRENT_SUBMISSIONS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed — cannot run the concurrency test.');
            }

            if ($pid === 0) {
                $this->runChildSubmission($i, $marker, $resultDir);
                exit(0);
            }

            $childPids[] = $pid;
        }

        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Safe to open a connection again now — every child has exited, so
        // nothing else can share this session.
        $db = Database::connection();

        try {
            $references = $this->collectReferences($resultDir);

            self::assertCount(
                self::CONCURRENT_SUBMISSIONS,
                array_unique($references),
                'Expected every concurrent submission to receive a unique reference number.',
            );

            $sequences = array_map(
                fn (string $ref): int => (int) preg_replace('/^RDFP-ENQ-\d{4}-0*/', '', $ref),
                $references,
            );
            sort($sequences);

            self::assertSame(
                range($before + 1, $before + self::CONCURRENT_SUBMISSIONS),
                $sequences,
                'Expected a contiguous run of sequence numbers with no gaps.',
            );
        } finally {
            $this->cleanUp($db, $marker, $year, $before, $resultDir);
        }
    }

    private function runChildSubmission(int $index, string $marker, string $resultDir): void
    {
        // No connection exists yet anywhere in this process tree at fork
        // time (see the class doc) — this is each child's first connect.
        $connection = Database::connection();

        $service = new EnquiryService(
            new EnquiryRepository($connection),
            new ProductRepository($connection),
            new ReferenceCounterRepository($connection),
            connection: $connection,
        );

        try {
            $result = $service->submit(null, '203.0.113.9', [
                'name'    => "Concurrency Test {$index}",
                'phone'   => '+8801700000000',
                'email'   => "{$marker}-{$index}@example.test",
                'city'    => 'Dhaka',
                'message' => 'Fired by the RTPP-29 concurrency test.',
            ]);

            file_put_contents("{$resultDir}/{$index}.ref", $result['referenceNo']);
        } catch (\Throwable $e) {
            file_put_contents("{$resultDir}/{$index}.error", $e->getMessage());
        }
    }

    /** @return list<string> */
    private function collectReferences(string $resultDir): array
    {
        $references = [];

        for ($i = 0; $i < self::CONCURRENT_SUBMISSIONS; $i++) {
            $refFile = "{$resultDir}/{$i}.ref";
            $errorFile = "{$resultDir}/{$i}.error";

            if (is_file($errorFile)) {
                self::fail("Child {$i} failed: " . file_get_contents($errorFile));
            }

            self::assertFileExists($refFile, "Child {$i} produced neither a reference nor an error.");
            $references[] = trim((string) file_get_contents($refFile));
        }

        return $references;
    }

    private function cleanUp(\PDO $db, string $marker, int $year, int $before, string $resultDir): void
    {
        $emails = [];

        for ($i = 0; $i < self::CONCURRENT_SUBMISSIONS; $i++) {
            $emails[] = "{$marker}-{$i}@example.test";

            foreach (["{$resultDir}/{$i}.ref", "{$resultDir}/{$i}.error"] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($resultDir)) {
            rmdir($resultDir);
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $statement = $db->prepare("DELETE FROM product_enquiries WHERE email IN ({$placeholders})");
        $statement->execute($emails);

        // Restores the counter to its pre-test value so a repeated run of
        // this test (or the sequential EnquiryTest suite, or a real
        // submission on this dev database) is not skewed by test noise.
        $db->prepare("UPDATE reference_counters SET last_seq = :before WHERE type = 'ENQ' AND year = :year")
            ->execute([':before' => $before, ':year' => $year]);
    }
}
