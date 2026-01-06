<?php
/**
 * USATF Membership Verification
 *
 * Validates USATF membership numbers and syncs member data
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USATF membership verification and lookup
 */
class USATFVerification {
    private const API_BASE = 'https://www.usatf.org/api/v1';

    private string $api_key;
    private string $api_secret;

    /**
     * Membership levels
     */
    private const MEMBERSHIP_TYPES = [
        'adult' => 'Adult Membership',
        'youth' => 'Youth Membership',
        'masters' => 'Masters Membership',
        'club' => 'Club Membership',
        'lifetime' => 'Lifetime Membership',
    ];

    /**
     * Pacific Association code
     */
    private const PACIFIC_ASSOCIATION_CODE = 'PA';

    public function __construct() {
        $this->api_key = get_option('pausatf_usatf_api_key', '');
        $this->api_secret = get_option('pausatf_usatf_api_secret', '');
    }

    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return !empty($this->api_key) && !empty($this->api_secret);
    }

    /**
     * Verify membership number
     *
     * @param string $membership_number USATF membership number
     * @return array Verification result
     */
    public function verify_membership(string $membership_number): array {
        // Clean membership number
        $membership_number = preg_replace('/[^0-9]/', '', $membership_number);

        if (strlen($membership_number) < 8) {
            return [
                'valid' => false,
                'error' => 'Invalid membership number format',
            ];
        }

        // Check cache first
        $cached = $this->get_cached_verification($membership_number);
        if ($cached !== null) {
            return $cached;
        }

        // API verification
        $response = $this->api_request('members/verify', [
            'membership_number' => $membership_number,
        ]);

        if (!$response) {
            // Fallback to local validation if API unavailable
            return $this->local_verify($membership_number);
        }

        $result = [
            'valid' => $response['valid'] ?? false,
            'membership_number' => $membership_number,
            'member_name' => $response['name'] ?? null,
            'membership_type' => $response['type'] ?? null,
            'expiration_date' => $response['expiration'] ?? null,
            'association' => $response['association'] ?? null,
            'is_pacific' => ($response['association'] ?? '') === self::PACIFIC_ASSOCIATION_CODE,
            'is_current' => $this->is_membership_current($response['expiration'] ?? null),
            'verified_at' => current_time('mysql'),
        ];

        // Cache the result
        $this->cache_verification($membership_number, $result);

        return $result;
    }

    /**
     * Check if membership is current
     */
    private function is_membership_current(?string $expiration): bool {
        if (!$expiration) {
            return false;
        }

        $exp_date = strtotime($expiration);
        return $exp_date > time();
    }

    /**
     * Local verification (format check only)
     */
    private function local_verify(string $membership_number): array {
        // USATF numbers are typically 8-10 digits
        $valid = preg_match('/^\d{8,10}$/', $membership_number);

        return [
            'valid' => (bool) $valid,
            'membership_number' => $membership_number,
            'verification_method' => 'local',
            'message' => $valid ? 'Format valid, full verification unavailable' : 'Invalid format',
        ];
    }

    /**
     * Lookup member by name
     *
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param array $filters Additional filters
     * @return array Matching members
     */
    public function lookup_member(string $first_name, string $last_name, array $filters = []): array {
        $params = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'association' => self::PACIFIC_ASSOCIATION_CODE,
        ];

        if (!empty($filters['city'])) {
            $params['city'] = $filters['city'];
        }
        if (!empty($filters['birth_year'])) {
            $params['birth_year'] = $filters['birth_year'];
        }

        $response = $this->api_request('members/search', $params);

        return $response['members'] ?? [];
    }

    /**
     * Get member details
     *
     * @param string $membership_number USATF membership number
     * @return array|null Member details
     */
    public function get_member(string $membership_number): ?array {
        $response = $this->api_request('members/' . $membership_number);

        return $response['member'] ?? null;
    }

    /**
     * Sync member data to athlete post
     *
     * @param int $athlete_id Athlete post ID
     * @param string $membership_number USATF membership number
     * @return array Sync result
     */
    public function sync_athlete_membership(int $athlete_id, string $membership_number): array {
        $verification = $this->verify_membership($membership_number);

        if (!$verification['valid']) {
            return [
                'success' => false,
                'error' => $verification['error'] ?? 'Membership verification failed',
            ];
        }

        // Update athlete meta
        update_post_meta($athlete_id, '_pausatf_usatf_number', $membership_number);
        update_post_meta($athlete_id, '_pausatf_usatf_verified', true);
        update_post_meta($athlete_id, '_pausatf_usatf_verified_at', current_time('mysql'));
        update_post_meta($athlete_id, '_pausatf_usatf_expiration', $verification['expiration_date'] ?? '');
        update_post_meta($athlete_id, '_pausatf_usatf_type', $verification['membership_type'] ?? '');
        update_post_meta($athlete_id, '_pausatf_is_pacific_member', $verification['is_pacific'] ?? false);

        return [
            'success' => true,
            'verification' => $verification,
        ];
    }

    /**
     * Get athletes with expiring memberships
     *
     * @param int $days_ahead Number of days to look ahead
     * @return array Athletes with expiring memberships
     */
    public function get_expiring_memberships(int $days_ahead = 30): array {
        $cutoff_date = date('Y-m-d', strtotime("+{$days_ahead} days"));

        $athletes = get_posts([
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_pausatf_usatf_verified',
                    'value' => '1',
                ],
                [
                    'key' => '_pausatf_usatf_expiration',
                    'value' => $cutoff_date,
                    'compare' => '<=',
                    'type' => 'DATE',
                ],
                [
                    'key' => '_pausatf_usatf_expiration',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE',
                ],
            ],
        ]);

        return array_map(function ($athlete) {
            return [
                'athlete_id' => $athlete->ID,
                'name' => $athlete->post_title,
                'usatf_number' => get_post_meta($athlete->ID, '_pausatf_usatf_number', true),
                'expiration' => get_post_meta($athlete->ID, '_pausatf_usatf_expiration', true),
            ];
        }, $athletes);
    }

    /**
     * Batch verify Pacific Association members
     *
     * @param array $membership_numbers Array of membership numbers
     * @return array Verification results
     */
    public function batch_verify(array $membership_numbers): array {
        $results = [];

        foreach ($membership_numbers as $number) {
            $results[$number] = $this->verify_membership($number);
        }

        return $results;
    }

    /**
     * Get cached verification
     */
    private function get_cached_verification(string $membership_number): ?array {
        $cache_key = 'pausatf_usatf_' . md5($membership_number);
        $cached = get_transient($cache_key);

        if ($cached === false) {
            return null;
        }

        return $cached;
    }

    /**
     * Cache verification result
     */
    private function cache_verification(string $membership_number, array $result): void {
        $cache_key = 'pausatf_usatf_' . md5($membership_number);
        // Cache for 24 hours
        set_transient($cache_key, $result, DAY_IN_SECONDS);
    }

    /**
     * Make API request
     */
    private function api_request(string $endpoint, array $params = []): ?array {
        if (!$this->is_configured()) {
            return null;
        }

        $url = self::API_BASE . '/' . $endpoint;

        // Add authentication
        $params['api_key'] = $this->api_key;
        $timestamp = time();
        $params['timestamp'] = $timestamp;
        $params['signature'] = hash_hmac('sha256', $timestamp . $this->api_key, $this->api_secret);

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($params),
        ]);

        if (is_wp_error($response)) {
            error_log('USATF API Error: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Register settings
     */
    public static function register_settings(): void {
        register_setting('pausatf_results', 'pausatf_usatf_api_key');
        register_setting('pausatf_results', 'pausatf_usatf_api_secret');
    }

    /**
     * Get membership type label
     */
    public function get_membership_type_label(string $type): string {
        return self::MEMBERSHIP_TYPES[$type] ?? $type;
    }
}

add_action('admin_init', [USATFVerification::class, 'register_settings']);
