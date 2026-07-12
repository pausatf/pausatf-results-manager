<?php
/**
 * Results Repository - Handles all database storage operations
 *
 * This class provides a clean separation between parsing logic and storage,
 * following the Repository pattern. All database operations for results
 * should go through this class.
 *
 * @package PAUSATF\Results\Storage
 * @since 2.3.0
 */

declare(strict_types=1);

namespace PAUSATF\Results\Storage;

use PAUSATF\Results\Parsers\ParsedResults;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for result data storage operations
 */
class ResultsRepository {
    private string $results_table;
    private string $events_table;
    private string $imports_table;

    public function __construct() {
        global $wpdb;
        $this->results_table = $wpdb->prefix . 'pausatf_results';
        $this->events_table = $wpdb->posts;
        $this->imports_table = $wpdb->prefix . 'pausatf_imports';
    }

    /**
     * Store an event from parsed results
     *
     * @param ParsedResults $parsed The parsed results data
     * @param array $metadata Additional metadata (source_url, source_file, etc.)
     * @return int|\WP_Error Event ID or error
     */
    public function store_event(ParsedResults $parsed, array $metadata = []): int|\WP_Error {
        // Check for existing event
        $existing_id = $this->find_existing_event($parsed, $metadata);

        $post_data = [
            'post_type' => 'pausatf_event',
            'post_title' => $this->sanitize_event_name($parsed->event_name),
            'post_status' => 'publish',
            'meta_input' => $this->prepare_event_meta($parsed, $metadata),
        ];

        if ($existing_id) {
            $post_data['ID'] = $existing_id;
            $event_id = wp_update_post($post_data, true);

            /**
             * Fires after an existing event is updated
             *
             * @param int $event_id The event post ID
             * @param ParsedResults $parsed The parsed results
             * @param array $metadata Import metadata
             */
            do_action('pausatf_event_updated', $event_id, $parsed, $metadata);
        } else {
            $event_id = wp_insert_post($post_data, true);

            /**
             * Fires after a new event is created
             *
             * @param int $event_id The event post ID
             * @param ParsedResults $parsed The parsed results
             * @param array $metadata Import metadata
             */
            do_action('pausatf_event_created', $event_id, $parsed, $metadata);
        }

        if (!is_wp_error($event_id)) {
            $this->set_event_taxonomies($event_id, $parsed, $metadata);
        }

        return $event_id;
    }

    /**
     * Store individual results for an event
     *
     * @param int $event_id The event post ID
     * @param array $results Array of result data
     * @param bool $replace Whether to replace existing results
     * @return StorageResult
     */
    public function store_results(int $event_id, array $results, bool $replace = true): StorageResult {
        global $wpdb;

        $storage_result = new StorageResult();
        $storage_result->event_id = $event_id;
        $storage_result->total_records = count($results);

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            if ($replace) {
                $deleted = $wpdb->delete($this->results_table, ['event_id' => $event_id]);
                $storage_result->deleted_records = $deleted ?: 0;
            }

            foreach ($results as $index => $result) {
                $insert_result = $this->insert_single_result($event_id, $result);

                if ($insert_result['success']) {
                    $storage_result->inserted_records++;
                    $storage_result->result_ids[] = $insert_result['id'];
                } else {
                    $storage_result->failed_records++;
                    $storage_result->add_error($index, $insert_result['error']);
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            // Update event meta with result count
            update_post_meta($event_id, '_pausatf_result_count', $storage_result->inserted_records);

            /**
             * Fires after results are stored
             *
             * @param int $event_id The event post ID
             * @param StorageResult $storage_result The storage result details
             */
            do_action('pausatf_results_stored', $event_id, $storage_result);

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            $storage_result->add_error(-1, $e->getMessage());
        }

        return $storage_result;
    }

    /**
     * Insert a single result record
     */
    private function insert_single_result(int $event_id, array $result): array {
        global $wpdb;

        // Validate required fields
        if (empty($result['athlete_name'])) {
            return ['success' => false, 'error' => 'Missing athlete name'];
        }

        // Prepare data with proper typing and sanitization
        $data = [
            'event_id' => $event_id,
            'athlete_name' => $this->sanitize_athlete_name($result['athlete_name'] ?? ''),
            'athlete_age' => $this->validate_age($result['athlete_age'] ?? null),
            'sex' => $this->validate_sex($result['sex'] ?? null),
            'place' => $this->validate_place($result['place'] ?? null),
            'division' => sanitize_text_field($result['division'] ?? ''),
            'division_place' => $this->validate_place($result['division_place'] ?? null),
            'time_seconds' => $this->validate_time_seconds($result['time_seconds'] ?? null),
            'time_display' => sanitize_text_field($result['time_display'] ?? ''),
            'points' => $this->validate_decimal($result['points'] ?? null),
            'payout' => $this->validate_decimal($result['payout'] ?? null),
            'club' => sanitize_text_field($result['club'] ?? ''),
            'bib' => sanitize_text_field($result['bib'] ?? ''),
            'pace' => sanitize_text_field($result['pace'] ?? ''),
            'raw_data' => wp_json_encode($result),
            'created_at' => current_time('mysql'),
        ];

        // Try to link to existing athlete
        $athlete_id = $this->find_athlete($result);
        if ($athlete_id) {
            $data['athlete_id'] = $athlete_id;
        }

        // Define format types for wpdb
        $formats = [
            '%d', // event_id
            '%s', // athlete_name
            '%d', // athlete_age
            '%s', // sex
            '%d', // place
            '%s', // division
            '%d', // division_place
            '%d', // time_seconds
            '%s', // time_display
            '%f', // points
            '%f', // payout
            '%s', // club
            '%s', // bib
            '%s', // pace
            '%s', // raw_data
            '%s', // created_at
        ];

        if (isset($data['athlete_id'])) {
            $formats[] = '%d';
        }

        $inserted = $wpdb->insert($this->results_table, $data, $formats);

        if ($inserted === false) {
            return [
                'success' => false,
                'error' => $wpdb->last_error ?: 'Database insert failed',
            ];
        }

        return [
            'success' => true,
            'id' => $wpdb->insert_id,
        ];
    }

    /**
     * Find existing event by URL or name/date
     */
    private function find_existing_event(ParsedResults $parsed, array $metadata): ?int {
        global $wpdb;

        // Check by source URL first (most reliable)
        if (!empty($metadata['source_url'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_pausatf_source_url' AND meta_value = %s
                 LIMIT 1",
                $metadata['source_url']
            ));

            if ($existing) {
                return (int) $existing;
            }
        }

        // Check by source file
        if (!empty($metadata['source_file'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_pausatf_source_file' AND meta_value = %s
                 LIMIT 1",
                $metadata['source_file']
            ));

            if ($existing) {
                return (int) $existing;
            }
        }

        // Check by name and date (fallback)
        if (!empty($parsed->event_name) && !empty($parsed->event_date)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
                 WHERE p.post_type = 'pausatf_event'
                 AND p.post_title = %s
                 AND m.meta_key = '_pausatf_event_date'
                 AND m.meta_value = %s
                 LIMIT 1",
                $parsed->event_name,
                $parsed->event_date
            ));

            if ($existing) {
                return (int) $existing;
            }
        }

