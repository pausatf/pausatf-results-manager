<?php
/**
 * Lossless archive context validation before any persistence.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;
use JsonException;

final class ArchiveBundle {
    public const MAX_JSON_BYTES = 8388608;
    public const MAX_SOURCE_BYTES = 33554432;
    public const MAX_RECORDS = 5000;
    public const ENTITIES = [
        'rulesets', 'competitions', 'divisions', 'rounds', 'performances',
        'attempts', 'rankings', 'teams', 'scores', 'result_releases', 'record_claims',
    ];
    public const TABLES = [
        'rulesets' => 'archive_rulesets', 'competitions' => 'archive_competitions',
        'divisions' => 'archive_divisions', 'rounds' => 'archive_rounds',
        'performances' => 'archive_performances', 'attempts' => 'archive_attempts',
        'rankings' => 'archive_rankings', 'teams' => 'archive_teams',
        'scores' => 'archive_scores', 'result_releases' => 'archive_result_releases',
        'record_claims' => 'archive_record_claims',
    ];

    /** @return array<string,mixed> */
    public static function parse(string $json, string $source_bytes): array {
        self::check(strlen($json) > 0 && strlen($json) <= self::MAX_JSON_BYTES, 'Bundle size is invalid.');
        self::check(strlen($source_bytes) > 0 && strlen($source_bytes) <= self::MAX_SOURCE_BYTES, 'Source size is invalid.');
        try {
            $bundle = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Bundle must be valid UTF-8 JSON.');
        }
        self::check(is_array($bundle) && !array_is_list($bundle), 'Bundle must be an object.');
        self::check(($bundle['schema_version'] ?? null) === 1, 'Unsupported bundle schema.');
        self::identifier($bundle['competition_key'] ?? null);
        $source = $bundle['source'] ?? null;
        self::check(is_array($source), 'Source metadata is required.');
        self::check(($source['sha256'] ?? null) === hash('sha256', $source_bytes), 'Source checksum does not match.');
        self::check(($source['bytes'] ?? null) === strlen($source_bytes), 'Source byte count does not match.');
        self::text($source, 'path', 2048, false);
        self::check(!str_starts_with($source['path'], '/') && !str_contains($source['path'], '\\') &&
            !in_array('..', explode('/', $source['path']), true), 'Source path must be a logical relative archive path.');
        self::text($source, 'encoding', 64);
        self::check(in_array($source['format'] ?? null, ['html', 'text', 'pdf', 'ole', 'zip', 'elf'], true), 'Unsupported source format.');
        if (isset($source['url'])) {
            self::text($source, 'url', 2048, false);
            self::check(filter_var($source['url'], FILTER_VALIDATE_URL) !== false &&
                in_array(parse_url($source['url'], PHP_URL_SCHEME), ['http', 'https'], true) &&
                parse_url($source['url'], PHP_URL_USER) === null && parse_url($source['url'], PHP_URL_PASS) === null,
                'Source URL must be HTTP(S) without credentials.');
        }
        if (isset($bundle['supersedes_sha256'])) {
            self::check(is_string($bundle['supersedes_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $bundle['supersedes_sha256']) === 1, 'Invalid previous bundle checksum.');
        }
        $entities = $bundle['entities'] ?? null;
        self::check(is_array($entities), 'Entity collections are required.');
        self::check(array_diff(array_keys($entities), self::ENTITIES) === [], 'Unknown entity collection.');
        $keys = [];
        $count = 0;
        foreach (self::ENTITIES as $kind) {
            self::check(isset($entities[$kind]) && is_array($entities[$kind]) && array_is_list($entities[$kind]), 'Each entity collection must be a list.');
            $keys[$kind] = [];
            foreach ($entities[$kind] as $record) {
                self::check(is_array($record) && !array_is_list($record), 'Records must be objects.');
                self::identifier($record['key'] ?? null);
                self::check(!isset($keys[$kind][$record['key']]), 'Duplicate entity key.');
                $keys[$kind][$record['key']] = true;
                self::locator($record['source_locator'] ?? null, strlen($source_bytes));
                self::check(++$count <= self::MAX_RECORDS, 'Bundle contains too many records.');
                self::record($kind, $record);
            }
        }
        $binary = self::binary_format($source_bytes);
        self::check($count > 0 || $binary !== null, 'Text bundle contains no records.');
        self::check($binary === null || $binary === $source['format'], 'Source signature contradicts its declared format.');
        if (in_array($source['format'], ['pdf', 'ole', 'zip', 'elf'], true)) {
            self::check($binary === $source['format'], 'Declared binary format requires its actual signature.');
        }
        if ($binary !== null) {
            foreach (array_diff(self::ENTITIES, ['rulesets', 'competitions', 'result_releases']) as $kind) {
                self::check($entities[$kind] === [], 'Binary sources require a separately verified conversion before structured extraction.');
            }
        }
        $round_by_key = array_column($entities['rounds'], null, 'key');
        $performance_by_key = array_column($entities['performances'], null, 'key');
        foreach (self::ENTITIES as $kind) {
            foreach ($entities[$kind] as $record) {
                $references = match ($kind) {
                    'rounds' => ['division_key' => 'divisions', 'competition_key' => 'competitions'],
                    'performances' => ['division_key' => 'divisions', 'round_key' => 'rounds', 'competition_key' => 'competitions'],
                    'attempts', 'rankings', 'record_claims' => ['performance_key' => 'performances'],
                    'teams' => ['division_key' => 'divisions', 'competition_key' => 'competitions'],
                    'scores' => ['team_key' => 'teams', 'performance_key' => 'performances'],
                    default => [],
                };
                $references['ruleset_key'] = 'rulesets';
                foreach ($references as $field => $target) {
                    if (isset($record[$field])) {
                        self::check(is_string($record[$field]) && isset($keys[$target][$record[$field]]), 'Entity reference is missing or belongs to another bundle.');
                    }
                }
                if (count($entities['competitions']) > 1 && in_array($kind, ['rounds', 'performances', 'teams'], true)) {
                    self::check(isset($record['competition_key']), 'Multi-event records require an explicit competition reference.');
                }
                if ($kind === 'performances' && isset($record['round_key'])) {
                    $round = $round_by_key[$record['round_key']];
                    if (isset($record['competition_key'], $round['competition_key'])) {
                        self::check($record['competition_key'] === $round['competition_key'], 'Performance and round belong to different competitions.');
                    }
                }
                if ($kind === 'teams') {
                    self::check(isset($record['member_keys']) && is_array($record['member_keys']) && array_is_list($record['member_keys']), 'Team members must be a list.');
                    $members = [];
                    foreach ($record['member_keys'] as $member) {
                        self::check(is_string($member) && isset($keys['performances'][$member]) && !isset($members[$member]), 'Invalid or duplicate team member.');
                        if (count($entities['competitions']) > 1) {
                            self::check($performance_by_key[$member]['competition_key'] === $record['competition_key'], 'Team member belongs to another competition.');
                        }
                        $members[$member] = true;
                    }
                    foreach (['scoring_member_keys', 'displacer_keys'] as $field) {
                        if (!isset($record[$field])) { continue; }
                        self::check(is_array($record[$field]) && array_is_list($record[$field]), 'Team role members must be a list.');
                        $seen = [];
                        foreach ($record[$field] as $member) {
                            self::check(is_string($member) && isset($members[$member]) && !isset($seen[$member]), 'Team role references a missing or duplicate member.');
                            $seen[$member] = true;
                        }
                    }
                }
            }
        }
        self::coverage($bundle['coverage'] ?? null, $keys);
        return $bundle;
    }

    public static function binary_format(string $bytes): ?string {
        return match (true) {
            str_starts_with($bytes, hex2bin('d0cf11e0a1b11ae1')) => 'ole',
            str_starts_with($bytes, '%PDF-') => 'pdf',
            str_starts_with($bytes, "PK\x03\x04") => 'zip',
            str_starts_with($bytes, "\x7fELF") => 'elf',
            default => null,
        };
    }

    public static function identifier(mixed $value): void {
        self::check(is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\z/D', $value) === 1, 'Invalid entity identifier.');
    }

    private static function record(string $kind, array $record): void {
        $text_fields = match ($kind) {
            'rulesets' => ['rule_reference', 'source_url'],
            'competitions' => ['title', 'discipline', 'surface', 'course_version', 'distance_raw', 'distance_unit', 'implement_weight', 'hurdle_specification'],
            'divisions' => ['published_label', 'sex_category_as_published', 'age_band', 'age_reference_basis', 'scope'],
            'rounds' => ['round_kind'],
            'performances' => ['timing_method', 'display_precision'],
            'attempts' => ['height', 'mark_raw', 'attempt_status_raw'],
            'rankings' => ['tie_group', 'timing_basis', 'age_grade_table_version'],
            'teams' => ['club_as_reported', 'team_label'],
            'result_releases' => ['published_at', 'status_as_reported', 'supersedes'],
            'record_claims' => ['record_type_as_printed', 'claimed_status', 'governing_ruleset'],
            default => [],
        };
        foreach ($text_fields as $field) {
            if (isset($record[$field])) {
                self::check(is_string($record[$field]) && preg_match_all('/./us', $record[$field]) <= 2048 &&
                    !str_contains($record[$field], "\0"), 'Context text must be a bounded string.');
            }
        }
        foreach (['heat', 'section', 'lane', 'attempt_order', 'bib'] as $field) {
            if (isset($record[$field])) {
                self::check((is_int($record[$field]) && $record[$field] >= 0) ||
                    (is_string($record[$field]) && strlen($record[$field]) <= 64 && !str_contains($record[$field], "\0")), 'Invalid event position.');
            }
        }
        if ($kind === 'performances') {
            self::text($record, 'athlete_name_raw', 255, false);
            foreach (['raw_mark', 'status_raw', 'qualifier_raw', 'gun_time_raw', 'net_time_raw', 'seed_mark_raw'] as $field) {
                if (isset($record[$field])) {
                    self::check(is_string($record[$field]) && preg_match_all('/./us', $record[$field]) <= 255 &&
                        !str_contains($record[$field], "\0"), 'Invalid raw result field.');
                }
            }
            self::text($record, 'unit', 255);
        }
        foreach (['wind', 'normalized_value', 'published_points', 'recomputed_points', 'event_multiplier', 'age_grade_published'] as $field) {
            if (isset($record[$field])) {
                self::check(is_string($record[$field]) && preg_match('/\A-?\d{1,15}(?:\.\d{1,9})?\z/D', $record[$field]) === 1, 'Numeric marks must be exact decimal strings.');
            }
        }
        if (in_array($kind, ['attempts', 'rankings', 'record_claims'], true)) {
            self::identifier($record['performance_key'] ?? null);
        }
        if ($kind === 'rankings') {
            self::text($record, 'scope', 96, false);
            self::text($record, 'published_place', 64, false);
        }
        if ($kind === 'scores') {
            self::check(isset($record['performance_key']) !== isset($record['team_key']), 'A score must have exactly one subject.');
        }
        $origin = $record['origin'] ?? 'published';
        self::check(in_array($origin, ['published', 'computed'], true), 'Invalid record origin.');
        self::check(!array_key_exists('recomputed_points', $record) || $origin === 'computed', 'Recomputed points must be labelled as computed.');
        if ($origin === 'computed') {
            self::check(in_array($kind, ['rankings', 'scores'], true), 'Only rankings and scores may contain derived records.');
            self::identifier($record['ruleset_key'] ?? null);
            self::text($record, 'calculation_version', 96, false);
            self::check(isset($record['inputs']) && is_array($record['inputs']) && $record['inputs'] !== [], 'Derived values require recorded inputs.');
        }
        if ($kind === 'rulesets') {
            self::text($record, 'edition', 96, false);
            self::text($record, 'authority_scope', 96, false);
            foreach (['effective_from', 'effective_until'] as $field) {
                if (isset($record[$field])) { self::date($record[$field]); }
            }
            if (isset($record['effective_from'], $record['effective_until'])) {
                self::check($record['effective_from'] <= $record['effective_until'], 'Ruleset date interval is reversed.');
            }
        }
        if ($kind === 'competitions' && isset($record['meet_date'])) { self::date($record['meet_date']); }
    }

    private static function date(mixed $value): void {
        self::check(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) === 1, 'Date must use YYYY-MM-DD.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        self::check($date !== false && $date->format('Y-m-d') === $value, 'Invalid calendar date.');
    }

    private static function locator(mixed $locator, int $bytes): void {
        self::check(is_array($locator), 'Every record requires source evidence.');
        $fields = match ($locator['kind'] ?? null) {
            'line' => ['line'], 'table_cell' => ['table', 'row', 'column'],
            'pdf_region' => ['page'], 'spreadsheet_cell' => ['sheet', 'row', 'column'],
            'byte_range' => [], default => null,
        };
        self::check($fields !== null, 'Unknown source location kind.');
        foreach ($fields as $field) {
            self::check(isset($locator[$field]) && is_int($locator[$field]) && $locator[$field] > 0, 'Source coordinates must be positive integers.');
        }
        if ($locator['kind'] === 'byte_range') {
            self::check(isset($locator['start'], $locator['end']) && is_int($locator['start']) && is_int($locator['end']) &&
                $locator['start'] >= 0 && $locator['end'] > $locator['start'] && $locator['end'] <= $bytes, 'Source byte range is invalid.');
        }
    }

    private static function coverage(mixed $coverage, array $keys): void {
        self::check(is_array($coverage) && array_is_list($coverage) && $coverage !== [] && count($coverage) <= self::MAX_RECORDS, 'Source coverage is required.');
        $covered = [];
        $coverage_keys = [];
        foreach ($coverage as $item) {
            self::check(is_array($item), 'Coverage entries must be objects.');
            self::identifier($item['key'] ?? null);
            self::check(!isset($coverage_keys[$item['key']]), 'Duplicate coverage key.');
            $coverage_keys[$item['key']] = true;
            self::check(in_array($item['disposition'] ?? null, ['record', 'excluded', 'needs_review'], true), 'Unknown coverage disposition.');
            self::check(isset($item['records']) && is_array($item['records']) && array_is_list($item['records']), 'Coverage records must be a list.');
            if ($item['disposition'] !== 'record') { self::text($item, 'reason', 2048, false); }
            self::check($item['disposition'] !== 'record' || $item['records'] !== [], 'Record coverage cannot be empty.');
            foreach ($item['records'] as $reference) {
                self::check(is_string($reference), 'Invalid coverage reference.');
                $parts = explode(':', $reference, 2);
                self::check(count($parts) === 2 && isset($keys[$parts[0]][$parts[1]]) && !isset($covered[$reference]), 'Missing or duplicate coverage reference.');
                $covered[$reference] = true;
            }
        }
        self::check(count($covered) === array_sum(array_map('count', $keys)), 'Some entity records have no source disposition.');
    }

    private static function text(array $record, string $field, int $max, bool $nullable = true): void {
        $value = $record[$field] ?? null;
        if ($nullable && $value === null) { return; }
        self::check(is_string($value) && trim($value) !== '' && preg_match_all('/./us', $value) <= $max && !str_contains($value, "\0"), 'Invalid text field: ' . $field . '.');
    }

    private static function check(bool $condition, string $message): void {
        if (!$condition) { throw new InvalidArgumentException($message); }
    }
}
