<?php
/**
 * Connection-bound archive storage.
 *
 * @package PAUSATF\Results\Archive
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use mysqli;
use RuntimeException;
use Throwable;

/** Keeps archive mutations on the connection that passed their safety checks. */
final class ArchiveDatabase {
    private const SUFFIXES = [
        'archive_schema', 'archive_sources', 'archive_releases', 'archive_reviews',
        'archive_rulesets', 'archive_competitions', 'archive_divisions', 'archive_rounds',
        'archive_performances', 'archive_attempts', 'archive_rankings', 'archive_teams',
        'archive_scores', 'archive_result_releases', 'archive_record_claims',
    ];

    public function __construct(private mysqli $connection, private string $prefix) {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) {
            throw new RuntimeException('Invalid archive table prefix.');
        }
        foreach (self::SUFFIXES as $suffix) {
            if (strlen($prefix . 'pausatf_' . $suffix) > 64) {
                throw new RuntimeException('Archive table prefix is too long.');
            }
        }
    }

    public function table(string $suffix): string {
        if (!in_array($suffix, self::SUFFIXES, true)) {
            throw new RuntimeException('Invalid archive table.');
        }
        return $this->prefix . 'pausatf_' . $suffix;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $sql, array $parameters = []): array {
        $statement = null;
        try {
            $statement = @$this->connection->prepare($sql);
            if ($statement === false || !@$statement->execute($parameters)) {
                throw new RuntimeException('Archive database read failed.');
            }
            $result = @$statement->get_result();
            if ($result === false) {
                throw new RuntimeException('Archive database read failed.');
            }
            try {
                return $result->fetch_all(MYSQLI_ASSOC);
            } finally {
                $result->free();
            }
        } catch (Throwable) {
            throw new RuntimeException('Archive database read failed.');
        } finally {
            if ($statement !== null && $statement !== false) {
                $statement->close();
            }
        }
    }

    public function execute(string $sql, array $parameters = []): int {
        $statement = null;
        try {
            $statement = @$this->connection->prepare($sql);
            if ($statement === false || !@$statement->execute($parameters)) {
                throw new RuntimeException('Archive database write failed.');
            }
            return $statement->affected_rows;
        } catch (Throwable) {
            throw new RuntimeException('Archive database write failed.');
        } finally {
            if ($statement !== null && $statement !== false) {
                $statement->close();
            }
        }
    }

    public function insert_id(): int {
        $id = $this->connection->insert_id;
        if (!is_int($id)) {
            throw new RuntimeException('Archive identifier exceeds the supported range.');
        }
        return $id;
    }

    /**
     * Execute an owned transaction, never committing or rolling back caller work.
     *
     * @template T
     * @param callable(): T $operation Operation on this connection.
     * @return T
     */
    public function transaction(callable $operation): mixed {
        $session = $this->rows('SELECT @@session.autocommit AS autocommit, @@session.transaction_read_only AS read_only, @@session.sql_mode AS sql_mode, @@session.character_set_client AS client_charset, @@session.character_set_connection AS connection_charset, @@session.character_set_results AS results_charset')[0] ?? null;
        if ($session === null || (int) $session['autocommit'] !== 1 || (int) $session['read_only'] !== 0) {
            throw new RuntimeException('Archive operation requires an idle writable connection.');
        }
        if ($session['client_charset'] !== 'utf8mb4' || $session['connection_charset'] !== 'utf8mb4' || $session['results_charset'] !== 'utf8mb4') {
            throw new RuntimeException('Archive operation requires an utf8mb4 connection.');
        }
        // MySQL rejects this statement inside a transaction, including one whose
        // autocommit variable still reads 1 after START TRANSACTION.
        $this->execute('SET TRANSACTION READ WRITE');
        $original_mode = (string) $session['sql_mode'];
        $modes = array_filter(explode(',', $original_mode));
        if (!in_array('STRICT_ALL_TABLES', $modes, true)) {
            $modes[] = 'STRICT_ALL_TABLES';
        }
        $owned = false;
        $failure = null;
        $value = null;
        try {
            $this->execute('SET SESSION sql_mode = ?', [implode(',', $modes)]);
            $configured = $this->rows('SELECT @@session.sql_mode AS sql_mode')[0] ?? [];
            if (!in_array('STRICT_ALL_TABLES', explode(',', (string) ($configured['sql_mode'] ?? '')), true)) {
                throw new RuntimeException('Archive strict SQL mode was not established.');
            }
            try {
                if (!@$this->connection->begin_transaction(MYSQLI_TRANS_START_READ_WRITE)) {
                    throw new RuntimeException('Archive transaction could not start.');
                }
            } catch (Throwable) {
                throw new RuntimeException('Archive transaction could not start.');
            }
            $owned = true;
            $value = $operation();
            try {
                if (!@$this->connection->commit()) {
                    throw new RuntimeException('Archive transaction commit failed.');
                }
            } catch (Throwable) {
                throw new RuntimeException('Archive transaction commit failed.');
            }
            $owned = false;
        } catch (Throwable $error) {
            $failure = $error;
            if ($owned) {
                try {
                    if (!@$this->connection->rollback()) {
                        throw new RuntimeException('Archive transaction rollback failed.');
                    }
                } catch (Throwable) {
                    // A cleanup error must not turn the original failure into success.
                }
            }
        }
        try {
            $this->execute('SET SESSION sql_mode = ?', [$original_mode]);
            $restored = $this->rows('SELECT @@session.sql_mode AS sql_mode')[0] ?? [];
            if (($restored['sql_mode'] ?? null) !== $original_mode) {
                throw new RuntimeException('Archive SQL mode restoration failed.');
            }
        } catch (Throwable $error) {
            $failure ??= $error;
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $value;
    }
}
