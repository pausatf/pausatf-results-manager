<?php
/**
 * Ranking System
 *
 * Generates seasonal and all-time rankings by event type
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

use PAUSATF\Results\Rules\USATFRulesEngine;
use PAUSATF\Results\Rules\USATFAgeDivisions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ranking system for athletes
 */
class RankingSystem {
    /**
     * Results table
     */
    private string $results_table;

    /**
     * Rankings table
     */
    private string $rankings_table;

    /**
     * Rules engine
     */
    private USATFRulesEngine $rules;

    public function __construct() {
        global $wpdb;
        $this->results_table = $wpdb->prefix . 'pausatf_results';
        $this->rankings_table = $wpdb->prefix . 'pausatf_rankings';
        $this->rules = new USATFRulesEngine();
    }

    /**
     * Create rankings table
     */
    public static function create_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'pausatf_rankings';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ranking_type enum('seasonal','all_time','age_graded') NOT NULL,
            season_year int DEFAULT NULL,
            event varchar(100) NOT NULL,
            venue_type varchar(20) NOT NULL DEFAULT 'outdoor',
            gender enum('M','F') NOT NULL,
            division_code varchar(20) NOT NULL,
            rank_position int NOT NULL,
            athlete_id bigint(20) unsigned DEFAULT NULL,
            athlete_name varchar(255) NOT NULL,
            best_performance decimal(15,4) NOT NULL,
            best_performance_display varchar(50) NOT NULL,
            best_result_id bigint(20) unsigned DEFAULT NULL,
            best_date date DEFAULT NULL,
            performances_count int DEFAULT 1,
            age_graded_score decimal(8,4) DEFAULT NULL,
            points decimal(10,2) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_ranking (ranking_type, season_year, event, venue_type, gender, division_code, athlete_name),
            KEY season_lookup (season_year, event, gender),
            KEY athlete_lookup (athlete_id),
            KEY rank_position (rank_position)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Generate rankings for an event
     *
     * @param string $event Event name (e.g., '5K', '10K')
     * @param string $gender M or F
     * @param string $division_code Division code
     * @param int|null $season Year for seasonal rankings
     * @param string $venue_type Venue type
     * @return int Number of athletes ranked
     */
    public function generate_rankings(
        string $event,
        string $gender,
        string $division_code,
        ?int $season = null,
        string $venue_type = 'road'
    ): int {
        global $wpdb;

        $ranking_type = $season ? 'seasonal' : 'all_time';

        // Build query to get best performances
        $sql = "SELECT
                    r.athlete_name,
                    r.athlete_id,
                    MIN(r.time_seconds) as best_time,
                    r.time_display,
                    r.id as result_id,
                    e.post_title as event_name,
                    pm.meta_value as event_date,
                    COUNT(*) as race_count
                FROM {$this->results_table} r
                INNER JOIN {$wpdb->posts} e ON r.event_id = e.ID
                LEFT JOIN {$wpdb->postmeta} pm ON e.ID = pm.post_id AND pm.meta_key = '_pausatf_event_date'
                WHERE r.time_seconds IS NOT NULL
                  AND r.time_seconds > 0
                  AND r.division LIKE %s";

        $params = ['%' . $division_code . '%'];

        // Filter by event type (from post title or meta)
        $sql .= " AND (e.post_title LIKE %s OR e.post_title LIKE %s)";
        $params[] = '%' . $event . '%';
        $params[] = '%' . str_replace('K', ' K', $event) . '%';

        // Filter by season if specified
        if ($season) {
            $sql .= " AND YEAR(pm.meta_value) = %d";
            $params[] = $season;
        }

        $sql .= " GROUP BY r.athlete_name
                  ORDER BY best_time ASC
                  LIMIT 1000";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        // Clear existing rankings for this event/division/season
        $delete_where = [
            'ranking_type' => $ranking_type,
            'event' => $event,
            'venue_type' => $venue_type,
            'gender' => $gender,
            'division_code' => $division_code,
        ];

        if ($season) {
            $delete_where['season_year'] = $season;
        }

        $wpdb->delete($this->rankings_table, $delete_where);

        // Insert new rankings
        $rank = 0;
        foreach ($results as $result) {
            $rank++;

            $wpdb->insert($this->rankings_table, [
                'ranking_type' => $ranking_type,
                'season_year' => $season,
                'event' => $event,
                'venue_type' => $venue_type,
                'gender' => $gender,
                'division_code' => $division_code,
                'rank_position' => $rank,
                'athlete_id' => $result['athlete_id'],
                'athlete_name' => $result['athlete_name'],
                'best_performance' => $result['best_time'],
                'best_performance_display' => $this->format_time($result['best_time']),
                'best_result_id' => $result['result_id'],
                'best_date' => $result['event_date'],
                'performances_count' => $result['race_count'],
            ]);
        }

        return $rank;
    }

