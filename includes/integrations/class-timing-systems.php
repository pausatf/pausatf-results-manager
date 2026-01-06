<?php
/**
 * Timing System Integrations
 *
 * Imports results from various timing systems (ChronoTrack, MYLAPS, Webscorer)
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multi-system timing integration handler
 */
class TimingSystems {
    /**
     * Supported timing systems
     */
    private const SYSTEMS = [
        'chronotrack' => ChronoTrackClient::class,
        'mylaps' => MYLAPSClient::class,
        'webscorer' => WebscorerClient::class,
        'racetab' => RaceTabClient::class,
    ];

    /**
     * Get timing system client
     *
     * @param string $system System identifier
     * @return TimingSystemInterface|null
     */
    public function get_client(string $system): ?TimingSystemInterface {
        $class = self::SYSTEMS[$system] ?? null;

        if (!$class || !class_exists($class)) {
            return null;
        }

        return new $class();
    }

    /**
     * Import results from timing system
     *
     * @param string $system System identifier
     * @param string $race_id Race/event ID in the timing system
     * @param array $options Import options
     * @return array Import result
     */
    public function import_results(string $system, string $race_id, array $options = []): array {
        $client = $this->get_client($system);

        if (!$client) {
            return ['success' => false, 'error' => "Unsupported timing system: {$system}"];
        }

        if (!$client->is_configured()) {
            return ['success' => false, 'error' => "{$system} is not configured"];
        }

        return $client->import_race($race_id, $options);
    }

    /**
     * Get available timing systems
     */
    public function get_available_systems(): array {
        $available = [];

        foreach (self::SYSTEMS as $id => $class) {
            $client = $this->get_client($id);
            if ($client) {
                $available[$id] = [
                    'name' => $client->get_name(),
                    'configured' => $client->is_configured(),
                ];
            }
        }

        return $available;
    }
}

/**
 * Timing system interface
 */
interface TimingSystemInterface {
    public function get_name(): string;
    public function is_configured(): bool;
    public function search_races(array $params = []): array;
    public function get_race(string $race_id): ?array;
    public function get_results(string $race_id): array;
    public function import_race(string $race_id, array $options = []): array;
}

/**
 * ChronoTrack timing system client
 */
class ChronoTrackClient implements TimingSystemInterface {
    private const API_BASE = 'https://api.chronotrack.com/v1';

    private string $api_key;

    public function __construct() {
        $this->api_key = get_option('pausatf_chronotrack_api_key', '');
    }

    public function get_name(): string {
        return 'ChronoTrack';
    }

    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    public function search_races(array $params = []): array {
        $response = $this->api_request('events', $params);
        return $response['events'] ?? [];
    }

    public function get_race(string $race_id): ?array {
        $response = $this->api_request("events/{$race_id}");
        return $response['event'] ?? null;
    }

    public function get_results(string $race_id): array {
        $response = $this->api_request("events/{$race_id}/results", [
            'limit' => 5000,
        ]);
        return $response['results'] ?? [];
    }

