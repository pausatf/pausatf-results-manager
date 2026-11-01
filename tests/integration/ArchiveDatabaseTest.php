<?php
/**
 * Archive persistence contracts against an explicitly configured local MySQL.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests\Integration;

use mysqli;
use mysqli_driver;
use PAUSATF\Results\Archive\ArchiveDatabase;
use PAUSATF\Results\Archive\ArchiveSchema;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-database.php';
require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-schema.php';

final class ArchiveDatabaseTest extends TestCase {
    private const TABLES = [
        'archive_rulesets', 'archive_competitions', 'archive_divisions', 'archive_rounds',
        'archive_performances', 'archive_attempts', 'archive_rankings', 'archive_teams',
        'archive_scores', 'archive_result_releases', 'archive_record_claims',
        'archive_reviews', 'archive_releases', 'archive_sources', 'archive_schema',
    ];

    private ?mysqli $connection = null;
    private ?ArchiveDatabase $database = null;
    private ?ArchiveSchema $schema = null;
    private string $prefix = '';
    private ?int $original_report_mode = null;

    protected function setUp(): void {
        if (getenv('PAUSATF_ARCHIVE_TEST_MYSQL_HOST') === false) {
            self::markTestSkipped('Set PAUSATF_ARCHIVE_TEST_MYSQL_HOST to run native archive storage tests.');
        }
        self::assertTrue(extension_loaded('mysqli'), 'Native archive tests require mysqli.');
        $host = (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_HOST');
        self::assertContains($host, ['127.0.0.1', 'localhost', '::1'], 'Archive integration tests require a local disposable database.');
        $name = (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_DATABASE');
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_]*test[A-Za-z0-9_]*\z/', $name, 'Use an explicitly named test database.');
        $this->original_report_mode = (new mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->connection = $this->newConnection();
            $this->connection->set_charset('utf8mb4');
        } catch (Throwable) {
            mysqli_report($this->original_report_mode);
            self::fail('Unable to connect to the explicitly configured archive test database.');
        }
        self::assertStringStartsWith('8.4.', (string) $this->scalar('SELECT VERSION()'), 'Run these schema contracts against MySQL 8.4.');
        $this->prefix = 'arc_' . bin2hex(random_bytes(6)) . '_';
        $this->database = new ArchiveDatabase($this->connection, $this->prefix);
        $this->schema = new ArchiveSchema($this->database);
    }

    protected function tearDown(): void {
        try {
            if ($this->connection !== null) {
                try {
                    $this->connection->query('SELECT 1');
                } catch (Throwable) {
                    // Failure-injection tests close only their own connection.
                    $this->connection->close();
                    $this->connection = $this->newConnection();
                    $this->connection->set_charset('utf8mb4');
                }
                $this->connection->rollback();
                $this->connection->autocommit(true);
                $this->connection->query('SET SESSION TRANSACTION READ WRITE');
                if ($this->database !== null) {
                    $this->connection->query('SET SESSION FOREIGN_KEY_CHECKS = 0');
                    try {
                        foreach (self::TABLES as $suffix) {
                            $table = $this->database->table($suffix);
                            self::assertStringStartsWith($this->prefix . 'pausatf_', $table);
                            $this->connection->query("DROP TEMPORARY TABLE IF EXISTS {$table}");
                            $this->connection->query("DROP TABLE IF EXISTS {$table}");
                        }
                    } finally {
                        $this->connection->query('SET SESSION FOREIGN_KEY_CHECKS = 1');
                    }
                }
                $this->connection->close();
            }
        } finally {
            if ($this->original_report_mode !== null) {
                mysqli_report($this->original_report_mode);
            }
        }
    }

    public function testFreshInstallEstablishesNativeSchemaAndVersion(): void {
        self::assertFalse($this->schema->is_installed());
        $this->schema->install();
        self::assertTrue($this->schema->is_installed());
        self::assertSame('1', (string) $this->scalar('SELECT version FROM ' . $this->database->table('archive_schema') . ' WHERE id = 1'));
        foreach (self::TABLES as $suffix) {
            $table = $this->database->table($suffix);
            $rows = $this->nativeRows('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            self::assertCount(1, $rows, $table);
            self::assertSame('InnoDB', $rows[0]['ENGINE']);
            self::assertSame('utf8mb4_bin', $rows[0]['TABLE_COLLATION']);
        }
        foreach (array_slice(self::TABLES, 0, 11) as $suffix) {
            $table = $this->database->table($suffix);
            self::assertSame('json', $this->nativeRows('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'payload'])[0]['DATA_TYPE']);
            $indexes = $this->nativeRows('SELECT NON_UNIQUE, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, 'release_record_key']);
            self::assertSame(['release_id', 'record_key'], array_column($indexes, 'COLUMN_NAME'));
            self::assertSame([null, null], array_column($indexes, 'SUB_PART'));
            self::assertSame([0, 0], array_map('intval', array_column($indexes, 'NON_UNIQUE')));
        }
    }

    public function testReinstallPreservesExistingRecords(): void {
        $this->schema->install();
        $id = $this->insertSource('reinstall');
        $before = $this->nativeRows('SELECT * FROM ' . $this->database->table('archive_sources'));
        $this->schema->install();
        self::assertTrue($this->schema->is_installed());
        self::assertSame($before, $this->nativeRows('SELECT * FROM ' . $this->database->table('archive_sources')));
        self::assertSame($id, (int) $before[0]['id']);
    }

    public function testWrongExistingTableIsPreservedWithoutVersionMarker(): void {
        $table = $this->database->table('archive_sources');
        $this->connection->query("CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sentinel VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        $this->connection->query("INSERT INTO {$table} (sentinel) VALUES ('preserve me')");
        $before = $this->nativeRows("SHOW CREATE TABLE {$table}");
        $failed = false;
        try {
            $this->schema->install();
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'An incompatible existing table must not be reported installed.');
        self::assertSame($before, $this->nativeRows("SHOW CREATE TABLE {$table}"));
        self::assertSame('preserve me', $this->scalar("SELECT sentinel FROM {$table}"));
        $marker = $this->database->table('archive_schema');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$marker}"));
        self::assertFalse($this->schema->is_installed());
    }

    public function testTemporaryTableShadowIsRejected(): void {
        $this->schema->install();
        $this->insertSource('base table');
        $table = $this->database->table('archive_sources');
        $this->connection->query("CREATE TEMPORARY TABLE {$table} (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        self::assertFalse($this->schema->is_installed());
        $failed = false;
        try {
            $this->schema->install();
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
        $this->connection->query("DROP TEMPORARY TABLE {$table}");
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
        self::assertTrue($this->schema->is_installed());
    }

    public function testFutureVersionIsNotDowngraded(): void {
        $this->schema->install();
        $source = $this->insertSource('future schema');
        $marker = $this->database->table('archive_schema');
        $this->connection->query("UPDATE {$marker} SET version = 2 WHERE id = 1");
        self::assertFalse($this->schema->is_installed());
        $failed = false;
        try {
            $this->schema->install();
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame(2, (int) $this->scalar("SELECT version FROM {$marker} WHERE id = 1"));
        self::assertSame($source, (int) $this->scalar('SELECT id FROM ' . $this->database->table('archive_sources')));
    }

    public function testPreparedStoragePreservesUnicodeCaseAndNull(): void {
        $this->schema->install();
        $source = $this->insertSource('unicode');
        $releases = $this->database->table('archive_releases');
        $bundle = '{"printed":"Élodie 漢字 🏃","codes":["Q","q"],"wind":null}';
        $this->database->execute("INSERT INTO {$releases} (source_id,competition_key,source_path,source_url,bundle_sha256,bundle_json,producer_user_id) VALUES (?,?,?,?,?,?,?)", [$source, 'meet-case', 'fixture.html', null, hash('sha256', $bundle), $bundle, 1]);
        $release = $this->database->insert_id();
        $table = $this->database->table('archive_performances');
        $payload = json_encode(['name' => "Élodie 漢字 🏃 '); --", 'qualified' => 'Q', 'qualified_by_time' => 'q', 'wind' => null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->database->execute("INSERT INTO {$table} (release_id,record_key,payload,ordinal) VALUES (?,?,?,?)", [$release, 'result-Q', $payload, 0]);
        $this->database->execute("INSERT INTO {$table} (release_id,record_key,payload,ordinal) VALUES (?,?,?,?)", [$release, 'result-q', '{}', 1]);
        $rows = $this->database->rows("SELECT record_key,payload FROM {$table} ORDER BY ordinal");
        self::assertCount(2, $rows);
        self::assertSame(['result-Q', 'result-q'], array_column($rows, 'record_key'));
        $restored = json_decode($rows[0]['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("Élodie 漢字 🏃 '); --", $restored['name']);
        self::assertSame('Q', $restored['qualified']);
        self::assertSame('q', $restored['qualified_by_time']);
        self::assertArrayHasKey('wind', $restored);
        self::assertNull($restored['wind']);
        $stored = $this->nativeRows("SELECT source_url,bundle_json FROM {$releases} WHERE id = ?", [$release])[0];
        self::assertNull($stored['source_url']);
        self::assertSame($bundle, $stored['bundle_json']);
    }

    public function testLaterInsertFailureRollsBackEarlierWrites(): void {
        $this->schema->install();
        $this->insertSource('existing');
        $table = $this->database->table('archive_sources');
        $failed = false;
        try {
            $this->database->transaction(function (): void {
                $this->insertSource('rollback this');
                $this->insertSource('existing');
            });
        } catch (RuntimeException $error) {
            $failed = true;
            self::assertStringNotContainsString(hash('sha256', 'existing'), $error->getMessage());
            self::assertStringNotContainsString($table, $error->getMessage());
        }
        self::assertTrue($failed);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
        self::assertSame(hash('sha256', 'existing'), $this->scalar("SELECT sha256 FROM {$table}"));
    }

    public function testCallerOwnedTransactionAndSqlModeRemainIntact(): void {
        $this->schema->install();
        $this->connection->query("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $original_mode = $this->scalar('SELECT @@session.sql_mode');
        $this->connection->begin_transaction();
        $id = $this->insertSource('caller owned');
        $called = false;
        $failed = false;
        try {
            $this->database->transaction(function () use (&$called): void {
                $called = true;
            });
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFalse($called);
        self::assertSame($original_mode, $this->scalar('SELECT @@session.sql_mode'));
        $table = $this->database->table('archive_sources');
        self::assertSame($id, (int) $this->scalar("SELECT id FROM {$table}"));
        $observer = $this->newConnection();
        try {
            self::assertSame(0, (int) $observer->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0], 'The caller transaction must not be committed.');
        } finally {
            $observer->close();
        }
        $this->connection->rollback();
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
    }

    public function testInstallationDoesNotCommitCallerOwnedTransaction(): void {
        $this->schema->install();
        $this->connection->begin_transaction();
        $this->insertSource('caller install');
        $failed = false;
        try {
            $this->schema->install();
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        $this->connection->rollback();
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_sources')));
    }

    public function testOwnedTransactionRestoresSqlModeOnSuccess(): void {
        $this->schema->install();
        $this->connection->query("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $before = $this->scalar('SELECT @@session.sql_mode');
        $returned = $this->database->transaction(function (): string {
            $inside = explode(',', (string) $this->scalar('SELECT @@session.sql_mode'));
            self::assertContains('STRICT_ALL_TABLES', $inside);
            $this->insertSource('committed');
            return 'transaction result';
        });
        self::assertSame('transaction result', $returned);
        self::assertSame($before, $this->scalar('SELECT @@session.sql_mode'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_sources')));
    }

    public function testOwnedTransactionEnforcesStrictnessAndRestoresModeOnFailure(): void {
        $this->schema->install();
        $this->connection->query("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $before = $this->scalar('SELECT @@session.sql_mode');
        $table = $this->database->table('archive_sources');
        $failed = false;
        try {
            $this->database->transaction(function () use ($table): void {
                $this->database->execute("INSERT INTO {$table} (sha256,byte_length,format) VALUES (?,?,?)", [hash('sha256', 'oversized'), 1, str_repeat('x', 33)]);
            });
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'Strict mode must reject oversized data instead of truncating it.');
        self::assertSame($before, $this->scalar('SELECT @@session.sql_mode'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
    }

    public function testReadOnlySessionIsRejectedBeforeCallback(): void {
        $this->schema->install();
        $this->connection->query('SET SESSION TRANSACTION READ ONLY');
        $called = false;
        $failed = false;
        try {
            $this->database->transaction(function () use (&$called): void {
                $called = true;
            });
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFalse($called);
        self::assertSame(1, (int) $this->scalar('SELECT @@session.transaction_read_only'));
    }

    public function testDisabledAutocommitPreservesCallerWork(): void {
        $this->schema->install();
        $this->connection->autocommit(false);
        $this->insertSource('autocommit caller');
        $called = false;
        $failed = false;
        try {
            $this->database->transaction(function () use (&$called): void {
                $called = true;
            });
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFalse($called);
        self::assertSame(0, (int) $this->scalar('SELECT @@session.autocommit'));
        $table = $this->database->table('archive_sources');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
        $observer = $this->newConnection();
        try {
            self::assertSame(0, (int) $observer->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0]);
        } finally {
            $observer->close();
        }
        $this->connection->rollback();
        $this->connection->autocommit(true);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$table}"));
    }

    public function testLostConnectionBeforeCommitCannotReportSuccessOrReconnect(): void {
        $this->schema->install();
        $thread = $this->connection->thread_id;
        $observer = $this->newConnection();
        $failed = false;
        try {
            $this->database->transaction(function () use ($observer, $thread): void {
                $this->insertSource('uncommitted connection loss');
                $observer->query('KILL CONNECTION ' . (int) $thread);
            });
        } catch (RuntimeException $error) {
            $failed = true;
            self::assertSame('Archive transaction commit failed.', $error->getMessage());
        }
        self::assertTrue($failed, 'An unconfirmed commit must not become a successful outcome.');
        $table = $this->database->table('archive_sources');
        try {
            self::assertSame(0, (int) $observer->query("SELECT COUNT(*) FROM {$table}")->fetch_row()[0]);
            $read_failed = false;
            try {
                $this->database->rows("SELECT COUNT(*) FROM {$table}");
            } catch (RuntimeException) {
                $read_failed = true;
            }
            self::assertTrue($read_failed, 'ArchiveDatabase must not reconnect after connection loss.');
        } finally {
            $observer->close();
        }
    }

    public function testMissingUniqueIndexInvalidatesInstalledSchema(): void {
        $this->schema->install();
        $table = $this->database->table('archive_sources');
        $this->connection->query("ALTER TABLE {$table} DROP INDEX sha256");
        self::assertFalse($this->schema->is_installed());
        $failed = false;
        try {
            $this->schema->install();
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'Reinstallation must not accept incomplete identity constraints.');
        self::assertFalse($this->schema->is_installed());
    }

    private function insertSource(string $identity): int {
        $table = $this->database->table('archive_sources');
        $this->database->execute("INSERT INTO {$table} (sha256,byte_length,format,encoding) VALUES (?,?,?,?)", [hash('sha256', $identity), 42, 'html', null]);
        return $this->database->insert_id();
    }

    private function newConnection(): mysqli {
        return new mysqli(
            (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_HOST'),
            (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_USER'),
            (string) (getenv('PAUSATF_ARCHIVE_TEST_MYSQL_PASSWORD') ?: ''),
            (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_DATABASE'),
            (int) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_PORT'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function nativeRows(string $sql, array $parameters = []): array {
        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute($parameters);
            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private function scalar(string $sql): mixed {
        return $this->connection->query($sql)->fetch_row()[0];
    }
}