    /**
     * Generate age-graded rankings
     *
     * @param string $event Event name
     * @param string $gender M or F
     * @param int|null $season Year for seasonal
     * @return int Number ranked
     */
    public function generate_age_graded_rankings(
        string $event,
        string $gender,
        ?int $season = null
    ): int {
        global $wpdb;

        $ranking_type = 'age_graded';

        // Get all performances with age
        $sql = "SELECT
                    r.*,
                    pm.meta_value as event_date
                FROM {$this->results_table} r
                INNER JOIN {$wpdb->posts} e ON r.event_id = e.ID
                LEFT JOIN {$wpdb->postmeta} pm ON e.ID = pm.post_id AND pm.meta_key = '_pausatf_event_date'
                WHERE r.time_seconds IS NOT NULL
                  AND r.time_seconds > 0
                  AND r.athlete_age IS NOT NULL
                  AND (e.post_title LIKE %s OR e.post_title LIKE %s)";

        $params = ['%' . $event . '%', '%' . str_replace('K', ' K', $event) . '%'];

        if ($season) {
            $sql .= " AND YEAR(pm.meta_value) = %d";
            $params[] = $season;
        }

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        // Calculate age-graded scores
        $scored_results = [];
        foreach ($results as $result) {
            $age_grade = $this->calculate_age_grade(
                $event,
                $result['time_seconds'],
                $result['athlete_age'],
                $gender
            );

            if ($age_grade) {
                $scored_results[] = array_merge($result, [
                    'age_graded_score' => $age_grade['percentage'],
                    'age_graded_time' => $age_grade['age_graded_time'],
                ]);
            }
        }

        // Sort by age-graded percentage (higher is better)
        usort($scored_results, fn($a, $b) => $b['age_graded_score'] <=> $a['age_graded_score']);

        // Keep best per athlete
        $best_by_athlete = [];
        foreach ($scored_results as $result) {
            $name = $result['athlete_name'];
            if (!isset($best_by_athlete[$name]) ||
                $result['age_graded_score'] > $best_by_athlete[$name]['age_graded_score']) {
                $best_by_athlete[$name] = $result;
            }
        }

        // Re-sort
        $best_results = array_values($best_by_athlete);
        usort($best_results, fn($a, $b) => $b['age_graded_score'] <=> $a['age_graded_score']);

        // Clear existing
        $delete_where = [
            'ranking_type' => $ranking_type,
            'event' => $event,
            'gender' => $gender,
        ];
        if ($season) {
            $delete_where['season_year'] = $season;
        }
        $wpdb->delete($this->rankings_table, $delete_where);

        // Insert ranked
        $rank = 0;
        foreach ($best_results as $result) {
            $rank++;

            $division = $this->rules->get_division_for_age($result['athlete_age'], $gender);

            $wpdb->insert($this->rankings_table, [
                'ranking_type' => $ranking_type,
                'season_year' => $season,
                'event' => $event,
                'venue_type' => 'road',
                'gender' => $gender,
                'division_code' => $division['code'] ?? 'OPEN',
                'rank_position' => $rank,
                'athlete_id' => $result['athlete_id'],
                'athlete_name' => $result['athlete_name'],
                'best_performance' => $result['time_seconds'],
                'best_performance_display' => $this->format_time($result['time_seconds']),
                'best_result_id' => $result['id'],
                'best_date' => $result['event_date'],
                'age_graded_score' => $result['age_graded_score'],
            ]);
        }

        return $rank;
    }

    /**
     * Calculate age-graded score
     * Uses WMA age-grading factors
     */
    private function calculate_age_grade(
        string $event,
        int $time_seconds,
        int $age,
        string $gender
    ): ?array {
        // WMA age-grading factors (simplified - real implementation would use full tables)
        $factors = $this->get_age_grading_factor($event, $age, $gender);

        if (!$factors) {
            return null;
        }

        $age_graded_time = $time_seconds * $factors['factor'];
        $percentage = ($factors['standard'] / $time_seconds) * 100;

        return [
            'age_graded_time' => $age_graded_time,
            'percentage' => round($percentage, 2),
            'factor' => $factors['factor'],
        ];
    }