    public function import_race(string $race_id, array $options = []): array {
        $race = $this->get_race($race_id);
        if (!$race) {
            return ['success' => false, 'error' => 'Race not found'];
        }

        $results = $this->get_results($race_id);
        if (empty($results)) {
            return ['success' => false, 'error' => 'No results found'];
        }

        // Create event
        $event_id = $this->create_event($race);
        if (!$event_id) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        // Import results
        $imported = $this->save_results($event_id, $results);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'source' => 'chronotrack',
        ];
    }

    private function create_event(array $race): int {
        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $race['name'] ?? 'ChronoTrack Event',
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $race['date'] ?? null,
                '_pausatf_event_location' => $race['location'] ?? '',
                '_pausatf_import_source' => 'chronotrack',
                '_pausatf_chronotrack_id' => $race['id'] ?? null,
            ],
        ]);

        return is_wp_error($event_id) ? 0 : $event_id;
    }

    private function save_results(int $event_id, array $results): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($results as $result) {
            $data = [
                'event_id' => $event_id,
                'athlete_name' => trim(($result['firstName'] ?? '') . ' ' . ($result['lastName'] ?? '')),
                'bib' => $result['bib'] ?? null,
                'place' => $result['overallPlace'] ?? null,
                'division' => $result['division'] ?? null,
                'division_place' => $result['divisionPlace'] ?? null,
                'time_seconds' => $result['chipTimeSeconds'] ?? $result['gunTimeSeconds'] ?? null,
                'time_display' => $result['chipTime'] ?? $result['gunTime'] ?? '',
                'raw_data' => json_encode($result),
            ];

            if ($wpdb->insert($table, $data)) {
                $imported++;
            }
        }

        update_post_meta($event_id, '_pausatf_result_count', $imported);
        return $imported;
    }

    private function api_request(string $endpoint, array $params = []): ?array {
        $url = self::API_BASE . '/' . $endpoint;
        $params['api_key'] = $this->api_key;

        $url .= '?' . http_build_query($params);

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

/**
 * MYLAPS timing system client
 */
class MYLAPSClient implements TimingSystemInterface {
    private const API_BASE = 'https://api.mylaps.com/v1';

    private string $api_key;

    public function __construct() {
        $this->api_key = get_option('pausatf_mylaps_api_key', '');
    }

    public function get_name(): string {
        return 'MYLAPS';
    }

    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    public function search_races(array $params = []): array {
        $response = $this->api_request('events', $params);
        return $response['events'] ?? [];
    }

    public function get_race(string $race_id): ?array {
        $response = $this->api_request("events/{$race_id}");
        return $response['event'] ?? null;
    }

    public function get_results(string $race_id): array {
        $results = [];
        $page = 1;

        do {
            $response = $this->api_request("events/{$race_id}/results", [
                'page' => $page,
                'per_page' => 500,
            ]);

            $page_results = $response['results'] ?? [];
            $results = array_merge($results, $page_results);
            $page++;
        } while (count($page_results) === 500);

        return $results;
    }

    public function import_race(string $race_id, array $options = []): array {
        $race = $this->get_race($race_id);
        if (!$race) {
            return ['success' => false, 'error' => 'Race not found'];
        }

        $results = $this->get_results($race_id);
        if (empty($results)) {
            return ['success' => false, 'error' => 'No results found'];
        }

        $event_id = $this->create_event($race);
        if (!$event_id) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        $imported = $this->save_results($event_id, $results);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'source' => 'mylaps',
        ];
    }

    private function create_event(array $race): int {
        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $race['name'] ?? 'MYLAPS Event',
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $race['startDate'] ?? null,
                '_pausatf_event_location' => $race['venue'] ?? '',
                '_pausatf_import_source' => 'mylaps',
                '_pausatf_mylaps_id' => $race['id'] ?? null,
            ],
        ]);

        return is_wp_error($event_id) ? 0 : $event_id;
    }

    private function save_results(int $event_id, array $results): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($results as $result) {
            $data = [
                'event_id' => $event_id,
                'athlete_name' => $result['participantName'] ?? '',
                'bib' => $result['startNumber'] ?? null,
                'place' => $result['position'] ?? null,
                'time_seconds' => isset($result['finishTimeMs']) ? (int) ($result['finishTimeMs'] / 1000) : null,
                'time_display' => $result['finishTime'] ?? '',
                'raw_data' => json_encode($result),
            ];

            if ($wpdb->insert($table, $data)) {
                $imported++;
            }
        }

        update_post_meta($event_id, '_pausatf_result_count', $imported);
        return $imported;
    }

    private function api_request(string $endpoint, array $params = []): ?array {
        $url = self::API_BASE . '/' . $endpoint;

        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

/**
 * Webscorer timing system client
 */
class WebscorerClient implements TimingSystemInterface {
    private const API_BASE = 'https://api.webscorer.com/v1';

    private string $api_key;

    public function __construct() {
        $this->api_key = get_option('pausatf_webscorer_api_key', '');
    }

    public function get_name(): string {
        return 'Webscorer';
    }

    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    public function search_races(array $params = []): array {
        $response = $this->api_request('races', $params);
        return $response['races'] ?? [];
    }

    public function get_race(string $race_id): ?array {
        $response = $this->api_request("races/{$race_id}");
        return $response['race'] ?? null;
    }

    public function get_results(string $race_id): array {
        $response = $this->api_request("races/{$race_id}/results");
        return $response['results'] ?? [];
    }

    public function import_race(string $race_id, array $options = []): array {
        $race = $this->get_race($race_id);
        if (!$race) {
            return ['success' => false, 'error' => 'Race not found'];
        }

        $results = $this->get_results($race_id);
        if (empty($results)) {
            return ['success' => false, 'error' => 'No results found'];
        }

        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $race['name'] ?? 'Webscorer Event',
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $race['date'] ?? null,
                '_pausatf_import_source' => 'webscorer',
                '_pausatf_webscorer_id' => $race_id,
            ],
        ]);

        if (is_wp_error($event_id)) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($results as $result) {
            if ($wpdb->insert($table, [
                'event_id' => $event_id,
                'athlete_name' => $result['name'] ?? '',
                'bib' => $result['bib'] ?? null,
                'place' => $result['place'] ?? null,
                'time_display' => $result['time'] ?? '',
                'raw_data' => json_encode($result),
            ])) {
                $imported++;
            }
        }

        update_post_meta($event_id, '_pausatf_result_count', $imported);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'source' => 'webscorer',
        ];
    }

    private function api_request(string $endpoint, array $params = []): ?array {
        $url = self::API_BASE . '/' . $endpoint;
        $params['apiKey'] = $this->api_key;

        $response = wp_remote_get($url . '?' . http_build_query($params), ['timeout' => 30]);

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

/**
 * RaceTab timing client
 */
class RaceTabClient implements TimingSystemInterface {
    private const API_BASE = 'https://api.racetab.io/v1';

    private string $api_key;

    public function __construct() {
        $this->api_key = get_option('pausatf_racetab_api_key', '');
    }

    public function get_name(): string {
        return 'RaceTab';
    }

    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    public function search_races(array $params = []): array {
        return [];
    }

    public function get_race(string $race_id): ?array {
        return null;
    }

    public function get_results(string $race_id): array {
        return [];
    }

    public function import_race(string $race_id, array $options = []): array {
        return ['success' => false, 'error' => 'RaceTab integration coming soon'];
    }
}

// Register settings
add_action('admin_init', function () {
    register_setting('pausatf_results', 'pausatf_chronotrack_api_key');
    register_setting('pausatf_results', 'pausatf_mylaps_api_key');
    register_setting('pausatf_results', 'pausatf_webscorer_api_key');
    register_setting('pausatf_results', 'pausatf_racetab_api_key');
});
