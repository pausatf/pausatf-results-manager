<?php
/**
 * Independent, revision-bound archive review gates.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;

final class ArchiveReview {
    /** @return array<string,mixed> */
    public static function validate(array $bundle, string $bundle_hash, array $report, int $producer, int $reviewer): array {
        self::require($reviewer > 0 && $producer > 0 && $producer !== $reviewer, 'A different administrator must verify the candidate.');
        self::require(($report['bundle_sha256'] ?? null) === $bundle_hash &&
            ($report['source_sha256'] ?? null) === $bundle['source']['sha256'], 'Review evidence does not match this immutable candidate.');
        self::require(in_array($report['outcome'] ?? null, ['pass', 'fail'], true), 'Review outcome must be pass or fail.');
        self::require(is_string($report['evidence'] ?? null) && trim($report['evidence']) !== '' && strlen($report['evidence']) <= 8192, 'Describe the independent source comparison.');
        self::require(isset($report['findings']) && is_array($report['findings']) && array_is_list($report['findings']) && count($report['findings']) <= 1000, 'Review findings must be a bounded list.');
        $all = [];
        foreach (ArchiveBundle::ENTITIES as $kind) {
            foreach ($bundle['entities'][$kind] as $record) { $all[] = $kind . ':' . $record['key']; }
        }
        $checked = $report['checked_records'] ?? null;
        self::require(is_array($checked) && array_is_list($checked) && count($checked) <= ArchiveBundle::MAX_RECORDS, 'Checked records must be a bounded list.');
        $seen = [];
        foreach ($checked as $key) {
            self::require(is_string($key) && in_array($key, $all, true) && !isset($seen[$key]), 'Review has missing, duplicate or foreign record references.');
            $seen[$key] = true;
        }
        $blocking = false;
        foreach ($report['findings'] as $finding) {
            self::require(is_array($finding) && in_array($finding['severity'] ?? null, ['warning', 'blocking'], true) &&
                is_string($finding['message'] ?? null) && trim($finding['message']) !== '' && strlen($finding['message']) <= 2048, 'Invalid review finding.');
            if (isset($finding['record'])) { self::require(is_string($finding['record']) && in_array($finding['record'], $all, true), 'Finding refers to a foreign record.'); }
            $blocking = $blocking || $finding['severity'] === 'blocking';
        }
        if ($report['outcome'] === 'fail') { return $report; }
        self::require(!$blocking && count($checked) === count($all), 'Blocking findings or unchecked records prevent verification.');
        foreach ($bundle['coverage'] as $coverage) {
            self::require($coverage['disposition'] !== 'needs_review', 'Unresolved source coverage prevents verification.');
        }
        $verified = $report['verified_rulesets'] ?? [];
        self::require(is_array($verified) && array_is_list($verified), 'Verified rulesets must be a list.');
        $rules = array_column($bundle['entities']['rulesets'], null, 'key');
        $verified_keys = [];
        foreach ($verified as $rule) {
            self::require(is_array($rule) && is_string($rule['key'] ?? null) && isset($rules[$rule['key']]) &&
                !isset($verified_keys[$rule['key']]) && is_string($rule['evidence'] ?? null) && trim($rule['evidence']) !== '', 'Ruleset applicability requires independent evidence.');
            $verified_keys[$rule['key']] = true;
        }
        foreach (['rankings', 'scores'] as $kind) {
            foreach ($bundle['entities'][$kind] as $record) {
                self::require(!array_key_exists('recomputed_points', $record) || ($record['origin'] ?? 'published') === 'computed', 'Recomputed points require the derived-value review gate.');
                if (($record['origin'] ?? 'published') !== 'computed') { continue; }
                $key = $record['ruleset_key'];
                self::require(isset($verified_keys[$key]), 'Derived values require a verified applicable ruleset.');
                $rule = $rules[$key];
                self::require(isset($rule['effective_from'], $rule['effective_until']), 'Derived rules require an explicit effective-date interval.');
                $competitions = $bundle['entities']['competitions'];
                self::require($competitions !== [], 'Derived values require dated competition context.');
                foreach ($competitions as $competition) {
                    self::require(isset($competition['meet_date']) && $competition['meet_date'] >= $rule['effective_from'] &&
                        $competition['meet_date'] <= $rule['effective_until'], 'Ruleset does not cover the competition date.');
                }
            }
        }
        return $report;
    }

    private static function require(bool $condition, string $message): void {
        if (!$condition) { throw new InvalidArgumentException($message); }
    }
}
