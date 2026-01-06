<?php
/**
 * Ultra-Signup Integration
 *
 * Imports ultramarathon and trail race results from Ultra-Signup
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ultra-Signup results importer
 */
class UltraSignupImport {
    private const BASE_URL = 'https://ultrasignup.com';
    private const RESULTS_URL = 'https://ultrasignup.com/results_event.aspx';

    /**
     * Search for races
     *
     * @param array $params Search parameters
     * @return array Matching races
     */
    public function search_races(array $params = []): array {
        $url = self::BASE_URL . '/register.aspx';

        $query = [
            'state' => $params['state'] ?? 'CA',
            'year' => $params['year'] ?? date('Y'),
        ];

        $response = wp_remote_get($url . '?' . http_build_query($query), [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return $this->parse_race_list(wp_remote_retrieve_body($response));
    }

    /**
     * Parse race list from HTML
     */
    private function parse_race_list(string $html): array {
        $races = [];

        if (preg_match_all('/<a[^>]+href="results_event\.aspx\?did=(\d+)"[^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $races[] = [
                    'id' => $match[1],
                    'name' => html_entity_decode(trim($match[2])),
                ];
            }
        }

        return $races;
    }

    /**
     * Get race results
     *
     * @param int $race_id Ultra-Signup race ID (did parameter)
     * @return array Results
     */
    public function get_results(int $race_id): array {
        $url = self::RESULTS_URL . '?did=' . $race_id;

        $response = wp_remote_get($url, [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return $this->parse_results_page(wp_remote_retrieve_body($response));
    }

    /**
     * Parse results page HTML
     */
    private function parse_results_page(string $html): array {
        $results = [];

        // Extract event info
        $event_info = $this->extract_event_info($html);

        // Find results table
        if (!preg_match('/<table[^>]+id="list"[^>]*>(.*?)<\/table>/is', $html, $table_match)) {
            // Try alternate table format
            if (!preg_match('/<table[^>]+class="[^"]*results[^"]*"[^>]*>(.*?)<\/table>/is', $html, $table_match)) {
                return [];
            }
        }

        $table_html = $table_match[1];

        // Parse header row to determine columns
        $columns = [];
        if (preg_match('/<tr[^>]*>(.+?)<\/tr>/is', $table_html, $header_match)) {
            if (preg_match_all('/<t[dh][^>]*>([^<]*)<\/t[dh]>/i', $header_match[1], $col_matches)) {
                $columns = array_map('strtolower', array_map('trim', $col_matches[1]));
            }
        }

        // Parse data rows
        if (preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*)<\/tr>/is', $table_html, $row_matches)) {
            $is_header = true;
            foreach ($row_matches[1] as $row_html) {
                if ($is_header) {
                    $is_header = false;
                    continue;
                }

                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row_html, $cell_matches)) {
                    $row_data = [];
                    foreach ($cell_matches[1] as $i => $cell) {
                        $col_name = $columns[$i] ?? "col_{$i}";
                        $row_data[$col_name] = strip_tags(trim($cell));
                    }

                    $result = $this->normalize_result($row_data);
                    if ($result) {
                        $results[] = $result;
                    }
                }
            }
        }

        return [
            'event' => $event_info,
            'results' => $results,
        ];
    }

