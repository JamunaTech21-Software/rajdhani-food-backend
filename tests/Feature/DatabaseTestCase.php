<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Rajdhani\Support\Database;
use Rajdhani\Support\Env;

/**
 * Base for tests that need a real database.
 *
 * They run against a real MySQL rather than a double because the things worth
 * testing here *are* database behaviour: a UNIQUE constraint on a jti, a
 * revocation that has to be visible to the next statement, a COUNT over a time
 * window. A mocked PDO would assert that the code calls the methods the code
 * calls, which proves nothing.
 *
 * **Every test runs inside a transaction that is rolled back.** Nothing a test
 * writes survives it, so the suite can run against a developer's working
 * database without destroying its contents — and no test can depend on rows a
 * previous one left behind.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);

        if (!Database::isReachable()) {
            self::markTestSkipped(
                'No database. Start one with ./bin/mysql8.sh start and apply migrations.'
            );
        }

        $this->db = Database::connection();

        if (!$this->schemaIsPresent()) {
            self::markTestSkipped('Schema not applied. Run: php bin/migrate.php up');
        }

        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function schemaIsPresent(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM admin_users LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
