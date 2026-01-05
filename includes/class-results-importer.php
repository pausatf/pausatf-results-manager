<?php
/**
 * Results Importer - Orchestrates parsing and database storage
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

use PAUSATF\Results\Parsers\ParserDetector;
use PAUSATF\Results\Parsers\ParsedResults;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main import orchestration class
 */
class ResultsImporter {
    private ParserDetector $detector;

    public function __construct() {
        $this->detector = new ParserDetector();
    }

    /**
     * Import results from a URL
     *
     * @param string $url Source URL
     * @param array  $options Import options
     * @return array Import result
     */
    public function import_from_url(string $url, array $options = []): array {
        // Log import start
        $import_id = $this->log_import_start($url);

        try {
            // Fetch HTML
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'sslverify' => false,
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('Failed to fetch URL: ' . $response->get_error_message());
            }

            $html = wp_remote_retrieve_body($response);

            if (empty($html)) {
                throw new \Exception('Empty response from URL');
            }

            // Parse and import
            $result = $this->import_from_html($html, array_merge($options, [
                'source_url' => $url,
                'import_id' => $import_id,
            ]));

            // Update import log
            $this->log_import_complete($import_id, $result['records_imported'] ?? 0);

            return $result;

        } catch (\Exception $e) {
            $this->log_import_failed($import_id, $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'import_id' => $import_id,
            ];
        }
    }

    /**
     * Import results from HTML content
     *
     * @param string $html HTML content
     * @param array  $options Import options
     * @return array Import result
     */
    public function import_from_html(string $html, array $options = []): array {
        // Detect parser
        $parser = $this->detector->detect($html);

        if (!$parser) {
            return [
                'success' => false,
                'error' => 'No suitable parser found for this HTML format',
                'analysis' => $this->detector->analyze($html),
            ];
        }

        // Parse HTML
        $parsed = $parser->parse($html, $options);

        if ($parsed->has_errors()) {
            return [
                'success' => false,
                'errors' => $parsed->errors,
                'warnings' => $parsed->warnings,
                'parser' => $parser->get_id(),
            ];
        }

        // Create or update event post
        $event_id = $this->create_or_update_event($parsed, $options);

        if (is_wp_error($event_id)) {
            return [
                'success' => false,
                'error' => $event_id->get_error_message(),
            ];
        }

        // Import individual results
        $imported_count = $this->import_results($event_id, $parsed->results);

        return [
            'success' => true,
            'event_id' => $event_id,
            'records_imported' => $imported_count,
            'divisions' => $parsed->divisions,
            'warnings' => $parsed->warnings,
            'parser' => $parser->get_id(),
            'event_name' => $parsed->event_name,
            'event_date' => $parsed->event_date,
        ];
    }

    /**
     * Import from a local file
     *
     * @param string $file_path Path to HTML file
     * @param array  $options Import options
     * @return array Import result
     */
    public function import_from_file(string $file_path, array $options = []): array {
        if (!file_exists($file_path)) {
            return [
                'success' => false,
                'error' => 'File not found: ' . $file_path,
            ];
        }

        $html = file_get_contents($file_path);

        if ($html === false) {
            return [
                'success' => false,
                'error' => 'Failed to read file: ' . $file_path,
            ];
        }

        // Extract context from filename
        $filename = basename($file_path);
        if (preg_match('/^([A-Z]+).*?(\d{4})/', $filename, $matches)) {
            $options['event_type_hint'] = $matches[1];
            $options['year_hint'] = (int) $matches[2];
        }

        return $this->import_from_html($html, array_merge($options, [
            'source_file' => $file_path,
        ]));
    }

    /**
     * Batch import from directory
     *
     * @param string $directory Path to directory
     * @param array  $options Import options
     * @return array Batch results
     */
    public function import_from_directory(string $directory, array $options = []): array {
        $results = [
            'total_files' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        $files = glob($directory . '/*.html');
        $results['total_files'] = count($files);

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip non-result files
            if ($this->should_skip_file($filename)) {
                $results['skipped']++;
                continue;
            }

            $import_result = $this->import_from_file($file, $options);

            if ($import_result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }

            $results['details'][$filename] = $import_result;

            // Prevent timeout
            if (function_exists('set_time_limit')) {
                set_time_limit(30);
            }
        }

        return $results;
    }

    /**
     * Create or update event post
     */
    private function create_or_update_event(ParsedResults $parsed, array $options): int|\WP_Error {
        // Check for existing event
        $existing = $this->find_existing_event($parsed, $options);

        $post_data = [
            'post_type' => 'pausatf_event',
            'post_title' => $parsed->event_name ?: 'Untitled Event',
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $parsed->event_date,
                '_pausatf_event_location' => $parsed->event_location,
                '_pausatf_source_url' => $options['source_url'] ?? '',
                '_pausatf_source_file' => $options['source_file'] ?? '',
                '_pausatf_result_count' => count($parsed->results),
                '_pausatf_divisions' => $parsed->divisions,
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ];

        if ($existing) {
            $post_data['ID'] = $existing;
            $event_id = wp_update_post($post_data, true);
        } else {
            $event_id = wp_insert_post($post_data, true);
        }

        if (!is_wp_error($event_id)) {
            // Set taxonomies
            $this->set_event_taxonomies($event_id, $parsed, $options);
        }

        return $event_id;
    }

    /**
     * Find existing event by URL or name/date
     */
    private function find_existing_event(ParsedResults $parsed, array $options): ?int {
        global $wpdb;

        // Check by source URL first
        if (!empty($options['source_url'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_pausatf_source_url' AND meta_value = %s
                 LIMIT 1",
                $options['source_url']
            ));

            if ($existing) {
                return (int) $existing;
            }
        }

        // Check by name and date
        if ($parsed->event_name && $parsed->event_date) {
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
     * Set event taxonomies
     */
    private function set_event_taxonomies(int $event_id, ParsedResults $parsed, array $options): void {
        // Detect event type from name or filename
        $event_type = $this->detect_event_type($parsed->event_name, $options);
        if ($event_type) {
            wp_set_object_terms($event_id, $event_type, 'pausatf_event_type');
        }

        // Set season/year
        $year = null;
        if ($parsed->event_date) {
            $year = date('Y', strtotime($parsed->event_date));
        } elseif (!empty($options['year_hint'])) {
            $year = $options['year_hint'];
        }

        if ($year) {
            wp_set_object_terms($event_id, (string) $year, 'pausatf_season');
        }

        // Set divisions
        if (!empty($parsed->divisions)) {
            wp_set_object_terms($event_id, $parsed->divisions, 'pausatf_division');
        }
    }

    /**
     * Detect event type from name/options
     */
    private function detect_event_type(string $name, array $options): ?string {
        $type_hint = $options['event_type_hint'] ?? '';
        $name_lower = strtolower($name . ' ' . $type_hint);

        $patterns = [
            'Cross Country' => ['xc', 'cross country', 'cross-country'],
            'Road Race' => ['5k', '10k', '10mi', 'half', 'marathon', 'mile', 'road'],
            'Track & Field' => ['track', 'field', 'tf', 'relay'],
            'Race Walk' => ['walk', 'rw', 'racewalk'],
            'Mountain/Ultra/Trail' => ['mountain', 'ultra', 'trail', 'mut'],
        ];

        foreach ($patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($name_lower, $keyword) !== false) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Import individual results to database
     */
    private function import_results(int $event_id, array $results): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        // Delete existing results for this event
        $wpdb->delete($table, ['event_id' => $event_id]);

        $imported = 0;

        foreach ($results as $result) {
            $data = [
                'event_id' => $event_id,
                'athlete_name' => $result['athlete_name'] ?? '',
                'athlete_age' => $result['athlete_age'] ?? null,
                'place' => $result['place'] ?? null,
                'division' => $result['division'] ?? null,
                'division_place' => $result['division_place'] ?? null,
                'time_seconds' => $result['time_seconds'] ?? null,
                'time_display' => $result['time_display'] ?? null,
                'points' => $result['points'] ?? null,
                'payout' => $result['payout'] ?? null,
                'club' => $result['club'] ?? null,
                'bib' => $result['bib'] ?? null,
                'pace' => $result['pace'] ?? null,
                'raw_data' => json_encode($result),
            ];

            // Try to link to existing athlete
            $athlete_id = $this->find_or_create_athlete($result);
            if ($athlete_id) {
                $data['athlete_id'] = $athlete_id;
            }

            $inserted = $wpdb->insert($table, $data);

            if ($inserted) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * Find or create athlete record
     */
    private function find_or_create_athlete(array $result): ?int {
        // For now, just try to find existing athlete by name
        // TODO: Implement fuzzy matching and athlete deduplication

        $name = $result['athlete_name'] ?? '';
        if (empty($name)) {
            return null;
        }

        $existing = get_page_by_title($name, OBJECT, 'pausatf_athlete');

        if ($existing) {
            return $existing->ID;
        }

        // Don't auto-create athletes - that's a separate feature
        return null;
    }

    /**
     * Check if file should be skipped
     */
    private function should_skip_file(string $filename): bool {
        $skip_patterns = [
            '/^index/i',
            '/schedule/i',
            '/form/i',
            '/flyer/i',
            '/flier/i',
            '/^info/i',
            '/^about/i',
            '/bylaws/i',
            '/minutes/i',
            '/procedures/i',
        ];

        foreach ($skip_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log import start
     */
    private function log_import_start(string $url): int {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'pausatf_imports',
            [
                'source_url' => $url,
                'status' => 'processing',
            ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Log import complete
     */
    private function log_import_complete(int $import_id, int $records): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'pausatf_imports',
            [
                'status' => 'completed',
                'records_imported' => $records,
                'imported_at' => current_time('mysql'),
            ],
            ['id' => $import_id]
        );
    }

    /**
     * Log import failure
     */
    private function log_import_failed(int $import_id, string $error): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'pausatf_imports',
            [
                'status' => 'failed',
                'error_message' => $error,
            ],
            ['id' => $import_id]
        );
    }

    /**
     * Get import history
     */
    public function get_import_history(int $limit = 50): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pausatf_imports
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Get parser detector for analysis
     */
    public function get_detector(): ParserDetector {
        return $this->detector;
    }
}
