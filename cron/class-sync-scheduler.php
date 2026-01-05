<?php
/**
 * Sync Scheduler - Handles automatic sync from pausatf.org
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages scheduled syncing of results
 */
class SyncScheduler {
    private static ?SyncScheduler $instance = null;

    public static function instance(): SyncScheduler {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        // Main sync hook
        add_action('pausatf_results_sync', [$this, 'run_sync']);

        // Batch import hook
        add_action('pausatf_batch_import', [$this, 'run_batch_import']);

        // Custom schedule
        add_filter('cron_schedules', [$this, 'add_schedules']);

        // AJAX handlers
        add_action('wp_ajax_pausatf_bulk_create_athletes', [$this, 'ajax_bulk_create_athletes']);
    }

    /**
     * Add custom cron schedules
     */
    public function add_schedules(array $schedules): array {
        $schedules['weekly'] = [
            'interval' => 604800,
            'display' => __('Once Weekly', 'pausatf-results'),
        ];
        return $schedules;
    }

    /**
     * Run automatic sync
     */
    public function run_sync(): void {
        if (!get_option('pausatf_sync_enabled', 0)) {
            return;
        }

        $importer = new ResultsImporter();

        // Get current year and check for new results
        $current_year = date('Y');

        // Check directory listing for current year
        $response = wp_remote_get(PAUSATF_LEGACY_SOURCE_URL . $current_year . '/', [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('PAUSATF Sync Error: ' . $response->get_error_message());
            return;
        }

        $html = wp_remote_retrieve_body($response);

        // Parse directory listing for HTML files
        preg_match_all('/href="([^"]+\.html)"/i', $html, $matches);

        if (empty($matches[1])) {
            return;
        }

        $imported_urls = $this->get_imported_urls();

        foreach ($matches[1] as $file) {
            // Skip non-result files
            if ($this->should_skip_file($file)) {
                continue;
            }

            $url = PAUSATF_LEGACY_SOURCE_URL . $current_year . '/' . $file;

            // Skip if already imported
            if (in_array($url, $imported_urls)) {
                continue;
            }

            // Import new file
            $result = $importer->import_from_url($url);

            if ($result['success']) {
                error_log('PAUSATF Sync: Imported ' . $url);
            }

            // Rate limiting
            sleep(1);
        }
    }

    /**
     * Run batch import for a year
     */
    public function run_batch_import(int $year): void {
        $importer = new ResultsImporter();

        $response = wp_remote_get(PAUSATF_LEGACY_SOURCE_URL . $year . '/', [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('PAUSATF Batch Import Error: ' . $response->get_error_message());
            return;
        }

        $html = wp_remote_retrieve_body($response);

        preg_match_all('/href="([^"]+\.html)"/i', $html, $matches);

        if (empty($matches[1])) {
            return;
        }

        $count = 0;
        $errors = 0;

        foreach ($matches[1] as $file) {
            if ($this->should_skip_file($file)) {
                continue;
            }

            $url = PAUSATF_LEGACY_SOURCE_URL . $year . '/' . $file;

            $result = $importer->import_from_url($url);

            if ($result['success']) {
                $count++;
            } else {
                $errors++;
                error_log('PAUSATF Import Failed: ' . $url . ' - ' . ($result['error'] ?? 'Unknown'));
            }

            // Prevent timeout
            if (function_exists('set_time_limit')) {
                set_time_limit(30);
            }

            // Rate limiting
            usleep(500000); // 0.5 seconds
        }

        error_log("PAUSATF Batch Import Complete: Year {$year}, Imported {$count}, Errors {$errors}");
    }

    /**
     * Get already imported URLs
     */
    private function get_imported_urls(): array {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT source_url FROM {$wpdb->prefix}pausatf_imports WHERE status = 'completed'"
        );
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
            '/grant/i',
            '/application/i',
        ];

        foreach ($skip_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX handler for bulk athlete creation
     */
    public function ajax_bulk_create_athletes(): void {
        check_ajax_referer('pausatf_ajax');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $min_events = get_option('pausatf_min_events_for_athlete', 3);

        $athlete_db = new AthleteDatabase();
        $result = $athlete_db->bulk_create_athletes($min_events);

        wp_send_json_success($result);
    }

    /**
     * Update sync schedule
     */
    public static function update_schedule(): void {
        $frequency = get_option('pausatf_sync_frequency', 'daily');

        // Clear existing
        wp_clear_scheduled_hook('pausatf_results_sync');

        // Re-schedule if enabled
        if (get_option('pausatf_sync_enabled', 0)) {
            wp_schedule_event(time(), $frequency, 'pausatf_results_sync');
        }
    }
}

// Initialize
add_action('init', function() {
    SyncScheduler::instance();
});

// Update schedule when settings change
add_action('update_option_pausatf_sync_enabled', [SyncScheduler::class, 'update_schedule']);
add_action('update_option_pausatf_sync_frequency', [SyncScheduler::class, 'update_schedule']);
