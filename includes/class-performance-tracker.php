<?php
/**
 * Performance Tracker - PRs, Age-Grading, Records
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks personal records, age-graded performances, and records
 */
class PerformanceTracker {
    /**
     * Standard event distances in meters
     */
    private const DISTANCES = [
        '5K' => 5000,
        '8K' => 8000,
        '10K' => 10000,
        '15K' => 15000,
        '10 Mile' => 16093,
        'Half Marathon' => 21097,
        'Marathon' => 42195,
        '50K' => 50000,
        '100K' => 100000,
    ];

    /**
     * Age-graded percentage from the imported factor table (AgeGrading). Null when no table
     * is loaded, the athlete is under 18, or the table has no factor for the event and age.
     *
     * @param int    $time_seconds Actual time in seconds
     * @param string $event Event name as the table names it, e.g. "5K"
     * @param int    $age Athlete's age
     * @param string $sex M or F
     */
    public function calculate_age_grade(int $time_seconds, string $event, int $age, string $sex): ?float {
        return AgeGrading::grade($event, (float) $time_seconds, $age, strtoupper($sex))['percentage'] ?? null;
    }

    /**
     * Get personal records for an athlete
     *
     * @param string   $athlete_name Athlete name
     * @param int|null $athlete_id Optional athlete post ID
     * @return array PRs by event
     */
    public function get_personal_records(string $athlete_name, ?int $athlete_id = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $where = $athlete_id ? 'r.athlete_id = %d' : 'r.athlete_name = %s';
        $param = $athlete_id ?: $athlete_name;

        // Get best time for each event type
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.event_id,
                r.time_seconds,
                r.time_display,
                r.athlete_age,
                p.post_title as event_name,
                " . EventDate::sql_expr('m') . " as event_date,
                t.name as event_type
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             " . EventDate::sql_join('r.event_id', 'm') . "
             LEFT JOIN {$wpdb->term_relationships} tr ON r.event_id = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'pausatf_event_type'
             LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE {$where}
             AND r.time_seconds IS NOT NULL
             AND r.time_seconds > 0
             ORDER BY r.time_seconds ASC",
            $param
        ), ARRAY_A);

        // Group by event type and get best
        $prs = [];
        $seen_events = [];

        foreach ($results as $result) {
            $event_type = $result['event_type'] ?: 'Unknown';

            // Extract distance from event name if possible
            $distance = $this->extract_distance($result['event_name']);
            $key = $distance ?: $event_type;

            if (!isset($seen_events[$key])) {
                $seen_events[$key] = true;
                $prs[$key] = [
                    'time_seconds' => (int) $result['time_seconds'],
                    'time_display' => $result['time_display'],
                    'event_name' => $result['event_name'],
                    'event_date' => $result['event_date'],
                    'age' => $result['athlete_age'],
                ];
            }
        }

        return $prs;
    }

    /**
     * Extract distance from event name
     */
    private function extract_distance(string $event_name): ?string {
        $patterns = [
            '/\b5\s*k\b/i' => '5K',
            '/\b8\s*k\b/i' => '8K',
            '/\b10\s*k\b/i' => '10K',
            '/\b15\s*k\b/i' => '15K',
            '/\b10\s*mi/i' => '10 Mile',
            '/\bhalf\s*marathon\b/i' => 'Half Marathon',
            '/\bmarathon\b/i' => 'Marathon',
            '/\b50\s*k\b/i' => '50K',
            '/\b100\s*k\b/i' => '100K',
            '/\bmile\b/i' => 'Mile',
        ];

        foreach ($patterns as $pattern => $distance) {
            if (preg_match($pattern, $event_name)) {
                return $distance;
            }
        }

        return null;
    }

    /**
     * Get event records (course/championship records)
     *
     * @param int         $event_id Event post ID
     * @param string|null $division Optional division filter
     * @return array Records by division/sex
     */
    public function get_event_records(int $event_id, ?string $division = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        // Get event type to find similar events
        $event_type_terms = wp_get_post_terms($event_id, 'pausatf_event_type', ['fields' => 'ids']);
        $event_name = get_the_title($event_id);

        if (empty($event_type_terms)) {
            return [];
        }

        // Find all events of this type with this name pattern
        $similar_events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'pausatf_event_type',
                    'terms' => $event_type_terms,
                ],
            ],
            's' => $this->get_event_base_name($event_name),
            'fields' => 'ids',
        ]);

        if (empty($similar_events)) {
            $similar_events = [$event_id];
        }

        $event_ids = implode(',', array_map('intval', $similar_events));

        $where = "r.event_id IN ({$event_ids}) AND r.time_seconds IS NOT NULL AND r.time_seconds > 0";
        $params = [];

        if ($division) {
            $where .= " AND r.division = %s";
            $params[] = $division;
        }

        $query = "SELECT
                    r.division,
                    MIN(r.time_seconds) as record_time,
                    (SELECT r2.athlete_name FROM {$table} r2
                     WHERE r2.event_id IN ({$event_ids})
                     AND r2.division = r.division
                     AND r2.time_seconds = MIN(r.time_seconds)
                     LIMIT 1) as record_holder,
                    (SELECT " . EventDate::sql_expr('m') . " FROM {$table} r2
                     " . EventDate::sql_join('r2.event_id', 'm') . "
                     WHERE r2.event_id IN ({$event_ids})
                     AND r2.division = r.division
                     AND r2.time_seconds = MIN(r.time_seconds)
                     LIMIT 1) as record_date
                  FROM {$table} r
                  WHERE {$where}
                  GROUP BY r.division
                  ORDER BY r.division";

        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
        } else {
            $results = $wpdb->get_results($query, ARRAY_A);
        }

        $records = [];
        foreach ($results as $row) {
            $records[$row['division']] = [
                'time_seconds' => (int) $row['record_time'],
                'time_display' => $this->seconds_to_time($row['record_time']),
                'holder' => $row['record_holder'],
                'date' => $row['record_date'],
            ];
        }

        return $records;
    }

    /**
     * Get base name of event (without year)
     */
    private function get_event_base_name(string $name): string {
        // Remove year from event name
        return preg_replace('/\s*\d{4}\s*/', ' ', $name);
    }

    /**
     * Convert seconds to time display
     */
    private function seconds_to_time(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Check if a result is a PR
     *
     * @param array $result Result data
     * @return bool
     */
    public function is_personal_record(array $result): bool {
        if (empty($result['athlete_name']) || empty($result['time_seconds'])) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        // Get event type
        $event_type = null;
        if (!empty($result['event_id'])) {
            $terms = wp_get_post_terms($result['event_id'], 'pausatf_event_type', ['fields' => 'names']);
            $event_type = $terms[0] ?? null;
        }

        if (!$event_type) {
            return false;
        }

        // Check if this is the fastest time for this athlete in this event type
        $faster_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} r
             LEFT JOIN {$wpdb->term_relationships} tr ON r.event_id = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE r.athlete_name = %s
             AND t.name = %s
             AND r.time_seconds < %d
             AND r.time_seconds > 0",
            $result['athlete_name'],
            $event_type,
            $result['time_seconds']
        ));

        return (int) $faster_count === 0;
    }

    /**
     * Get performance trends for an athlete
     *
     * @param string $athlete_name Athlete name
     * @param string $event_type Event type to track
     * @return array Chronological performance data
     */
    public function get_performance_trend(string $athlete_name, string $event_type): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.time_seconds,
                r.time_display,
                r.athlete_age,
                r.points,
                p.post_title as event_name,
                " . EventDate::sql_expr('m') . " as event_date
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             " . EventDate::sql_join('r.event_id', 'm') . "
             LEFT JOIN {$wpdb->term_relationships} tr ON r.event_id = tr.object_id
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'pausatf_event_type'
             LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE r.athlete_name = %s
             AND t.name = %s
             AND r.time_seconds IS NOT NULL
             AND r.time_seconds > 0
             ORDER BY " . EventDate::sql_expr('m') . " ASC",
            $athlete_name,
            $event_type
        ), ARRAY_A);
    }
}