        return null;
    }

    /**
     * Prepare event meta data
     */
    private function prepare_event_meta(ParsedResults $parsed, array $metadata): array {
        return [
            '_pausatf_event_date' => $parsed->event_date,
            '_pausatf_event_location' => $parsed->event_location,
            '_pausatf_event_type' => $parsed->event_type,
            '_pausatf_source_url' => $metadata['source_url'] ?? '',
            '_pausatf_source_file' => $metadata['source_file'] ?? '',
            '_pausatf_parser_used' => $metadata['parser'] ?? '',
            '_pausatf_result_count' => count($parsed->results),
            '_pausatf_divisions' => $parsed->divisions,
            '_pausatf_imported_at' => current_time('mysql'),
            '_pausatf_import_warnings' => $parsed->warnings,
            '_pausatf_import_metadata' => $parsed->metadata,
        ];
    }

    /**
     * Set event taxonomies based on parsed data
     */
    private function set_event_taxonomies(int $event_id, ParsedResults $parsed, array $metadata): void {
        // Set event type
        if (!empty($parsed->event_type)) {
            wp_set_object_terms($event_id, $parsed->event_type, 'pausatf_event_type');
        }

        // Set year/season
        if (!empty($parsed->event_date)) {
            $year = date('Y', strtotime($parsed->event_date));
            wp_set_object_terms($event_id, $year, 'pausatf_season');
        }

        // Set divisions
        if (!empty($parsed->divisions)) {
            wp_set_object_terms($event_id, $parsed->divisions, 'pausatf_division');
        }

        /**
         * Allows adding custom taxonomies after event is saved
         *
         * @param int $event_id The event post ID
         * @param ParsedResults $parsed The parsed results
         * @param array $metadata Import metadata
         */
        do_action('pausatf_set_event_taxonomies', $event_id, $parsed, $metadata);
    }

    /**
     * Find athlete by name (basic matching)
     */
    private function find_athlete(array $result): ?int {
        $name = $result['athlete_name'] ?? '';
        if (empty($name)) {
            return null;
        }

        // Try exact match first
        $athlete = get_page_by_title($name, OBJECT, 'pausatf_athlete');
        if ($athlete) {
            return $athlete->ID;
        }

        /**
         * Filter to allow custom athlete matching logic
         *
         * @param int|null $athlete_id The matched athlete ID or null
         * @param array $result The result data being imported
         */
        return apply_filters('pausatf_find_athlete', null, $result);
    }

    /**
     * Get results for an event
     *
     * @param int $event_id The event post ID
     * @param array $args Query arguments (orderby, order, division, sex, limit, offset)
     * @return array Array of result objects
     */
    public function get_results(int $event_id, array $args = []): array {
        global $wpdb;

        $defaults = [
            'orderby' => 'place',
            'order' => 'ASC',
            'division' => '',
            'sex' => '',
            'limit' => 0,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM {$this->results_table} WHERE event_id = %d";
        $params = [$event_id];

        if (!empty($args['division'])) {
            $sql .= " AND division = %s";
            $params[] = $args['division'];
        }

        if (!empty($args['sex'])) {
            $sql .= " AND sex = %s";
            $params[] = $args['sex'];
        }

        // Whitelist orderby columns
        $allowed_orderby = ['place', 'division_place', 'time_seconds', 'points', 'athlete_name'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'place';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$orderby} {$order}";

        if ($args['limit'] > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $params[] = $args['limit'];
            $params[] = $args['offset'];
        }

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    /**
     * Delete results for an event
     */
    public function delete_results(int $event_id): int {
        global $wpdb;

        $deleted = $wpdb->delete($this->results_table, ['event_id' => $event_id]);

        /**
         * Fires after results are deleted
         *
         * @param int $event_id The event post ID
         * @param int $deleted Number of deleted records
         */
        do_action('pausatf_results_deleted', $event_id, $deleted);

        return $deleted ?: 0;
    }

    /**
     * Log an import operation
     */
    public function log_import(array $data): int {
        global $wpdb;

        $wpdb->insert(
            $this->imports_table,
            [
                'source_url' => $data['source_url'] ?? '',
                'source_file' => $data['source_file'] ?? '',
                'parser' => $data['parser'] ?? '',
                'status' => $data['status'] ?? 'processing',
                'event_id' => $data['event_id'] ?? null,
                'records_imported' => $data['records_imported'] ?? 0,
                'error_message' => $data['error_message'] ?? null,
                'created_at' => current_time('mysql'),
            ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Update import log
     */
    public function update_import_log(int $import_id, array $data): void {
        global $wpdb;

        $data['updated_at'] = current_time('mysql');

        $wpdb->update($this->imports_table, $data, ['id' => $import_id]);
    }

    /**
     * Get import history
     */
    public function get_import_history(int $limit = 50, int $offset = 0): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->imports_table}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
    }

    // ===== Validation/Sanitization Helpers =====

    private function sanitize_event_name(string $name): string {
        $name = trim($name);
        if (empty($name)) {
            return 'Untitled Event';
        }
        return sanitize_text_field($name);
    }

    private function sanitize_athlete_name(string $name): string {
        $name = trim($name);
        // Remove excess whitespace
        $name = preg_replace('/\s+/', ' ', $name);
        // Basic title case for names
        return sanitize_text_field($name);
    }

    private function validate_age(?int $age): ?int {
        if ($age === null) {
            return null;
        }
        if ($age < 1 || $age > 120) {
            return null;
        }
        return $age;
    }

    private function validate_sex(?string $sex): ?string {
        if ($sex === null) {
            return null;
        }
        $sex = strtoupper(substr(trim($sex), 0, 1));
        return in_array($sex, ['M', 'F'], true) ? $sex : null;
    }

    private function validate_place(?int $place): ?int {
        if ($place === null) {
            return null;
        }
        return $place > 0 ? $place : null;
    }

    private function validate_time_seconds(?int $seconds): ?int {
        if ($seconds === null) {
            return null;
        }
        // Max reasonable time: 72 hours
        return ($seconds > 0 && $seconds < 259200) ? $seconds : null;
    }

    private function validate_decimal(?float $value): ?float {
        if ($value === null) {
            return null;
        }
        return $value >= 0 ? round($value, 2) : null;
    }
}

/**
 * Storage result data structure
 */
class StorageResult {
    public int $event_id = 0;
    public int $total_records = 0;
    public int $inserted_records = 0;
    public int $failed_records = 0;
    public int $deleted_records = 0;
    public array $result_ids = [];
    public array $errors = [];

    public function add_error(int $index, string $message): void {
        $this->errors[] = [
            'index' => $index,
            'message' => $message,
        ];
    }

    public function has_errors(): bool {
        return !empty($this->errors);
    }

    public function is_success(): bool {
        return $this->inserted_records > 0 && $this->failed_records === 0;
    }

    public function to_array(): array {
        return [
            'event_id' => $this->event_id,
            'total_records' => $this->total_records,
            'inserted_records' => $this->inserted_records,
            'failed_records' => $this->failed_records,
            'deleted_records' => $this->deleted_records,
            'result_ids' => $this->result_ids,
            'errors' => $this->errors,
            'success' => $this->is_success(),
        ];
    }
}
