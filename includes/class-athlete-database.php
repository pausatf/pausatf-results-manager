<?php
/**
 * Athlete Database - Manages athlete records and cross-event tracking
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Athlete database management class
 */
class AthleteDatabase {
    /**
     * Search for athletes by name
     *
     * @param string $query Search query
     * @param int    $limit Max results
     * @return array Matching athletes
     */
    public function search(string $query, int $limit = 20): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';

        // Search in results table for unique athlete names
        $athletes = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT athlete_name, athlete_id,
                    COUNT(*) as event_count,
                    MIN(athlete_age) as min_age,
                    MAX(athlete_age) as max_age
             FROM {$results_table}
             WHERE athlete_name LIKE %s
             GROUP BY athlete_name, athlete_id
             ORDER BY event_count DESC
             LIMIT %d",
            '%' . $wpdb->esc_like($query) . '%',
            $limit
        ), ARRAY_A);

        return $athletes;
    }

    /**
     * Get all results for an athlete
     *
     * @param string   $name Athlete name
     * @param int|null $athlete_id Optional athlete post ID
     * @return array Results history
     */
    public function get_athlete_results(string $name, ?int $athlete_id = null): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';

        if ($athlete_id) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, p.post_title as event_name, m.meta_value as event_date
                 FROM {$results_table} r
                 LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
                 LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
                 WHERE r.athlete_id = %d
                 ORDER BY m.meta_value DESC",
                $athlete_id
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, p.post_title as event_name, m.meta_value as event_date
                 FROM {$results_table} r
                 LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
                 LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
                 WHERE r.athlete_name = %s
                 ORDER BY m.meta_value DESC",
                $name
            ), ARRAY_A);
        }

        return $results;
    }

    /**
     * Get athlete statistics
     *
     * @param string $name Athlete name
     * @return array Statistics
     */
    public function get_athlete_stats(string $name): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_events,
                COUNT(DISTINCT division) as divisions_competed,
                MIN(place) as best_place,
                AVG(place) as avg_place,
                SUM(CASE WHEN place = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN place <= 3 THEN 1 ELSE 0 END) as podiums,
                MIN(time_seconds) as best_time,
                SUM(COALESCE(points, 0)) as total_points,
                SUM(COALESCE(payout, 0)) as total_earnings
             FROM {$results_table}
             WHERE athlete_name = %s",
            $name
        ), ARRAY_A);

        return $stats ?: [];
    }

    /**
     * Create athlete post from results data
     *
     * @param string $name Athlete name
     * @return int|\WP_Error Athlete post ID or error
     */
    public function create_athlete(string $name): int|\WP_Error {
        // Check if already exists
        $existing = get_page_by_title($name, OBJECT, 'pausatf_athlete');
        if ($existing) {
            return $existing->ID;
        }

        // Get stats for this athlete
        $stats = $this->get_athlete_stats($name);
        $results = $this->get_athlete_results($name);

        // Determine divisions
        $divisions = array_unique(array_filter(array_column($results, 'division')));

        $athlete_id = wp_insert_post([
            'post_type' => 'pausatf_athlete',
            'post_title' => $name,
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_total_events' => $stats['total_events'] ?? 0,
                '_pausatf_wins' => $stats['wins'] ?? 0,
                '_pausatf_podiums' => $stats['podiums'] ?? 0,
                '_pausatf_total_points' => $stats['total_points'] ?? 0,
                '_pausatf_total_earnings' => $stats['total_earnings'] ?? 0,
                '_pausatf_best_place' => $stats['best_place'] ?? null,
            ],
        ], true);

        if (!is_wp_error($athlete_id) && !empty($divisions)) {
            wp_set_object_terms($athlete_id, $divisions, 'pausatf_division');
        }

        return $athlete_id;
    }

    /**
     * Bulk create athletes from results
     *
     * @param int $min_events Minimum events to qualify for athlete record
     * @return array Creation results
     */
    public function bulk_create_athletes(int $min_events = 3): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';

        // Get athletes with minimum event count
        $athletes = $wpdb->get_results($wpdb->prepare(
            "SELECT athlete_name, COUNT(*) as event_count
             FROM {$results_table}
             WHERE athlete_id IS NULL
             AND athlete_name != ''
             GROUP BY athlete_name
             HAVING event_count >= %d
             ORDER BY event_count DESC",
            $min_events
        ), ARRAY_A);

        $created = 0;
        $errors = [];

        foreach ($athletes as $athlete) {
            $result = $this->create_athlete($athlete['athlete_name']);

            if (is_wp_error($result)) {
                $errors[] = $athlete['athlete_name'] . ': ' . $result->get_error_message();
            } else {
                // Update results with athlete ID
                $wpdb->update(
                    $results_table,
                    ['athlete_id' => $result],
                    ['athlete_name' => $athlete['athlete_name']]
                );
                $created++;
            }
        }

        return [
            'created' => $created,
            'errors' => $errors,
            'total_eligible' => count($athletes),
        ];
    }

    /**
     * Get leaderboard by points
     *
     * @param string|null $division Optional division filter
     * @param string|null $season Optional year/season filter
     * @param int         $limit Max results
     * @return array Leaderboard
     */
    public function get_leaderboard(?string $division = null, ?string $season = null, int $limit = 50): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';

        $where = ['1=1'];
        $params = [];

        if ($division) {
            $where[] = 'r.division = %s';
            $params[] = $division;
        }

        if ($season) {
            $where[] = 'YEAR(m.meta_value) = %d';
            $params[] = (int) $season;
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT
                    r.athlete_name,
                    r.athlete_id,
                    SUM(r.points) as total_points,
                    COUNT(*) as events,
                    SUM(CASE WHEN r.place = 1 THEN 1 ELSE 0 END) as wins,
                    MIN(r.place) as best_finish
                  FROM {$results_table} r
                  LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
                  WHERE {$where_clause}
                  AND r.points IS NOT NULL
                  GROUP BY r.athlete_name, r.athlete_id
                  ORDER BY total_points DESC
                  LIMIT %d";

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
    }
}
