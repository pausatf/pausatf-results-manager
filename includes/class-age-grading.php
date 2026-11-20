<?php
/**
 * Age grading from a loaded factor table. The plugin ships no factors.
 *
 * WMA age-grading tables give, per event and sex, an open (world-best) standard and a
 * factor for each single year of age (1.0 at peak ages, below 1.0 otherwise). Then:
 *
 *   age-graded time = time x factor
 *   percentage      = open standard / (time x factor) x 100
 *
 * which equals age standard / time, with age standard = open standard / factor. Tables
 * are loaded from a JSON file (docs/age-grading.md) and stored with their edition, so
 * every grade can name the table it came from. Without a table nothing is graded.
 *
 * Athletes under 18 are never graded: results show minors, but RM derives no new age
 * data about them.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class AgeGrading {
    /** Option holding the imported table (not autoloaded). */
    public const OPTION = 'pausatf_results_age_grading_table';

    /** Youngest age graded. */
    public const MIN_AGE = 18;

    /** @var array{edition: string, source: string, events: array<string, array<string, array{standard_seconds: float, factors: array<int, float>}>>}|null|false false = not loaded yet */
    private static array|null|false $table = false;

    /**
     * The imported table, or null when none is loaded or the stored one is invalid.
     *
     * @return array{edition: string, source: string, events: array<string, array<string, array{standard_seconds: float, factors: array<int, float>}>>}|null
     */
    public static function table(): ?array {
        if (self::$table === false) {
            $stored = get_option(self::OPTION, null);
            try {
                self::$table = is_array($stored) ? self::validate($stored) : null;
            } catch (\InvalidArgumentException) {
                self::$table = null;
            }
        }
        return self::$table;
    }

    public static function has_factors(): bool {
        return self::table() !== null;
    }

    public static function edition(): ?string {
        return self::table()['edition'] ?? null;
    }

    public static function clear_cache(): void {
        self::$table = false;
    }

    /**
     * Check and normalize a table.
     *
     * @param array<string, mixed> $data
     * @return array{edition: string, source: string, events: array<string, array<string, array{standard_seconds: float, factors: array<int, float>}>>}
     * @throws \InvalidArgumentException When a required part is missing or out of range.
     */
    public static function validate(array $data): array {
        $edition = trim((string) ($data['edition'] ?? ''));
        $source = trim((string) ($data['source'] ?? ''));
        if ($edition === '' || $source === '') {
            throw new \InvalidArgumentException('The table needs an "edition" and a "source".');
        }
        if (!is_array($data['events'] ?? null) || $data['events'] === []) {
            throw new \InvalidArgumentException('The table has no "events".');
        }

        $events = [];
        foreach ($data['events'] as $event => $by_sex) {
            if (!is_array($by_sex)) {
                throw new \InvalidArgumentException("Event {$event} is not an object.");
            }
            foreach (['M', 'F'] as $sex) {
                if (!isset($by_sex[$sex])) {
                    continue;
                }
                $entry = $by_sex[$sex];
                $standard = (float) ($entry['standard_seconds'] ?? 0);
                if ($standard <= 0 || !is_array($entry['factors'] ?? null) || $entry['factors'] === []) {
                    throw new \InvalidArgumentException("Event {$event} {$sex} needs standard_seconds and factors.");
                }
                $factors = [];
                foreach ($entry['factors'] as $age => $factor) {
                    $age = filter_var($age, FILTER_VALIDATE_INT);
                    $factor = (float) $factor;
                    if ($age === false || $age < 0 || $age > 120 || $factor <= 0 || $factor > 1) {
                        throw new \InvalidArgumentException("Event {$event} {$sex} has a factor outside 0-1 or a bad age.");
                    }
                    $factors[$age] = $factor;
                }
                ksort($factors);
                $events[(string) $event][$sex] = ['standard_seconds' => $standard, 'factors' => $factors];
            }
            if (!isset($events[(string) $event])) {
                throw new \InvalidArgumentException("Event {$event} has neither M nor F.");
            }
        }

        return ['edition' => $edition, 'source' => $source, 'events' => $events];
    }

    /**
     * Load a JSON table file and store it.
     *
     * @return array{edition: string, events: int}
     * @throws \InvalidArgumentException When the file cannot be read or is not a valid table.
     */
    public static function import_file(string $path): array {
        if (!is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read {$path}.");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException("{$path} is not a JSON object.");
        }
        $table = self::validate($data);
        update_option(self::OPTION, $table, false);
        self::clear_cache();
        // RankingSystem adds its age_grade_edition column on this action, before any graded row exists.
        do_action('pausatf_results_age_grading_imported', $table['edition']);
        return ['edition' => $table['edition'], 'events' => count($table['events'])];
    }

    /**
     * Grade one performance. Null when there is no table, the athlete is under MIN_AGE,
     * the sex is not M or F, or the table has no factor for that event, sex and age.
     * Ages are not interpolated: the tables give one factor per year of age.
     *
     * @param array<string, mixed>|null $table Defaults to the imported table.
     * @return array{percentage: float, age_graded_time: float, factor: float, standard_seconds: float, edition: string}|null
     */
    public static function grade(string $event, float $time_seconds, int $age, string $sex, ?array $table = null): ?array {
        $table ??= self::table();
        if ($table === null || $time_seconds <= 0 || $age < self::MIN_AGE || !in_array($sex, ['M', 'F'], true)) {
            return null;
        }
        $entry = $table['events'][$event][$sex] ?? null;
        $factor = $entry['factors'][$age] ?? null;
        if ($entry === null || $factor === null) {
            return null;
        }
        $graded = $time_seconds * $factor;
        return [
            'percentage' => round($entry['standard_seconds'] / $graded * 100, 2),
            'age_graded_time' => $graded,
            'factor' => $factor,
            'standard_seconds' => $entry['standard_seconds'],
            'edition' => $table['edition'],
        ];
    }

    /**
     * Age-graded rankings need the age_graded_rankings feature (off by default) and a
     * loaded table.
     */
    public static function rankings_enabled(): bool {
        return class_exists(FeatureManager::class) && FeatureManager::is_enabled('age_graded_rankings') && self::has_factors();
    }

    /**
     * WHERE clause for reading the rankings table. Age-graded rows show only while enabled
     * and only from the loaded edition, so rows from another table, or from the placeholder
     * factors earlier releases used, are never published.
     *
     * @return array{0: string, 1: list<string>} SQL fragment and its prepare() parameters.
     */
    public static function rankings_where(): array {
        if (self::rankings_enabled()) {
            return ["(ranking_type <> 'age_graded' OR age_grade_edition = %s)", [(string) self::edition()]];
        }
        return ["ranking_type <> 'age_graded'", []];
    }

    /** `wp pausatf-results age-grading import <file>` and `... status`. */
    public static function register_cli(): void {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('\WP_CLI')) {
            return;
        }
        \WP_CLI::add_command('pausatf-results age-grading import', static function (array $args): void {
            try {
                $summary = self::import_file((string) ($args[0] ?? ''));
                \WP_CLI::success(sprintf('Loaded %s (%d events).', $summary['edition'], $summary['events']));
            } catch (\InvalidArgumentException $e) {
                \WP_CLI::error($e->getMessage());
            }
        });
        \WP_CLI::add_command('pausatf-results age-grading status', static function (): void {
            \WP_CLI::log(self::has_factors() ? 'Loaded: ' . self::edition() : 'No age-grading table loaded.');
        });
    }
}
