<?php
/**
 * Strava & Garmin Activity Sync
 *
 * Links race results to athlete activity data from Strava/Garmin
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strava OAuth and activity sync
 */
class StravaSync {
    private const AUTH_URL = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const API_BASE = 'https://www.strava.com/api/v3';

    private string $client_id;
    private string $client_secret;

    public function __construct() {
        $this->client_id = get_option('pausatf_strava_client_id', '');
        $this->client_secret = get_option('pausatf_strava_client_secret', '');

        add_action('wp_ajax_pausatf_strava_callback', [$this, 'handle_oauth_callback']);
        add_action('wp_ajax_nopriv_pausatf_strava_callback', [$this, 'handle_oauth_callback']);
    }

    /**
     * Check if configured
     */
    public function is_configured(): bool {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    /**
     * Get OAuth authorization URL
     *
     * @param int $user_id WordPress user ID
     * @return string Authorization URL
     */
    public function get_auth_url(int $user_id): string {
        $state = wp_create_nonce('pausatf_strava_' . $user_id);

        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => admin_url('admin-ajax.php?action=pausatf_strava_callback'),
            'response_type' => 'code',
            'scope' => 'read,activity:read_all',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback(): void {
        $code = sanitize_text_field($_GET['code'] ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');

        if (empty($code)) {
            wp_die('Authorization failed');
        }

        // Exchange code for token
        $token_data = $this->exchange_code($code);

        if (!$token_data) {
            wp_die('Token exchange failed');
        }

        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_die('Not logged in');
        }

        // Save tokens
        $this->save_user_tokens($user_id, $token_data);

        // Redirect to profile
        wp_redirect(get_edit_profile_url($user_id) . '#strava-connected');
        exit;
    }

    /**
     * Exchange authorization code for access token
     */
    private function exchange_code(string $code): ?array {
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Save user tokens
     */
    private function save_user_tokens(int $user_id, array $token_data): void {
        update_user_meta($user_id, '_pausatf_strava_access_token', $token_data['access_token'] ?? '');
        update_user_meta($user_id, '_pausatf_strava_refresh_token', $token_data['refresh_token'] ?? '');
        update_user_meta($user_id, '_pausatf_strava_expires_at', $token_data['expires_at'] ?? 0);
        update_user_meta($user_id, '_pausatf_strava_athlete_id', $token_data['athlete']['id'] ?? 0);
    }

    /**
     * Get valid access token for user
     */
    public function get_access_token(int $user_id): ?string {
        $access_token = get_user_meta($user_id, '_pausatf_strava_access_token', true);
        $expires_at = (int) get_user_meta($user_id, '_pausatf_strava_expires_at', true);

        if (empty($access_token)) {
            return null;
        }

        // Check if token needs refresh
        if ($expires_at < time()) {
            $access_token = $this->refresh_token($user_id);
        }

        return $access_token;
    }

    /**
     * Refresh access token
     */
    private function refresh_token(int $user_id): ?string {
        $refresh_token = get_user_meta($user_id, '_pausatf_strava_refresh_token', true);

        if (empty($refresh_token)) {
            return null;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $token_data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($token_data['access_token'])) {
            $this->save_user_tokens($user_id, $token_data);
            return $token_data['access_token'];
        }

        return null;
    }

    /**
     * Get athlete's activities
     *
     * @param int $user_id WordPress user ID
     * @param array $params Query parameters
     * @return array Activities
     */
    public function get_activities(int $user_id, array $params = []): array {
        $token = $this->get_access_token($user_id);
        if (!$token) {
            return [];
        }

        $defaults = [
            'per_page' => 30,
            'page' => 1,
        ];

        $params = array_merge($defaults, $params);

        $response = wp_remote_get(
            self::API_BASE . '/athlete/activities?' . http_build_query($params),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        return json_decode(wp_remote_retrieve_body($response), true) ?: [];
    }

    /**
     * Get specific activity
     */
    public function get_activity(int $user_id, int $activity_id): ?array {
        $token = $this->get_access_token($user_id);
        if (!$token) {
            return null;
        }

        $response = wp_remote_get(
            self::API_BASE . "/activities/{$activity_id}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Find matching activity for a race result
     *
     * @param int $user_id WordPress user ID
     * @param string $race_date Race date (Y-m-d)
     * @param string $race_name Race name for matching
     * @return array|null Matching activity
     */
    public function find_matching_activity(int $user_id, string $race_date, string $race_name = ''): ?array {
        $start_date = strtotime($race_date);
        $end_date = strtotime($race_date . ' +1 day');

        $activities = $this->get_activities($user_id, [
            'after' => $start_date,
            'before' => $end_date,
        ]);

        // Filter to race activities
        $races = array_filter($activities, function ($activity) {
            return ($activity['type'] ?? '') === 'Run' &&
                   ($activity['workout_type'] ?? 0) === 1; // Race
        });

        if (empty($races)) {
            // Fall back to any run on that date
            $races = array_filter($activities, function ($activity) {
                return ($activity['type'] ?? '') === 'Run';
            });
        }

        return !empty($races) ? reset($races) : null;
    }

    /**
     * Link result to Strava activity
     *
     * @param int $result_id Result ID
     * @param int $activity_id Strava activity ID
     * @return bool Success
     */
    public function link_result_to_activity(int $result_id, int $activity_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $result_id
        ), ARRAY_A);

        if (!$result) {
            return false;
        }

        $raw_data = json_decode($result['raw_data'] ?? '{}', true);
        $raw_data['strava_activity_id'] = $activity_id;

        return (bool) $wpdb->update(
            $table,
            ['raw_data' => json_encode($raw_data)],
            ['id' => $result_id]
        );
    }

    /**
     * Get activity stats for display
     */
    public function get_activity_stats(array $activity): array {
        return [
            'distance' => round(($activity['distance'] ?? 0) / 1609.34, 2), // Convert to miles
            'elapsed_time' => $activity['elapsed_time'] ?? 0,
            'moving_time' => $activity['moving_time'] ?? 0,
            'elevation_gain' => round(($activity['total_elevation_gain'] ?? 0) * 3.281), // Convert to feet
            'average_speed' => $activity['average_speed'] ?? 0,
            'max_speed' => $activity['max_speed'] ?? 0,
            'average_heartrate' => $activity['average_heartrate'] ?? null,
            'max_heartrate' => $activity['max_heartrate'] ?? null,
            'kudos_count' => $activity['kudos_count'] ?? 0,
            'strava_url' => "https://www.strava.com/activities/{$activity['id']}",
        ];
    }

    /**
     * Check if user has Strava connected
     */
    public function is_connected(int $user_id): bool {
        $token = get_user_meta($user_id, '_pausatf_strava_access_token', true);
        return !empty($token);
    }

    /**
     * Disconnect Strava
     */
    public function disconnect(int $user_id): void {
        delete_user_meta($user_id, '_pausatf_strava_access_token');
        delete_user_meta($user_id, '_pausatf_strava_refresh_token');
        delete_user_meta($user_id, '_pausatf_strava_expires_at');
        delete_user_meta($user_id, '_pausatf_strava_athlete_id');
    }

    /**
     * Register settings
     */
    public static function register_settings(): void {
        register_setting('pausatf_results', 'pausatf_strava_client_id');
        register_setting('pausatf_results', 'pausatf_strava_client_secret');
    }
}

/**
 * Garmin Connect sync (similar structure)
 */
class GarminSync {
    private const API_BASE = 'https://connect.garmin.com/modern/proxy';

    private string $consumer_key;
    private string $consumer_secret;

    public function __construct() {
        $this->consumer_key = get_option('pausatf_garmin_consumer_key', '');
        $this->consumer_secret = get_option('pausatf_garmin_consumer_secret', '');
    }

    public function is_configured(): bool {
        return !empty($this->consumer_key) && !empty($this->consumer_secret);
    }

    public function is_connected(int $user_id): bool {
        $token = get_user_meta($user_id, '_pausatf_garmin_access_token', true);
        return !empty($token);
    }

    /**
     * Get activities from Garmin Connect
     */
    public function get_activities(int $user_id, array $params = []): array {
        // Garmin uses OAuth 1.0a - implementation simplified for brevity
        return [];
    }

    /**
     * Register settings
     */
    public static function register_settings(): void {
        register_setting('pausatf_results', 'pausatf_garmin_consumer_key');
        register_setting('pausatf_results', 'pausatf_garmin_consumer_secret');
    }
}

add_action('admin_init', [StravaSync::class, 'register_settings']);
add_action('admin_init', [GarminSync::class, 'register_settings']);
