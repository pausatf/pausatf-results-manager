<?php
/**
 * Athlinks API Integration
 *
 * Syncs athlete race history from Athlinks
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Athlinks API client and result syncer
 */
class AthlinksIntegration {
    private const API_BASE = 'https://api.athlinks.com/v1';

    private string $api_key;

    public function __construct() {
        $this->api_key = get_option('pausatf_athlinks_api_key', '');
    }

    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Search for athlete by name
     *
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param array $filters Optional filters (city, state, age)
     * @return array Matching athletes
     */
    public function search_athlete(string $first_name, string $last_name, array $filters = []): array {
        $params = [
            'firstName' => $first_name,
            'lastName' => $last_name,
        ];

        if (!empty($filters['city'])) {
            $params['city'] = $filters['city'];
        }
        if (!empty($filters['state'])) {
            $params['state'] = $filters['state'];
        }

        $response = $this->api_request('athletes/search', $params);

        return $response['athletes'] ?? [];
    }

    /**
     * Get athlete profile
     *
     * @param int $athlinks_id Athlinks athlete ID
     * @return array|null Athlete profile
     */
    public function get_athlete(int $athlinks_id): ?array {
        $response = $this->api_request("athletes/{$athlinks_id}");

        return $response['athlete'] ?? null;
    }

    /**
     * Get athlete's race results
     *
     * @param int $athlinks_id Athlinks athlete ID
     * @param array $params Query parameters
     * @return array Race results
     */
    public function get_athlete_results(int $athlinks_id, array $params = []): array {
        $defaults = [
            'limit' => 100,
            'offset' => 0,
        ];

        $params = array_merge($defaults, $params);
        $response = $this->api_request("athletes/{$athlinks_id}/results", $params);

        return $response['results'] ?? [];
    }

    /**
     * Sync athlete results from Athlinks
     *
     * @param int $athlete_id Local athlete post ID
     * @param int $athlinks_id Athlinks athlete ID
     * @return array Sync result
     */
    public function sync_athlete_results(int $athlete_id, int $athlinks_id): array {
        if (!$this->is_configured()) {
            return ['success' => false, 'error' => 'Athlinks API not configured'];
        }

        // Get results from Athlinks
        $athlinks_results = $this->get_athlete_results($athlinks_id);
        if (empty($athlinks_results)) {
            return ['success' => true, 'imported' => 0, 'message' => 'No results found'];
        }

        $imported = 0;
        $skipped = 0;

        foreach ($athlinks_results as $result) {
            // Check if already imported
            if ($this->result_exists($result['resultId'])) {
                $skipped++;
                continue;
            }

            // Import the result
            if ($this->import_result($athlete_id, $result)) {
                $imported++;
            }
        }

        // Update sync timestamp
        update_post_meta($athlete_id, '_pausatf_athlinks_id', $athlinks_id);
        update_post_meta($athlete_id, '_pausatf_athlinks_synced', current_time('mysql'));

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($athlinks_results),
        ];
    }

    /**
     * Import a single Athlinks result
     */
    private function import_result(int $athlete_id, array $result): bool {
        global $wpdb;

        // Find or create event
        $event_id = $this->find_or_create_event($result);
        if (!$event_id) {
            return false;
        }

        $athlete = get_post($athlete_id);
        $athlete_name = $athlete ? $athlete->post_title : '';

        $data = [
            'event_id' => $event_id,
            'athlete_id' => $athlete_id,
            'athlete_name' => $athlete_name,
            'place' => $result['overallPlace'] ?? null,
            'division' => $result['divisionName'] ?? null,
            'division_place' => $result['divisionPlace'] ?? null,
            'time_seconds' => $this->parse_athlinks_time($result['chipTime'] ?? $result['gunTime'] ?? null),
            'time_display' => $result['chipTimeFormatted'] ?? $result['gunTimeFormatted'] ?? '',
            'pace' => $result['paceFormatted'] ?? null,
            'raw_data' => json_encode([
                'athlinks_result_id' => $result['resultId'],
                'athlinks_race_id' => $result['raceId'],
                'source' => 'athlinks',
            ]),
        ];

        $table = $wpdb->prefix . 'pausatf_results';
        return (bool) $wpdb->insert($table, $data);
    }

    /**
     * Find or create event from Athlinks race data
     */
    private function find_or_create_event(array $result): int {
        // Try to find existing event by Athlinks ID
        $existing = get_posts([
            'post_type' => 'pausatf_event',
            'meta_key' => '_pausatf_athlinks_race_id',
            'meta_value' => $result['raceId'],
            'posts_per_page' => 1,
        ]);

        if (!empty($existing)) {
            return $existing[0]->ID;
        }

        // Create new event
        $race_name = $result['raceName'] ?? 'Athlinks Race';
        $race_date = !empty($result['raceDate']) ? date('Y-m-d', strtotime($result['raceDate'])) : null;

        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $race_name,
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $race_date,
                '_pausatf_event_location' => trim(($result['city'] ?? '') . ', ' . ($result['state'] ?? '')),
                '_pausatf_event_distance' => $result['distance'] ?? null,
                '_pausatf_import_source' => 'athlinks',
                '_pausatf_athlinks_race_id' => $result['raceId'],
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ]);

        if (is_wp_error($event_id)) {
            return 0;
        }

        // Set season
        if ($race_date) {
            wp_set_object_terms($event_id, date('Y', strtotime($race_date)), 'pausatf_season');
        }

        return $event_id;
    }

    /**
     * Check if result already exists
     */
    private function result_exists(int $athlinks_result_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE raw_data LIKE %s",
            '%"athlinks_result_id":' . $athlinks_result_id . '%'
        ));

        return $count > 0;
    }

    /**
     * Parse Athlinks time to seconds
     */
    private function parse_athlinks_time(?int $milliseconds): ?int {
        if (!$milliseconds) {
            return null;
        }
        return (int) round($milliseconds / 1000);
    }

    /**
     * Make API request
     */
    private function api_request(string $endpoint, array $params = []): ?array {
        $url = self::API_BASE . '/' . $endpoint;

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept' => 'application/json',
        ];

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            error_log('Athlinks API Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Athlinks API: Invalid JSON response');
            return null;
        }

        return $data;
    }

    /**
     * Register settings
     */
    public static function register_settings(): void {
        register_setting('pausatf_results', 'pausatf_athlinks_api_key');
    }

    /**
     * Link athlete to Athlinks profile
     */
    public function link_athlete(int $athlete_id, int $athlinks_id): bool {
        $profile = $this->get_athlete($athlinks_id);
        if (!$profile) {
            return false;
        }

        update_post_meta($athlete_id, '_pausatf_athlinks_id', $athlinks_id);
        update_post_meta($athlete_id, '_pausatf_athlinks_profile', json_encode($profile));

        return true;
    }

    /**
     * Get linked Athlinks ID for athlete
     */
    public function get_linked_athlinks_id(int $athlete_id): ?int {
        $id = get_post_meta($athlete_id, '_pausatf_athlinks_id', true);
        return $id ? (int) $id : null;
    }
}

add_action('admin_init', [AthlinksIntegration::class, 'register_settings']);
