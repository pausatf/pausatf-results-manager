<?php
/**
 * RunSignUp API Integration
 *
 * Imports results from RunSignUp races and syncs with USATF membership
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RunSignUp API client and result importer
 */
class RunSignUpIntegration {
    private const API_BASE = 'https://runsignup.com/Rest';
    private const API_VERSION = '2';

    private string $api_key;
    private string $api_secret;

    public function __construct() {
        $this->api_key = get_option('pausatf_runsignup_api_key', '');
        $this->api_secret = get_option('pausatf_runsignup_api_secret', '');
    }

    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return !empty($this->api_key) && !empty($this->api_secret);
    }

    /**
     * Search for races
     *
     * @param array $params Search parameters
     * @return array Search results
     */
    public function search_races(array $params = []): array {
        $defaults = [
            'state' => 'CA',
            'distance_units' => 'K',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'results_per_page' => 25,
        ];

        $params = array_merge($defaults, $params);

        $response = $this->api_request('races', $params);

        if (!$response || !isset($response['races'])) {
            return [];
        }

        return $response['races'];
    }

    /**
     * Get race details
     *
     * @param int $race_id RunSignUp race ID
     * @return array|null Race details
     */
    public function get_race(int $race_id): ?array {
        $response = $this->api_request("race/{$race_id}");

        return $response['race'] ?? null;
    }

    /**
     * Get race results
     *
     * @param int   $race_id RunSignUp race ID
     * @param int   $event_id Event ID within the race
     * @param array $params Additional parameters
     * @return array Results
     */
    public function get_results(int $race_id, int $event_id = 0, array $params = []): array {
        $endpoint = "race/{$race_id}/results";

        if ($event_id) {
            $params['event_id'] = $event_id;
        }

        $params['results_per_page'] = $params['results_per_page'] ?? 1000;

        $response = $this->api_request($endpoint, $params);

        return $response['results'] ?? [];
    }

    /**
     * Import results from a RunSignUp race
     *
     * @param int   $race_id RunSignUp race ID
     * @param array $options Import options
     * @return array Import result
     */
    public function import_race_results(int $race_id, array $options = []): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'error' => 'RunSignUp API not configured'];
        }

        // Get race info
        $race = $this->get_race($race_id);
        if (!$race) {
            return ['success' => false, 'error' => 'Race not found'];
        }

        // Get results
        $results = $this->get_results($race_id, $options['event_id'] ?? 0);
        if (empty($results)) {
            return ['success' => false, 'error' => 'No results found'];
        }

        // Create event
        $event_id = $this->create_event($race, $options);
        if (!$event_id) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        // Import results
        $imported = $this->save_results($event_id, $results);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'race_name' => $race['name'] ?? '',
            'source_id' => $race_id,
        ];
    }

    /**
     * Make API request
     */
    private function api_request(string $endpoint, array $params = []): ?array {
        $url = self::API_BASE . '/' . $endpoint;

        $params['api_key'] = $this->api_key;
        $params['api_secret'] = $this->api_secret;
        $params['format'] = 'json';

        $url .= '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('RunSignUp API Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('RunSignUp API: Invalid JSON response');
            return null;
        }

        return $data;
    }

    /**
     * Create event from race data
     */
    private function create_event(array $race, array $options): int {
        $event_name = $race['name'] ?? 'RunSignUp Race';

        // Extract date
        $date = null;
        if (!empty($race['next_date'])) {
            $date = date('Y-m-d', strtotime($race['next_date']));
        } elseif (!empty($race['last_date'])) {
            $date = date('Y-m-d', strtotime($race['last_date']));
        }

        // Extract location
        $location = '';
        if (!empty($race['address'])) {
            $addr = $race['address'];
            $location = trim(implode(', ', array_filter([
                $addr['city'] ?? '',
                $addr['state'] ?? '',
            ])));
        }

        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $event_name,
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $date,
                '_pausatf_event_location' => $location,
                '_pausatf_import_source' => 'runsignup',
                '_pausatf_runsignup_id' => $race['race_id'] ?? null,
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ]);

        if (!is_wp_error($event_id)) {
            // Set event type based on distance
            $event_type = $this->detect_event_type($race);
            if ($event_type) {
                wp_set_object_terms($event_id, $event_type, 'pausatf_event_type');
            }

            // Set year
            if ($date) {
                wp_set_object_terms($event_id, date('Y', strtotime($date)), 'pausatf_season');
            }
        }

        return is_wp_error($event_id) ? 0 : $event_id;
    }

    /**
     * Save results to database
     */
    private function save_results(int $event_id, array $results): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($results as $result) {
            $user = $result['user'] ?? [];

            $data = [
                'event_id' => $event_id,
                'athlete_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'athlete_age' => $this->calculate_age($user['dob'] ?? null),
                'place' => (int) ($result['place'] ?? 0),
                'time_seconds' => $this->parse_time($result['clock_time'] ?? $result['chip_time'] ?? ''),
                'time_display' => $result['clock_time'] ?? $result['chip_time'] ?? '',
                'division' => $result['age_group'] ?? null,
                'division_place' => (int) ($result['age_group_place'] ?? 0),
                'bib' => $result['bib_num'] ?? null,
                'club' => $result['team_name'] ?? null,
                'raw_data' => json_encode($result),
            ];

            // Determine division if not set
            if (empty($data['division']) && $data['athlete_age']) {
                $data['division'] = $this->age_to_division($data['athlete_age']);
            }

            if ($wpdb->insert($table, $data)) {
                $imported++;
            }
        }

        update_post_meta($event_id, '_pausatf_result_count', $imported);

        return $imported;
    }

    /**
     * Detect event type from race data
     */
    private function detect_event_type(array $race): ?string {
        $name = strtolower($race['name'] ?? '');

        if (strpos($name, 'cross country') !== false || strpos($name, 'xc') !== false) {
            return 'Cross Country';
        }

        if (strpos($name, 'trail') !== false || strpos($name, 'ultra') !== false) {
            return 'Mountain/Ultra/Trail';
        }

        if (strpos($name, 'track') !== false) {
            return 'Track & Field';
        }

        if (strpos($name, 'walk') !== false) {
            return 'Race Walk';
        }

        return 'Road Race';
    }

    /**
     * Calculate age from DOB
     */
    private function calculate_age(?string $dob): ?int {
        if (!$dob) {
            return null;
        }

        try {
            $birth = new \DateTime($dob);
            $now = new \DateTime();
            return $birth->diff($now)->y;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse time string to seconds
     */
    private function parse_time(string $time): ?int {
        $time = trim($time);

        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        }

        if (preg_match('/^(\d+):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        return null;
    }

    /**
     * Convert age to division
     */
    private function age_to_division(int $age): string {
        if ($age < 40) return 'Open';
        if ($age < 50) return 'Masters 40+';
        if ($age < 60) return 'Seniors 50+';
        if ($age < 70) return 'Super-Seniors 60+';
        return 'Veterans 70+';
    }

    /**
     * Register settings
     */
    public static function register_settings(): void {
        register_setting('pausatf_results', 'pausatf_runsignup_api_key');
        register_setting('pausatf_results', 'pausatf_runsignup_api_secret');
    }
}

// Register settings on admin init
add_action('admin_init', [RunSignUpIntegration::class, 'register_settings']);