    /**
     * Get WMA age-grading factor
     */
    private function get_age_grading_factor(string $event, int $age, string $gender): ?array {
        // Simplified factors - real implementation would load from database/file
        // These are approximate open class standards and factors

        $standards = [
            '5K' => ['M' => 780, 'F' => 900], // ~13:00 / ~15:00
            '10K' => ['M' => 1620, 'F' => 1920], // ~27:00 / ~32:00
            'Half Marathon' => ['M' => 3600, 'F' => 4320], // 1:00:00 / 1:12:00
            'Marathon' => ['M' => 7560, 'F' => 9180], // 2:06:00 / 2:33:00
        ];

        // Age factors (simplified - actual WMA tables have per-year factors)
        $age_factors = [
            'M' => [
                30 => 1.000, 35 => 0.990, 40 => 0.970, 45 => 0.940,
                50 => 0.905, 55 => 0.865, 60 => 0.820, 65 => 0.770,
                70 => 0.715, 75 => 0.655, 80 => 0.590, 85 => 0.520,
            ],
            'F' => [
                30 => 1.000, 35 => 0.992, 40 => 0.975, 45 => 0.950,
                50 => 0.918, 55 => 0.880, 60 => 0.835, 65 => 0.785,
                70 => 0.730, 75 => 0.670, 80 => 0.605, 85 => 0.535,
            ],
        ];

        if (!isset($standards[$event][$gender])) {
            return null;
        }

        // Find appropriate age bracket
        $age_bracket = 30;
        foreach (array_keys($age_factors[$gender]) as $bracket) {
            if ($age >= $bracket) {
                $age_bracket = $bracket;
            }
        }

        return [
            'standard' => $standards[$event][$gender],
            'factor' => $age_factors[$gender][$age_bracket],
        ];
    }

    /**
     * Get rankings
     *
     * @param array $filters Filter criteria
     * @param int $limit Number of results
     * @param int $offset Offset for pagination
     * @return array Rankings
     */
    public function get_rankings(array $filters, int $limit = 100, int $offset = 0): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['ranking_type'])) {
            $where[] = 'ranking_type = %s';
            $params[] = $filters['ranking_type'];
        }

        if (!empty($filters['season'])) {
            $where[] = 'season_year = %d';
            $params[] = $filters['season'];
        }

        if (!empty($filters['event'])) {
            $where[] = 'event = %s';
            $params[] = $filters['event'];
        }

        if (!empty($filters['gender'])) {
            $where[] = 'gender = %s';
            $params[] = $filters['gender'];
        }

        if (!empty($filters['division'])) {
            $where[] = 'division_code = %s';
            $params[] = $filters['division'];
        }

        $where_sql = implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$this->rankings_table}
                WHERE {$where_sql}
                ORDER BY rank_position ASC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    /**
     * Get athlete's rankings across events
     */
    public function get_athlete_rankings(int $athlete_id, ?int $season = null): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->rankings_table}
                WHERE athlete_id = %d";
        $params = [$athlete_id];

        if ($season) {
            $sql .= " AND (season_year = %d OR ranking_type = 'all_time')";
            $params[] = $season;
        }

        $sql .= " ORDER BY event, ranking_type";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    /**
     * Regenerate all rankings
     */
    public function regenerate_all(int $season = null): array {
        $events = ['5K', '10K', '15K', '10 Miles', 'Half Marathon', 'Marathon'];
        $genders = ['M', 'F'];
        $divisions = ['OPEN', 'M40', 'M50', 'M60', 'M70', 'W40', 'W50', 'W60', 'W70'];

        $results = [];

        foreach ($events as $event) {
            foreach ($genders as $gender) {
                // Age-graded rankings
                $count = $this->generate_age_graded_rankings($event, $gender, $season);
                $results[] = "Age-graded {$event} {$gender}: {$count} ranked";

                // Division rankings
                foreach ($divisions as $division) {
                    if (($gender === 'M' && strpos($division, 'W') === 0) ||
                        ($gender === 'F' && strpos($division, 'M') === 0 && $division !== 'M40')) {
                        continue;
                    }

                    $count = $this->generate_rankings($event, $gender, $division, $season);
                    if ($count > 0) {
                        $results[] = "{$event} {$gender} {$division}: {$count} ranked";
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Format time in seconds to display string
     */
    private function format_time(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
