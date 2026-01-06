<?php
/**
 * Association Records Database
 *
 * Tracks official PA-USATF records by event, age group, and division
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

use PAUSATF\Results\Rules\USATFRulesEngine;
use PAUSATF\Results\Rules\USATFAgeDivisions;
use PAUSATF\Results\Rules\USATFRecordCategories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records Database - Manages association records
 */
class RecordsDatabase {
    /**
     * Database table name
     */
    private string $table;

    /**
     * Rules engine instance
     */
    private USATFRulesEngine $rules;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pausatf_records';
        $this->rules = new USATFRulesEngine();
    }

    /**
     * Create records table
     */
    public static function create_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'pausatf_records';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_type varchar(50) NOT NULL DEFAULT 'association',
            event varchar(100) NOT NULL,
            venue_type enum('outdoor','indoor','road','trail','xc') NOT NULL,
            gender enum('M','F') NOT NULL,
            division_code varchar(20) NOT NULL,
            age_group varchar(20) DEFAULT NULL,
            performance decimal(15,4) NOT NULL,
            performance_display varchar(50) NOT NULL,
            athlete_name varchar(255) NOT NULL,
            athlete_id bigint(20) unsigned DEFAULT NULL,
            athlete_age int DEFAULT NULL,
            club varchar(255) DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            competition varchar(255) DEFAULT NULL,
            record_date date NOT NULL,
            wind_reading decimal(5,2) DEFAULT NULL,
            altitude int DEFAULT NULL,
            implement_weight decimal(6,3) DEFAULT NULL,
            verified tinyint(1) DEFAULT 0,
            verified_by bigint(20) unsigned DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            previous_record_id bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            source_result_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_record (record_type, event, venue_type, gender, division_code),
            KEY event_lookup (event, gender, division_code),
            KEY athlete_records (athlete_id),
            KEY date_lookup (record_date),
            KEY verified (verified)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Create pending records table
        $pending_table = $wpdb->prefix . 'pausatf_pending_records';
        $sql_pending = "CREATE TABLE {$pending_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_type varchar(50) NOT NULL,
            event varchar(100) NOT NULL,
            venue_type varchar(20) NOT NULL,
            gender varchar(1) NOT NULL,
            division_code varchar(20) NOT NULL,
            performance decimal(15,4) NOT NULL,
            performance_display varchar(50) NOT NULL,
            athlete_name varchar(255) NOT NULL,
            athlete_id bigint(20) unsigned DEFAULT NULL,
            athlete_age int DEFAULT NULL,
            club varchar(255) DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            competition varchar(255) DEFAULT NULL,
            record_date date NOT NULL,
            wind_reading decimal(5,2) DEFAULT NULL,
            source_result_id bigint(20) unsigned DEFAULT NULL,
            status enum('pending','approved','rejected') DEFAULT 'pending',
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            review_notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY event_lookup (event, gender, division_code)
        ) {$charset_collate};";

        dbDelta($sql_pending);
    }

    /**
     * Get current record
     *
     * @param string $event Event name
     * @param string $gender M or F
     * @param string $division_code Division code (e.g., M40, W50, OPEN)
     * @param string $venue_type outdoor, indoor, road, etc.
     * @param string $record_type association, meet, facility
     * @return array|null Current record
     */
    public function get_record(
        string $event,
        string $gender,
        string $division_code,
        string $venue_type = 'outdoor',
        string $record_type = 'association'
    ): ?array {
        global $wpdb;

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE record_type = %s
               AND event = %s
               AND venue_type = %s
               AND gender = %s
               AND division_code = %s
               AND verified = 1
             LIMIT 1",
            $record_type,
            $event,
            $venue_type,
            $gender,
            $division_code
        ), ARRAY_A);

        return $record ?: null;
    }

    /**
     * Get all records for an event
     *
     * @param string $event Event name
     * @param string $venue_type Venue type
     * @return array Records by division
     */
    public function get_event_records(string $event, string $venue_type = 'outdoor'): array {
        global $wpdb;

        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE event = %s AND venue_type = %s AND verified = 1
             ORDER BY gender, CAST(SUBSTRING(division_code, 2) AS UNSIGNED)",
            $event,
            $venue_type
        ), ARRAY_A);

        // Organize by gender and division
        $organized = ['M' => [], 'F' => []];
        foreach ($records as $record) {
            $organized[$record['gender']][$record['division_code']] = $record;
        }

        return $organized;
    }

    /**
     * Get all records for a division
     *
     * @param string $division_code Division code
     * @param string $venue_type Venue type
     * @return array Records for division
     */
    public function get_division_records(string $division_code, string $venue_type = 'outdoor'): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE division_code = %s AND venue_type = %s AND verified = 1
             ORDER BY event",
            $division_code,
            $venue_type
        ), ARRAY_A);
    }

    /**
     * Get athlete's records
     *
     * @param int $athlete_id Athlete post ID
     * @return array Athlete's records
     */
    public function get_athlete_records(int $athlete_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE athlete_id = %d AND verified = 1
             ORDER BY record_date DESC",
            $athlete_id
        ), ARRAY_A);
    }

    /**
     * Check if performance is a record
     *
     * @param string $event Event name
     * @param float $performance Performance value
     * @param string $gender M or F
     * @param int $age Athlete age
     * @param string $venue_type Venue type
     * @param float|null $wind Wind reading (if applicable)
     * @return array Record check result
     */
    public function check_record(
        string $event,
        float $performance,
        string $gender,
        int $age,
        string $venue_type = 'outdoor',
        ?float $wind = null
    ): array {
        // Get applicable division
        $division = $this->rules->get_division_for_age($age, $gender);
        if (!$division) {
            return ['is_record' => false, 'reason' => 'Invalid age/gender'];
        }

        $division_code = $division['code'];

        // Check wind for wind-affected events
        if (USATFRecordCategories::requires_wind_reading($event)) {
            if ($wind === null) {
                return ['is_record' => false, 'reason' => 'Wind reading required'];
            }
            if ($wind > 2.0) {
                return ['is_record' => false, 'reason' => 'Wind-assisted (> 2.0 m/s)'];
            }
        }

        // Get current record
        $current = $this->get_record($event, $gender, $division_code, $venue_type);

        // Determine if better
        $is_better = $this->is_better_performance($event, $performance, $current['performance'] ?? null);

        $result = [
            'is_record' => $is_better || !$current,
            'event' => $event,
            'division_code' => $division_code,
            'new_performance' => $performance,
            'current_record' => $current,
        ];

        if ($is_better && $current) {
            $result['improvement'] = abs($performance - $current['performance']);
            $result['previous_holder'] = $current['athlete_name'];
        }

        return $result;
    }

    /**
     * Compare performances
     */
    private function is_better_performance(string $event, float $new, ?float $current): bool {
        if ($current === null) {
            return true;
        }

        // Field events (higher is better)
        $field_events = ['High Jump', 'Pole Vault', 'Long Jump', 'Triple Jump',
                         'Shot Put', 'Discus', 'Hammer', 'Javelin', 'Weight Throw'];

        if (in_array($event, $field_events)) {
            return $new > $current;
        }

        // Track/road (lower is better)
        return $new < $current;
    }

    /**
     * Submit new record for verification
     *
     * @param array $data Record data
     * @return int|false Pending record ID or false
     */
    public function submit_record(array $data): int|false {
        global $wpdb;

        $required = ['event', 'venue_type', 'gender', 'division_code',
                     'performance', 'performance_display', 'athlete_name', 'record_date'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        // Check if it beats current record
        $check = $this->check_record(
            $data['event'],
            $data['performance'],
            $data['gender'],
            $data['athlete_age'] ?? 0,
            $data['venue_type'],
            $data['wind_reading'] ?? null
        );

        if (!$check['is_record']) {
            return false;
        }

        $pending_table = $wpdb->prefix . 'pausatf_pending_records';

        $insert_data = [
            'record_type' => $data['record_type'] ?? 'association',
            'event' => $data['event'],
            'venue_type' => $data['venue_type'],
            'gender' => $data['gender'],
            'division_code' => $data['division_code'],
            'performance' => $data['performance'],
            'performance_display' => $data['performance_display'],
            'athlete_name' => $data['athlete_name'],
            'athlete_id' => $data['athlete_id'] ?? null,
            'athlete_age' => $data['athlete_age'] ?? null,
            'club' => $data['club'] ?? null,
            'location' => $data['location'] ?? null,
            'competition' => $data['competition'] ?? null,
            'record_date' => $data['record_date'],
            'wind_reading' => $data['wind_reading'] ?? null,
            'source_result_id' => $data['source_result_id'] ?? null,
            'status' => 'pending',
        ];

        $result = $wpdb->insert($pending_table, $insert_data);

        if ($result) {
            $this->notify_record_submission($wpdb->insert_id, $insert_data);
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Approve pending record
     *
     * @param int $pending_id Pending record ID
     * @param int $reviewer_id User ID of reviewer
     * @param string|null $notes Review notes
     * @return bool Success
     */
    public function approve_record(int $pending_id, int $reviewer_id, ?string $notes = null): bool {
        global $wpdb;

        $pending_table = $wpdb->prefix . 'pausatf_pending_records';

        // Get pending record
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pending_table} WHERE id = %d AND status = 'pending'",
            $pending_id
        ), ARRAY_A);

        if (!$pending) {
            return false;
        }

        // Get current record (will become previous)
        $current = $this->get_record(
            $pending['event'],
            $pending['gender'],
            $pending['division_code'],
            $pending['venue_type'],
            $pending['record_type']
        );

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // If updating existing record, archive the old one
            $previous_id = null;
            if ($current) {
                // Mark old record as superseded
                $wpdb->update(
                    $this->table,
                    ['notes' => 'Superseded by record ID: ' . ($wpdb->insert_id + 1)],
                    ['id' => $current['id']]
                );
                $previous_id = $current['id'];

                // Delete old record (or keep for history - implementation choice)
                $wpdb->delete($this->table, ['id' => $current['id']]);
            }

            // Insert new record
            $record_data = [
                'record_type' => $pending['record_type'],
                'event' => $pending['event'],
                'venue_type' => $pending['venue_type'],
                'gender' => $pending['gender'],
                'division_code' => $pending['division_code'],
                'performance' => $pending['performance'],
                'performance_display' => $pending['performance_display'],
                'athlete_name' => $pending['athlete_name'],
                'athlete_id' => $pending['athlete_id'],
                'athlete_age' => $pending['athlete_age'],
                'club' => $pending['club'],
                'location' => $pending['location'],
                'competition' => $pending['competition'],
                'record_date' => $pending['record_date'],
                'wind_reading' => $pending['wind_reading'],
                'verified' => 1,
                'verified_by' => $reviewer_id,
                'verified_at' => current_time('mysql'),
                'previous_record_id' => $previous_id,
                'notes' => $notes,
                'source_result_id' => $pending['source_result_id'],
            ];

            $wpdb->insert($this->table, $record_data);
            $new_record_id = $wpdb->insert_id;

            // Update pending status
            $wpdb->update(
                $pending_table,
                [
                    'status' => 'approved',
                    'reviewed_by' => $reviewer_id,
                    'reviewed_at' => current_time('mysql'),
                    'review_notes' => $notes,
                ],
                ['id' => $pending_id]
            );

            $wpdb->query('COMMIT');

            // Notify about new record
            $this->notify_new_record($new_record_id, $record_data);

            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Record approval failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reject pending record
     */
    public function reject_record(int $pending_id, int $reviewer_id, string $reason): bool {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'pausatf_pending_records';

        return (bool) $wpdb->update(
            $pending_table,
            [
                'status' => 'rejected',
                'reviewed_by' => $reviewer_id,
                'reviewed_at' => current_time('mysql'),
                'review_notes' => $reason,
            ],
            ['id' => $pending_id]
        );
    }

    /**
     * Get pending records
     */
    public function get_pending_records(): array {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'pausatf_pending_records';

        return $wpdb->get_results(
            "SELECT * FROM {$pending_table} WHERE status = 'pending' ORDER BY submitted_at ASC",
            ARRAY_A
        );
    }

    /**
     * Auto-detect records from imported results
     *
     * @param int $event_id Event post ID
     * @return array Potential records found
     */
    public function scan_for_records(int $event_id): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'pausatf_results';

        // Get event details
        $event_date = get_post_meta($event_id, '_pausatf_event_date', true);
        $event_location = get_post_meta($event_id, '_pausatf_event_location', true);
        $event_name = get_the_title($event_id);

        // Determine venue type from event taxonomy
        $event_types = wp_get_object_terms($event_id, 'pausatf_event_type', ['fields' => 'names']);
        $venue_type = $this->determine_venue_type($event_types);

        // Get all results for event
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$results_table} WHERE event_id = %d",
            $event_id
        ), ARRAY_A);

        $potential_records = [];

        foreach ($results as $result) {
            if (!$result['time_seconds'] || !$result['athlete_age']) {
                continue;
            }

            // Determine gender from division or name
            $gender = $this->infer_gender($result);
            if (!$gender) continue;

            // Get the specific event (distance) from event post
            $event_distance = $this->get_event_distance($event_id);
            if (!$event_distance) continue;

            $check = $this->check_record(
                $event_distance,
                $result['time_seconds'],
                $gender,
                $result['athlete_age'],
                $venue_type
            );

            if ($check['is_record']) {
                $potential_records[] = [
                    'result' => $result,
                    'check' => $check,
                    'event_distance' => $event_distance,
                    'venue_type' => $venue_type,
                    'competition' => $event_name,
                    'location' => $event_location,
                    'date' => $event_date,
                ];
            }
        }

        return $potential_records;
    }

    /**
     * Determine venue type from event types
     */
    private function determine_venue_type(array $event_types): string {
        foreach ($event_types as $type) {
            $type_lower = strtolower($type);
            if (strpos($type_lower, 'indoor') !== false) return 'indoor';
            if (strpos($type_lower, 'cross country') !== false || strpos($type_lower, 'xc') !== false) return 'xc';
            if (strpos($type_lower, 'road') !== false) return 'road';
            if (strpos($type_lower, 'trail') !== false || strpos($type_lower, 'ultra') !== false) return 'trail';
        }
        return 'outdoor';
    }

    /**
     * Infer gender from result data
     */
    private function infer_gender(array $result): ?string {
        $division = $result['division'] ?? '';

        if (preg_match('/^[WF]/i', $division) || strpos(strtolower($division), 'women') !== false) {
            return 'F';
        }
        if (preg_match('/^M/i', $division) || strpos(strtolower($division), 'men') !== false) {
            return 'M';
        }

        return null;
    }

    /**
     * Get event distance from event post
     */
    private function get_event_distance(int $event_id): ?string {
        $title = strtolower(get_the_title($event_id));

        // Common distance patterns
        $patterns = [
            '5k' => '5K',
            '10k' => '10K',
            'half marathon' => 'Half Marathon',
            'marathon' => 'Marathon',
            '100m' => '100m',
            '200m' => '200m',
            '400m' => '400m',
            '800m' => '800m',
            '1500m' => '1500m',
            '1 mile' => '1 Mile',
            '5000m' => '5000m',
            '10000m' => '10000m',
        ];

        foreach ($patterns as $pattern => $distance) {
            if (strpos($title, $pattern) !== false) {
                return $distance;
            }
        }

        return get_post_meta($event_id, '_pausatf_event_distance', true) ?: null;
    }

    /**
     * Notify admins of record submission
     */
    private function notify_record_submission(int $pending_id, array $data): void {
        $admin_email = get_option('admin_email');
        $subject = sprintf('[PA-USATF] New Record Submission: %s %s', $data['event'], $data['division_code']);

        $message = sprintf(
            "A new record has been submitted for review:\n\n" .
            "Event: %s\n" .
            "Division: %s\n" .
            "Performance: %s\n" .
            "Athlete: %s\n" .
            "Date: %s\n\n" .
            "Review at: %s",
            $data['event'],
            $data['division_code'],
            $data['performance_display'],
            $data['athlete_name'],
            $data['record_date'],
            admin_url('admin.php?page=pausatf-records&action=review&id=' . $pending_id)
        );

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Notify about new verified record
     */
    private function notify_new_record(int $record_id, array $data): void {
        // Could post to social media, send newsletter, etc.
        do_action('pausatf_new_record', $record_id, $data);
    }

    /**
     * Get record history for event/division
     */
    public function get_record_history(
        string $event,
        string $gender,
        string $division_code,
        string $venue_type = 'outdoor'
    ): array {
        global $wpdb;

        // Get current and all previous records
        $records = [$this->get_record($event, $gender, $division_code, $venue_type)];

        $current = $records[0];
        while ($current && !empty($current['previous_record_id'])) {
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $current['previous_record_id']
            ), ARRAY_A);

            if ($previous) {
                $records[] = $previous;
                $current = $previous;
            } else {
                break;
            }
        }

        return array_filter($records);
    }

    /**
     * Export records to CSV
     */
    public function export_records(array $filters = []): string {
        global $wpdb;

        $where = ['verified = 1'];
        $params = [];

        if (!empty($filters['event'])) {
            $where[] = 'event = %s';
            $params[] = $filters['event'];
        }
        if (!empty($filters['gender'])) {
            $where[] = 'gender = %s';
            $params[] = $filters['gender'];
        }
        if (!empty($filters['venue_type'])) {
            $where[] = 'venue_type = %s';
            $params[] = $filters['venue_type'];
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY event, gender, division_code";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $records = $wpdb->get_results($sql, ARRAY_A);

        // Build CSV
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Event', 'Venue', 'Gender', 'Division', 'Performance', 'Athlete', 'Age', 'Club', 'Location', 'Date', 'Wind']);

        foreach ($records as $record) {
            fputcsv($output, [
                $record['event'],
                $record['venue_type'],
                $record['gender'],
                $record['division_code'],
                $record['performance_display'],
                $record['athlete_name'],
                $record['athlete_age'],
                $record['club'],
                $record['location'],
                $record['record_date'],
                $record['wind_reading'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
