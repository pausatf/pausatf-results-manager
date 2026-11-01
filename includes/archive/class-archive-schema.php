<?php
/**
 * Explicit, additive archive schema installation.
 *
 * @package PAUSATF\Results\Archive
 */

declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use RuntimeException;

/** Installs only new archive tables and confirms their actual database contracts. */
final class ArchiveSchema {
    public const VERSION = 1;
    public const ENTITIES = [
        'archive_rulesets', 'archive_competitions', 'archive_divisions', 'archive_rounds',
        'archive_performances', 'archive_attempts', 'archive_rankings', 'archive_teams',
        'archive_scores', 'archive_result_releases', 'archive_record_claims',
    ];

    public function __construct(private ArchiveDatabase $database) {}

    public function install(): void {
        $flags = $this->database->rows('SELECT @@session.autocommit AS autocommit, @@session.transaction_read_only AS read_only')[0] ?? [];
        if ((int) ($flags['autocommit'] ?? 0) !== 1 || (int) ($flags['read_only'] ?? 1) !== 0) {
            throw new RuntimeException('Archive installation requires an idle writable connection.');
        }
        // DDL commits implicitly; reject an existing transaction before any DDL.
        $this->database->execute('SET TRANSACTION READ WRITE');
        $contracts = $this->contracts();
        $schema_table = $this->database->table('archive_schema');
        $existing = $this->database->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$schema_table]);
        if ($existing !== []) {
            $version = $this->database->rows("SELECT id, version FROM {$schema_table}");
            foreach ($version as $row) {
                if ((int) $row['id'] !== 1 || (int) $row['version'] > self::VERSION) {
                    throw new RuntimeException('Unsupported archive schema version.');
                }
            }
        }
        foreach ($contracts as $suffix => $contract) {
            $table = $this->database->table($suffix);
            $definitions = [];
            foreach ($contract['columns'] as $name => $column) {
                $definitions[] = $name . ' ' . $column['sql'];
            }
            foreach ($contract['indexes'] as $name => $index) {
                $columns = implode(', ', $index['columns']);
                $definitions[] = $name === 'PRIMARY' ? "PRIMARY KEY ({$columns})" : ($index['unique'] ? 'UNIQUE KEY ' : 'KEY ') . $name . " ({$columns})";
            }
            foreach ($contract['foreign_keys'] as $column => $reference) {
                $name = 'pa_archive_' . substr(hash('sha256', $table . ':' . $column), 0, 24);
                $target = $this->database->table($reference);
                $definitions[] = "CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES {$target} (id) ON DELETE RESTRICT ON UPDATE RESTRICT";
            }
            $sql = "CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
            $this->database->execute($sql);
        }
        if (!$this->contracts_match()) {
            throw new RuntimeException('Archive schema contracts were not established.');
        }
        $this->database->transaction(function () use ($schema_table): void {
            $rows = $this->database->rows("SELECT id, version FROM {$schema_table} FOR UPDATE");
            if ($rows === []) {
                $this->database->execute("INSERT INTO {$schema_table} (id, version) VALUES (1, ?)", [self::VERSION]);
            } elseif (count($rows) !== 1 || (int) $rows[0]['id'] !== 1 || (int) $rows[0]['version'] > self::VERSION) {
                throw new RuntimeException('Unsupported archive schema version.');
            } elseif ((int) $rows[0]['version'] !== self::VERSION) {
                $this->database->execute("UPDATE {$schema_table} SET version = ? WHERE id = 1", [self::VERSION]);
            }
        });
        if (!$this->is_installed()) {
            throw new RuntimeException('Archive installation verification failed.');
        }
    }

    public function is_installed(): bool {
        if (!$this->contracts_match()) {
            return false;
        }
        $table = $this->database->table('archive_schema');
        $rows = $this->database->rows("SELECT id, version FROM {$table}");
        return count($rows) === 1 && (int) $rows[0]['id'] === 1 && (int) $rows[0]['version'] === self::VERSION;
    }

    private function contracts_match(): bool {
        $schema = $this->database->rows('SELECT DATABASE() AS schema_name')[0]['schema_name'] ?? null;
        if (!is_string($schema) || $schema === '') {
            return false;
        }
        foreach ($this->contracts() as $suffix => $contract) {
            $table = $this->database->table($suffix);
            $tables = $this->database->rows('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (count($tables) !== 1 || $tables[0]['TABLE_NAME'] !== $table || $tables[0]['ENGINE'] !== 'InnoDB' || $tables[0]['TABLE_COLLATION'] !== 'utf8mb4_bin' || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE') {
                return false;
            }
            $create = $this->database->rows("SHOW CREATE TABLE {$table}")[0] ?? [];
            if (!isset($create['Create Table']) || preg_match('/\ACREATE\s+TEMPORARY\s+TABLE\b/i', (string) $create['Create Table'])) {
                return false;
            }
            $columns = $this->database->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (count($columns) !== count($contract['columns'])) {
                return false;
            }
            foreach ($columns as $column) {
                $expected = $contract['columns'][$column['COLUMN_NAME']] ?? null;
                if ($expected === null || strtolower($column['COLUMN_TYPE']) !== $expected['type'] || $column['IS_NULLABLE'] !== ($expected['nullable'] ? 'YES' : 'NO')) {
                    return false;
                }
                $extra = strtolower((string) $column['EXTRA']);
                if ($extra !== $expected['extra']) {
                    return false;
                }
                $default = $column['COLUMN_DEFAULT'];
                if ($expected['default'] === 'CURRENT_TIMESTAMP') {
                    if (strtolower(str_replace('()', '', (string) $default)) !== 'current_timestamp') {
                        return false;
                    }
                } elseif ($default !== $expected['default']) {
                    return false;
                }
                if ($expected['charset'] !== null && ($column['CHARACTER_SET_NAME'] !== $expected['charset'] || $column['COLLATION_NAME'] !== $expected['collation'])) {
                    return false;
                }
            }
            $indexes = $this->database->rows('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]);
            $actual_indexes = [];
            foreach ($indexes as $index) {
                if ($index['SUB_PART'] !== null || $index['EXPRESSION'] !== null || $index['INDEX_TYPE'] !== 'BTREE' || $index['IS_VISIBLE'] !== 'YES') {
                    return false;
                }
                $name = $index['INDEX_NAME'];
                $actual_indexes[$name]['unique'] = (int) $index['NON_UNIQUE'] === 0;
                $actual_indexes[$name]['columns'][] = $index['COLUMN_NAME'];
            }
            ksort($actual_indexes);
            $expected_indexes = $contract['indexes'];
            ksort($expected_indexes);
            if ($actual_indexes !== $expected_indexes) {
                return false;
            }
            $references = $this->database->rows('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL', [$table]);
            if (count($references) !== count($contract['foreign_keys'])) {
                return false;
            }
            foreach ($references as $reference) {
                $target = $contract['foreign_keys'][$reference['COLUMN_NAME']] ?? null;
                if ($target === null || $reference['REFERENCED_TABLE_SCHEMA'] !== $schema || $reference['REFERENCED_TABLE_NAME'] !== $this->database->table($target) || $reference['REFERENCED_COLUMN_NAME'] !== 'id' || (int) $reference['ORDINAL_POSITION'] !== 1 || $reference['UPDATE_RULE'] !== 'RESTRICT' || $reference['DELETE_RULE'] !== 'RESTRICT') {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return array<string, array{columns: array, indexes: array, foreign_keys: array}> */
    private function contracts(): array {
        $id = $this->column('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'bigint unsigned', extra: 'auto_increment');
        $foreign_id = $this->column('BIGINT UNSIGNED NOT NULL', 'bigint unsigned');
        $created = $this->column('DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', default: 'CURRENT_TIMESTAMP', extra: 'default_generated');
        $hash = $this->column('CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'char(64)', charset: 'ascii', collation: 'ascii_bin');
        $key = $this->column('VARCHAR(96) NOT NULL', 'varchar(96)', charset: 'utf8mb4', collation: 'utf8mb4_bin');
        $text = $this->column('TEXT NOT NULL', 'text', charset: 'utf8mb4', collation: 'utf8mb4_bin');
        $primary = ['PRIMARY' => ['unique' => true, 'columns' => ['id']]];
        $contracts = [
            'archive_schema' => ['columns' => [
                'id' => $this->column('TINYINT UNSIGNED NOT NULL', 'tinyint unsigned'),
                'version' => $this->column('INT UNSIGNED NOT NULL', 'int unsigned'),
            ], 'indexes' => $primary, 'foreign_keys' => []],
            'archive_sources' => ['columns' => [
                'id' => $id, 'sha256' => $hash,
                'byte_length' => $this->column('BIGINT UNSIGNED NOT NULL', 'bigint unsigned'),
                'format' => $this->column('VARCHAR(32) NOT NULL', 'varchar(32)', charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'encoding' => $this->column('VARCHAR(64) NULL', 'varchar(64)', nullable: true, charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'created_at' => $created,
            ], 'indexes' => $primary + ['sha256' => ['unique' => true, 'columns' => ['sha256']]], 'foreign_keys' => []],
            'archive_releases' => ['columns' => [
                'id' => $id, 'source_id' => $foreign_id, 'competition_key' => $key,
                'source_path' => $text,
                'source_url' => $this->column('TEXT NULL', 'text', nullable: true, charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'bundle_sha256' => $hash,
                'bundle_json' => $this->column('LONGTEXT NOT NULL', 'longtext', charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'producer_user_id' => $foreign_id,
                'state' => $this->column("VARCHAR(16) NOT NULL DEFAULT 'candidate'", 'varchar(16)', default: 'candidate', charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'supersedes_id' => $this->column('BIGINT UNSIGNED NULL', 'bigint unsigned', nullable: true),
                'created_at' => $created,
                'published_at' => $this->column('DATETIME NULL', 'datetime', nullable: true),
            ], 'indexes' => $primary + [
                'bundle_sha256' => ['unique' => true, 'columns' => ['bundle_sha256']],
                'source_id' => ['unique' => false, 'columns' => ['source_id']],
                'supersedes_id' => ['unique' => false, 'columns' => ['supersedes_id']],
            ], 'foreign_keys' => ['source_id' => 'archive_sources', 'supersedes_id' => 'archive_releases']],
            'archive_reviews' => ['columns' => [
                'id' => $id, 'release_id' => $foreign_id, 'reviewer_user_id' => $foreign_id,
                'report_json' => $this->column('LONGTEXT NOT NULL', 'longtext', charset: 'utf8mb4', collation: 'utf8mb4_bin'),
                'created_at' => $created,
            ], 'indexes' => $primary + ['release_id' => ['unique' => true, 'columns' => ['release_id']]], 'foreign_keys' => ['release_id' => 'archive_releases']],
        ];
        foreach (self::ENTITIES as $suffix) {
            $contracts[$suffix] = ['columns' => [
                'id' => $id, 'release_id' => $foreign_id, 'record_key' => $key,
                'payload' => $this->column('JSON NOT NULL', 'json'),
                'ordinal' => $this->column('INT UNSIGNED NOT NULL', 'int unsigned'),
            ], 'indexes' => $primary + ['release_record_key' => ['unique' => true, 'columns' => ['release_id', 'record_key']]], 'foreign_keys' => ['release_id' => 'archive_releases']];
        }
        return $contracts;
    }

    private function column(string $sql, string $type, bool $nullable = false, ?string $default = null, string $extra = '', ?string $charset = null, ?string $collation = null): array {
        return compact('sql', 'type', 'nullable', 'default', 'extra', 'charset', 'collation');
    }
}
