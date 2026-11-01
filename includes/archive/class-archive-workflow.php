<?php
/**
 * Immutable candidate, independent review and explicit archive publication.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ArchiveWorkflow {
    /**
     * @param (\Closure(string, int): void)|null $on_transition Called after a publish or withdraw has committed
     *        and changed state ('published' | 'withdrawn', release id); never inside the transaction, never on a no-op.
     */
    public function __construct(private ArchiveDatabase $database, private ?\Closure $on_transition = null) {}

    public function install(): void {
        (new ArchiveSchema($this->database))->install();
    }

    public function is_installed(): bool {
        return (new ArchiveSchema($this->database))->is_installed();
    }

    public function stage(string $json, string $source, int $producer): int {
        if ($producer < 1) { throw new InvalidArgumentException('An authenticated producer is required.'); }
        $bundle = ArchiveBundle::parse($json, $source);
        $hash = hash('sha256', $json);
        $this->ready_for_write();
        return $this->database->transaction(function () use ($bundle, $hash, $json, $producer): int {
            $this->lock_contracts();
            $sources = $this->database->table('archive_sources');
            $releases = $this->database->table('archive_releases');
            // A single content identity serializes duplicate candidate submissions.
            $this->database->execute("INSERT INTO {$sources} (sha256, byte_length, format, encoding, created_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", [
                $bundle['source']['sha256'], $bundle['source']['bytes'], $bundle['source']['format'], $bundle['source']['encoding'] ?? null, gmdate('Y-m-d H:i:s'),
            ]);
            $source_id = $this->database->insert_id();
            $source_row = $this->database->rows("SELECT * FROM {$sources} WHERE id = ? FOR UPDATE", [$source_id])[0] ?? null;
            if ($source_row === null || (int) $source_row['byte_length'] !== $bundle['source']['bytes']) {
                throw new RuntimeException('Stored source identity does not match the artifact.');
            }
            $existing = $this->database->rows("SELECT id, bundle_json FROM {$releases} WHERE bundle_sha256 = ? FOR UPDATE", [$hash]);
            if ($existing !== []) {
                if ($existing[0]['bundle_json'] !== $json) { throw new RuntimeException('Stored artifact does not match its checksum.'); }
                return (int) $existing[0]['id'];
            }
            $previous = null;
            if (isset($bundle['supersedes_sha256'])) {
                $rows = $this->database->rows("SELECT id, competition_key FROM {$releases} WHERE bundle_sha256 = ? FOR UPDATE", [$bundle['supersedes_sha256']]);
                if ($rows === [] || $rows[0]['competition_key'] !== $bundle['competition_key']) {
                    throw new InvalidArgumentException('Previous release must belong to the same competition.');
                }
                $previous = (int) $rows[0]['id'];
            }
            $this->database->execute("INSERT INTO {$releases} (source_id, competition_key, source_path, source_url, bundle_sha256, bundle_json, producer_user_id, state, supersedes_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'candidate', ?, ?)", [
                $source_id, $bundle['competition_key'], $bundle['source']['path'], $bundle['source']['url'] ?? null,
                $hash, $json, $producer, $previous, gmdate('Y-m-d H:i:s'),
            ]);
            $release_id = $this->database->insert_id();
            // Use the original JSON objects so unknown object/array distinctions survive.
            $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            foreach (ArchiveBundle::TABLES as $kind => $suffix) {
                $table = $this->database->table($suffix);
                $pending = [];
                $expected = [];
                // Bundles staged before schema version 2 may omit the newer collections.
                foreach ($document->entities->{$kind} ?? [] as $ordinal => $record) {
                    $payload = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    $pending[] = [$release_id, $record->key, $payload, $ordinal];
                    $expected[] = ['record_key' => $record->key, 'payload' => json_decode($payload, true, 64, JSON_THROW_ON_ERROR), 'ordinal' => $ordinal];
                    if (count($pending) === 100) { $this->insert_records($table, $pending); $pending = []; }
                }
                if ($pending !== []) { $this->insert_records($table, $pending); }
                $readback = $this->database->rows("SELECT record_key, payload, ordinal FROM {$table} WHERE release_id = ? ORDER BY ordinal", [$release_id]);
                $actual = array_map(static fn(array $row): array => [
                    'record_key' => $row['record_key'], 'payload' => json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR), 'ordinal' => (int) $row['ordinal'],
                ], $readback);
                // Native JSON may reorder object keys, but must retain their values and types.
                if (!$this->equivalent($actual, $expected)) { throw new RuntimeException('Entity payload readback failed.'); }
            }
            $stored = $this->database->rows("SELECT bundle_json FROM {$releases} WHERE id = ?", [$release_id])[0] ?? [];
            if (($stored['bundle_json'] ?? null) !== $json) { throw new RuntimeException('Stored artifact readback failed.'); }
            return $release_id;
        });
    }

    public function review(int $id, string $report_json, string $source, int $reviewer): void {
        if ($id < 1 || $reviewer < 1 || strlen($report_json) > ArchiveBundle::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('Invalid review request.');
        }
        try {
            $report = json_decode($report_json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Review report must be valid UTF-8 JSON.');
        }
        if (!is_array($report) || array_is_list($report)) { throw new InvalidArgumentException('Review report must be an object.'); }
        $this->ready_for_write();
        $this->database->transaction(function () use ($id, $report, $report_json, $source, $reviewer): void {
            $this->lock_contracts();
            $release = $this->locked_release($id);
            if ($release['state'] !== 'candidate') { throw new InvalidArgumentException('Only a candidate can receive its immutable review. Submit a new corrected artifact after rejection.'); }
            $bundle = ArchiveBundle::parse($release['bundle_json'], $source);
            ArchiveReview::validate($bundle, $release['bundle_sha256'], $report, (int) $release['producer_user_id'], $reviewer);
            $reviews = $this->database->table('archive_reviews');
            $this->database->execute("INSERT INTO {$reviews} (release_id, reviewer_user_id, report_json, created_at) VALUES (?, ?, ?, ?)", [$id, $reviewer, $report_json, gmdate('Y-m-d H:i:s')]);
            $state = $report['outcome'] === 'pass' ? 'verified' : 'rejected';
            $table = $this->database->table('archive_releases');
            if ($this->database->execute("UPDATE {$table} SET state = ? WHERE id = ? AND state = 'candidate'", [$state, $id]) !== 1) {
                throw new RuntimeException('Candidate changed while its review was being saved.');
            }
        });
    }

    public function publish(int $id): void {
        $this->ready_for_write();
        $changed = false;
        $this->database->transaction(function () use ($id, &$changed): void {
            $this->lock_contracts();
            $release = $this->locked_release($id);
            if ($release['state'] === 'published') { return; }
            if ($release['state'] !== 'verified' || hash('sha256', $release['bundle_json']) !== $release['bundle_sha256']) {
                throw new InvalidArgumentException('Only the verified, unchanged artifact can be published.');
            }
            $table = $this->database->table('archive_reviews');
            $review = $this->database->rows("SELECT * FROM {$table} WHERE release_id = ? FOR UPDATE", [$id])[0] ?? null;
            if ($review === null) { throw new InvalidArgumentException('Independent verification is missing.'); }
            $bundle = json_decode($release['bundle_json'], true, 64, JSON_THROW_ON_ERROR);
            $report = json_decode($review['report_json'], true, 64, JSON_THROW_ON_ERROR);
            ArchiveReview::validate($bundle, $release['bundle_sha256'], $report, (int) $release['producer_user_id'], (int) $review['reviewer_user_id']);
            if ($report['outcome'] !== 'pass') { throw new InvalidArgumentException('Rejected artifacts cannot be published.'); }
            $table = $this->database->table('archive_releases');
            if ($this->database->execute("UPDATE {$table} SET state = 'published', published_at = ? WHERE id = ? AND state = 'verified'", [gmdate('Y-m-d H:i:s'), $id]) !== 1) {
                throw new RuntimeException('Publication was not confirmed.');
            }
            $changed = true;
        });
        if ($changed && $this->on_transition !== null) { ($this->on_transition)('published', $id); }
    }

    public function withdraw(int $id): void {
        $this->ready_for_write();
        $changed = false;
        $this->database->transaction(function () use ($id, &$changed): void {
            $this->lock_contracts();
            $row = $this->locked_release($id);
            if ($row['state'] === 'withdrawn') { return; }
            if ($row['state'] !== 'published') { throw new InvalidArgumentException('Only a published release can be withdrawn.'); }
            $table = $this->database->table('archive_releases');
            if ($this->database->execute("UPDATE {$table} SET state = 'withdrawn' WHERE id = ? AND state = 'published'", [$id]) !== 1) {
                throw new RuntimeException('Withdrawal was not confirmed.');
            }
            $changed = true;
        });
        if ($changed && $this->on_transition !== null) { ($this->on_transition)('withdrawn', $id); }
    }

    /** Private application method; callers must authorize before invoking it. */
    public function inspect(int $id): ?array {
        if ($id < 1) { throw new InvalidArgumentException('Invalid release identifier.'); }
        $table = $this->database->table('archive_releases');
        $row = $this->database->rows("SELECT * FROM {$table} WHERE id = ?", [$id])[0] ?? null;
        if ($row === null) { return null; }
        $reviews = $this->database->table('archive_reviews');
        $row['review'] = $this->database->rows("SELECT * FROM {$reviews} WHERE release_id = ?", [$id])[0] ?? null;
        return $row;
    }

    /** A version-specific public projection never includes the review or raw bundle. */
    public function published(int $id): ?array {
        if ($id < 1) { return null; }
        $table = $this->database->table('archive_releases');
        $row = $this->database->rows("SELECT id, competition_key, bundle_sha256, bundle_json, source_url, state, published_at, supersedes_id FROM {$table} WHERE id = ? AND state = 'published'", [$id])[0] ?? null;
        if ($row === null) { return null; }
        if (hash('sha256', $row['bundle_json']) !== $row['bundle_sha256']) { throw new RuntimeException('Published artifact integrity check failed.'); }
        $bundle = json_decode($row['bundle_json'], true, 64, JSON_THROW_ON_ERROR);
        return [
            'id' => (int) $row['id'], 'competition_key' => $row['competition_key'], 'published_at' => $row['published_at'],
            'supersedes_id' => $row['supersedes_id'] === null ? null : (int) $row['supersedes_id'],
            'bundle_sha256' => $row['bundle_sha256'], 'source_sha256' => $bundle['source']['sha256'],
            'source_url' => $row['source_url'], 'entities' => self::public_entities($bundle),
            'record_ratification' => 'not_determined_by_extraction_review',
        ];
    }

    /**
     * Allowlisted public fields of every collection; omitted older collections project as empty.
     *
     * @param array<string,mixed> $bundle
     * @return array<string, list<array<string,mixed>>>
     */
    public static function public_entities(array $bundle): array {
        $fields = [
            'rulesets' => ['key', 'edition', 'authority_scope', 'effective_from', 'effective_until', 'rule_reference', 'source_url'],
            'competitions' => ['key', 'title', 'meet_date', 'discipline', 'surface', 'course_version', 'distance_raw', 'distance_unit', 'implement_weight', 'hurdle_specification'],
            'divisions' => ['key', 'published_label', 'sex_category_as_published', 'age_band', 'age_reference_basis', 'scope'],
            'rounds' => ['key', 'competition_key', 'division_key', 'round_kind', 'heat', 'section', 'lane'],
            'performances' => ['key', 'competition_key', 'athlete_name_raw', 'raw_mark', 'status_raw', 'qualifier_raw', 'wind', 'normalized_value', 'unit', 'timing_method', 'gun_time_raw', 'net_time_raw', 'seed_mark_raw', 'lane', 'bib', 'display_precision', 'division_key', 'round_key', 'source_locator'],
            'attempts' => ['key', 'performance_key', 'attempt_order', 'height', 'mark_raw', 'attempt_status_raw', 'source_locator'],
            'rankings' => ['key', 'performance_key', 'scope', 'published_place', 'tie_group', 'timing_basis', 'age_grade_published', 'age_grade_table_version', 'origin', 'ruleset_key', 'calculation_version'],
            'teams' => ['key', 'competition_key', 'club_as_reported', 'team_label', 'division_key', 'member_keys', 'scoring_member_keys', 'displacer_keys'],
            'scores' => ['key', 'team_key', 'performance_key', 'published_points', 'recomputed_points', 'event_multiplier', 'origin', 'ruleset_key', 'calculation_version'],
            'result_releases' => ['key', 'published_at', 'status_as_reported', 'supersedes'],
            'record_claims' => ['key', 'performance_key', 'record_type_as_printed', 'claimed_status', 'governing_ruleset'],
            'series_standings' => ['key', 'source_locator', 'standings_kind', 'division_label_as_printed', 'series_label_as_printed', 'place_as_printed', 'name_as_printed', 'club_as_printed', 'race_points_as_printed', 'total_as_printed', 'origin'],
            'association_records' => ['key', 'source_locator', 'list_label_as_printed', 'division_as_printed', 'event_as_printed', 'holder_name_as_printed', 'club_as_printed', 'mark_as_printed', 'date_as_printed', 'date_set', 'meet_as_printed', 'record_status_as_listed', 'is_relay'],
        ];
        $entities = [];
        foreach ($fields as $kind => $allowlist) {
            $entities[$kind] = array_map(static function (array $record) use ($allowlist): array {
                $public = array_intersect_key($record, array_flip($allowlist));
                if (isset($public['source_locator'])) {
                    $coordinates = match ($public['source_locator']['kind']) {
                        'line' => ['kind', 'line'], 'byte_range' => ['kind', 'start', 'end'],
                        'table_cell' => ['kind', 'table', 'row', 'column'],
                        'pdf_region' => ['kind', 'page'],
                        'spreadsheet_cell' => ['kind', 'sheet', 'row', 'column'],
                    };
                    $public['source_locator'] = array_intersect_key($public['source_locator'], array_flip($coordinates));
                }
                if (isset($public['race_points_as_printed'])) {
                    // Keep printed race labels a JSON object even when empty or numeric-looking.
                    $public['race_points_as_printed'] = (object) $public['race_points_as_printed'];
                }
                return $public;
            }, ArchiveBundle::records($bundle, $kind));
        }
        return $entities;
    }

    /**
     * Published releases, newest first; listing metadata only (no bundle).
     *
     * @return list<array<string,mixed>>
     */
    public function published_index(int $offset = 0, int $limit = 50): array {
        if ($offset < 0 || $offset > 10000000 || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('Invalid archive page.'); }
        $table = $this->database->table('archive_releases');
        return $this->database->rows("SELECT id, competition_key, bundle_sha256, source_url, published_at FROM {$table} WHERE state = 'published' ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?", [$limit, $offset]);
    }

    public function published_count(): int {
        $table = $this->database->table('archive_releases');
        return (int) ($this->database->rows("SELECT COUNT(*) AS n FROM {$table} WHERE state = 'published'")[0]['n'] ?? 0);
    }

    public function recent(int $offset = 0, int $limit = 25): array {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) { throw new InvalidArgumentException('Invalid archive page.'); }
        $table = $this->database->table('archive_releases');
        return $this->database->rows("SELECT id, competition_key, bundle_sha256, producer_user_id, state, created_at FROM {$table} ORDER BY id DESC LIMIT ? OFFSET ?", [$limit, $offset]);
    }

    private function locked_release(int $id): array {
        if ($id < 1) { throw new InvalidArgumentException('Invalid release identifier.'); }
        $table = $this->database->table('archive_releases');
        $row = $this->database->rows("SELECT * FROM {$table} WHERE id = ? FOR UPDATE", [$id])[0] ?? null;
        if ($row === null) { throw new InvalidArgumentException('Archive release was not found.'); }
        return $row;
    }

    private function insert_records(string $table, array $rows): void {
        $values = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?)'));
        $affected = $this->database->execute("INSERT INTO {$table} (release_id, record_key, payload, ordinal) VALUES {$values}", array_merge(...$rows));
        if ($affected !== count($rows)) { throw new RuntimeException('Entity insertion was not confirmed.'); }
    }

    private function equivalent(mixed $actual, mixed $expected): bool {
        if (!is_array($actual) || !is_array($expected)) { return $actual === $expected; }
        if (count($actual) !== count($expected) || array_is_list($actual) !== array_is_list($expected)) { return false; }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !$this->equivalent($actual[$key], $value)) { return false; }
        }
        return true;
    }

    private function ready_for_write(): void {
        if (!$this->is_installed()) { throw new RuntimeException('Install or repair the archive schema before importing.'); }
    }

    private function lock_contracts(): void {
        foreach (['archive_schema', 'archive_sources', 'archive_releases', 'archive_reviews', ...ArchiveSchema::ENTITIES] as $suffix) {
            $table = $this->database->table($suffix);
            $this->database->rows("SELECT id FROM {$table} LIMIT 0");
        }
        // Metadata locks acquired above keep the checked tables stable until commit.
        $this->ready_for_write();
    }
}