    /**
     * Extract event info from page
     */
    private function extract_event_info(string $html): array {
        $info = [];

        // Event name
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $match)) {
            $info['name'] = html_entity_decode(trim($match[1]));
        }

        // Date
        if (preg_match('/(\w+ \d{1,2}, \d{4})/', $html, $match)) {
            $info['date'] = date('Y-m-d', strtotime($match[1]));
        }

        // Distance
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mile|mi|k|km|m)/i', $html, $match)) {
            $info['distance'] = $match[1];
            $info['distance_unit'] = strtolower($match[2]);
        }

        // Location
        if (preg_match('/(?:Location|City):\s*([^<\n]+)/i', $html, $match)) {
            $info['location'] = trim($match[1]);
        }

        return $info;
    }

    /**
     * Normalize result data
     */
    private function normalize_result(array $data): ?array {
        // Find place
        $place = null;
        foreach (['place', 'rank', 'pos', 'overall', '#'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $place = (int) $data[$key];
                break;
            }
        }

        // Find name
        $name = null;
        foreach (['name', 'runner', 'athlete', 'participant'] as $key) {
            if (!empty($data[$key])) {
                $name = $data[$key];
                break;
            }
        }

        if (!$name) {
            // Try combining first/last
            $first = $data['first'] ?? $data['firstname'] ?? '';
            $last = $data['last'] ?? $data['lastname'] ?? '';
            $name = trim("{$first} {$last}");
        }

        if (empty($name)) {
            return null;
        }

        // Find time
        $time = null;
        foreach (['time', 'finish', 'clock', 'elapsed'] as $key) {
            if (!empty($data[$key])) {
                $time = $data[$key];
                break;
            }
        }

        // Find age/gender
        $age = null;
        $gender = null;
        if (!empty($data['age'])) {
            $age = (int) $data['age'];
        }
        if (!empty($data['gender']) || !empty($data['sex'])) {
            $gender = strtoupper(substr($data['gender'] ?? $data['sex'], 0, 1));
        }

        return [
            'place' => $place,
            'athlete_name' => $name,
            'time_display' => $time,
            'time_seconds' => $this->parse_time($time),
            'athlete_age' => $age,
            'gender' => $gender,
            'division' => $data['division'] ?? $data['age_group'] ?? $data['class'] ?? null,
            'division_place' => (int) ($data['div_place'] ?? $data['age_place'] ?? 0),
            'city' => $data['city'] ?? $data['hometown'] ?? null,
            'state' => $data['state'] ?? $data['st'] ?? null,
        ];
    }

    /**
     * Parse time string to seconds
     */
    private function parse_time(?string $time): ?int {
        if (!$time) {
            return null;
        }

        $time = trim($time);

        // Handle DNF, DNS, etc.
        if (preg_match('/^(DNF|DNS|DQ|DNP)/i', $time)) {
            return null;
        }

        // HH:MM:SS format
        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        }

        // MM:SS format
        if (preg_match('/^(\d+):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        return null;
    }

    /**
     * Import race results
     *
     * @param int $race_id Ultra-Signup race ID
     * @param array $options Import options
     * @return array Import result
     */
    public function import_race(int $race_id, array $options = []): array {
        $data = $this->get_results($race_id);

        if (empty($data['results'])) {
            return ['success' => false, 'error' => 'No results found'];
        }

        $event_info = $data['event'];

        // Create event
        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $event_info['name'] ?? 'Ultra-Signup Event',
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $event_info['date'] ?? null,
                '_pausatf_event_location' => $event_info['location'] ?? '',
                '_pausatf_event_distance' => $event_info['distance'] ?? '',
                '_pausatf_import_source' => 'ultrasignup',
                '_pausatf_ultrasignup_id' => $race_id,
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ]);

        if (is_wp_error($event_id)) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        // Set event type
        wp_set_object_terms($event_id, 'Mountain/Ultra/Trail', 'pausatf_event_type');

        // Set season
        if (!empty($event_info['date'])) {
            wp_set_object_terms($event_id, date('Y', strtotime($event_info['date'])), 'pausatf_season');
        }

        // Save results
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($data['results'] as $result) {
            $row = [
                'event_id' => $event_id,
                'athlete_name' => $result['athlete_name'],
                'athlete_age' => $result['athlete_age'],
                'place' => $result['place'],
                'time_seconds' => $result['time_seconds'],
                'time_display' => $result['time_display'],
                'division' => $result['division'],
                'division_place' => $result['division_place'],
                'raw_data' => json_encode($result),
            ];

            if ($wpdb->insert($table, $row)) {
                $imported++;
            }
        }

        update_post_meta($event_id, '_pausatf_result_count', $imported);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'event_name' => $event_info['name'] ?? '',
            'source' => 'ultrasignup',
        ];
    }

    /**
     * Get athlete's Ultra-Signup profile results
     *
     * @param string $first_name First name
     * @param string $last_name Last name
     * @return array Results
     */
    public function get_athlete_history(string $first_name, string $last_name): array {
        $url = self::BASE_URL . '/results_participant.aspx';

        $response = wp_remote_get($url . '?' . http_build_query([
            'fname' => $first_name,
            'lname' => $last_name,
        ]), [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return $this->parse_athlete_history(wp_remote_retrieve_body($response));
    }

    /**
     * Parse athlete history page
     */
    private function parse_athlete_history(string $html): array {
        $results = [];

        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows)) {
            foreach ($rows[1] as $row) {
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cells)) {
                    if (count($cells[1]) >= 4) {
                        $results[] = [
                            'date' => strip_tags(trim($cells[1][0])),
                            'event' => strip_tags(trim($cells[1][1])),
                            'distance' => strip_tags(trim($cells[1][2])),
                            'time' => strip_tags(trim($cells[1][3])),
                            'place' => isset($cells[1][4]) ? strip_tags(trim($cells[1][4])) : null,
                        ];
                    }
                }
            }
        }

        return $results;
    }
}
