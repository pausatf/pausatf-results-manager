<?php
/**
 * Admin Settings Handler
 *
 * Handles AJAX operations for admin tools and settings.
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results\Admin;

use PAUSATF\Results\FeatureManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Settings class
 */
class AdminSettings {

    /**
     * Initialize admin settings
     */
    public static function init(): void {
        // Register AJAX handlers
        add_action('wp_ajax_pausatf_bulk_create_athletes', [self::class, 'ajax_bulk_create_athletes']);
        add_action('wp_ajax_pausatf_reparse_all', [self::class, 'ajax_reparse_all']);
        add_action('wp_ajax_pausatf_scan_records', [self::class, 'ajax_scan_records']);
        add_action('wp_ajax_pausatf_regenerate_rankings', [self::class, 'ajax_regenerate_rankings']);
        add_action('wp_ajax_pausatf_repair_tables', [self::class, 'ajax_repair_tables']);
        add_action('wp_ajax_pausatf_clear_cache', [self::class, 'ajax_clear_cache']);
        add_action('wp_ajax_pausatf_delete_all', [self::class, 'ajax_delete_all']);
    }

    /**
     * Verify AJAX nonce
     *
     * @return bool
     */
    private static function verify_nonce(): bool {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pausatf_ajax')) {
            wp_send_json_error(__('Security check failed.', 'pausatf-results'));
            return false;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
            return false;
        }

        return true;
    }

    /**
     * Bulk create athlete profiles
     */
    public static function ajax_bulk_create_athletes(): void {
        if (!self::verify_nonce()) {
            return;
        }

        global $wpdb;

        $min_events = get_option('pausatf_min_events_for_athlete', 3);
        $table = $wpdb->prefix . 'pausatf_results';

        // Find athletes with enough events who don't have profiles
        $athletes = $wpdb->get_results($wpdb->prepare(
            "SELECT athlete_name, COUNT(DISTINCT event_id) as event_count
             FROM $table
             WHERE athlete_id IS NULL
             GROUP BY athlete_name
             HAVING event_count >= %d",
            $min_events
        ));

        $created = 0;

        foreach ($athletes as $athlete) {
            // Check if athlete post already exists
            $existing = get_posts([
                'post_type' => 'pausatf_athlete',
                'title' => $athlete->athlete_name,
                'posts_per_page' => 1,
            ]);

            if (empty($existing)) {
                $post_id = wp_insert_post([
                    'post_type' => 'pausatf_athlete',
                    'post_title' => $athlete->athlete_name,
                    'post_status' => 'publish',
                ]);

                if ($post_id && !is_wp_error($post_id)) {
                    // Update results to link to this athlete
                    $wpdb->update(
                        $table,
                        ['athlete_id' => $post_id],
                        ['athlete_name' => $athlete->athlete_name]
                    );
                    $created++;
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Created %d athlete profiles.', 'pausatf-results'),
                $created
            ),
            'created' => $created,
        ]);
    }

    /**
     * Re-parse all events
     */
    public static function ajax_reparse_all(): void {
        if (!self::verify_nonce()) {
            return;
        }

        // This would typically be a background process
        // For now, we'll just mark all imports as pending
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_imports';

        $updated = $wpdb->update(
            $table,
            ['status' => 'pending'],
            ['status' => 'completed']
        );

        wp_send_json_success([
            'message' => sprintf(
                __('Marked %d imports for re-processing. Run the sync to process them.', 'pausatf-results'),
                $updated ?: 0
            ),
        ]);
    }

    /**
     * Scan for potential records
     */
    public static function ajax_scan_records(): void {
        if (!self::verify_nonce()) {
            return;
        }

        if (!FeatureManager::is_enabled('records_database')) {
            wp_send_json_error(__('Records Database feature is not enabled.', 'pausatf-results'));
            return;
        }

        if (class_exists('PAUSATF\\Results\\RecordsDatabase')) {
            $records = \PAUSATF\Results\RecordsDatabase::scan_for_records();
            wp_send_json_success([
                'message' => sprintf(
                    __('Found %d potential new records. Review them in the Records section.', 'pausatf-results'),
                    count($records)
                ),
            ]);
        } else {
            wp_send_json_error(__('RecordsDatabase class not found.', 'pausatf-results'));
        }
    }

    /**
     * Regenerate all rankings
     */
    public static function ajax_regenerate_rankings(): void {
        if (!self::verify_nonce()) {
            return;
        }

        if (!FeatureManager::is_enabled('ranking_system')) {
            wp_send_json_error(__('Ranking System feature is not enabled.', 'pausatf-results'));
            return;
        }

        if (class_exists('PAUSATF\\Results\\RankingSystem')) {
            $ranking = new \PAUSATF\Results\RankingSystem();
            $year = date('Y');

            // Generate rankings for current year
            $ranking->generate_rankings($year);
            $ranking->generate_age_graded_rankings($year);

            wp_send_json_success([
                'message' => sprintf(
                    __('Rankings regenerated for %d.', 'pausatf-results'),
                    $year
                ),
            ]);
        } else {
            wp_send_json_error(__('RankingSystem class not found.', 'pausatf-results'));
        }
    }

    /**
     * Repair database tables
     */
    public static function ajax_repair_tables(): void {
        if (!self::verify_nonce()) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables_created = [];

        // Core tables
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Results table
        $table_results = $wpdb->prefix . 'pausatf_results';
        $sql_results = "CREATE TABLE $table_results (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            athlete_id bigint(20) unsigned DEFAULT NULL,
            athlete_name varchar(255) NOT NULL,
            athlete_age smallint unsigned DEFAULT NULL,
            place smallint unsigned DEFAULT NULL,
            division varchar(50) DEFAULT NULL,
            division_place smallint unsigned DEFAULT NULL,
            time_seconds int unsigned DEFAULT NULL,
            time_display varchar(20) DEFAULT NULL,
            points decimal(10,2) DEFAULT NULL,
            payout decimal(10,2) DEFAULT NULL,
            club varchar(100) DEFAULT NULL,
            bib varchar(20) DEFAULT NULL,
            pace varchar(20) DEFAULT NULL,
            raw_data text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_id (event_id),
            KEY athlete_id (athlete_id),
            KEY athlete_name (athlete_name(100)),
            KEY division (division),
            KEY place (place)
        ) $charset_collate;";
        dbDelta($sql_results);
        $tables_created[] = 'pausatf_results';

        // Imports table
        $table_imports = $wpdb->prefix . 'pausatf_imports';
        $sql_imports = "CREATE TABLE $table_imports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_url varchar(500) NOT NULL,
            source_file varchar(255) DEFAULT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            records_imported int unsigned DEFAULT 0,
            error_message text DEFAULT NULL,
            imported_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_url (source_url(255)),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_imports);
        $tables_created[] = 'pausatf_imports';

        // Feature-specific tables
        if (FeatureManager::is_enabled('records_database') && class_exists('PAUSATF\\Results\\RecordsDatabase')) {
            \PAUSATF\Results\RecordsDatabase::create_table();
            $tables_created[] = 'pausatf_records';
        }

        if (FeatureManager::is_enabled('ranking_system') && class_exists('PAUSATF\\Results\\RankingSystem')) {
            \PAUSATF\Results\RankingSystem::create_table();
            $tables_created[] = 'pausatf_rankings';
        }

        if (FeatureManager::is_enabled('grand_prix') && class_exists('PAUSATF\\Results\\GrandPrix')) {
            \PAUSATF\Results\GrandPrix::create_tables();
            $tables_created[] = 'pausatf_grand_prix';
            $tables_created[] = 'pausatf_grand_prix_events';
            $tables_created[] = 'pausatf_grand_prix_points';
        }

        if (FeatureManager::is_enabled('webhooks') && class_exists('PAUSATF\\Results\\Webhooks')) {
            \PAUSATF\Results\Webhooks::create_tables();
            $tables_created[] = 'pausatf_webhooks';
            $tables_created[] = 'pausatf_webhook_deliveries';
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Repaired %d database tables: %s', 'pausatf-results'),
                count($tables_created),
                implode(', ', $tables_created)
            ),
        ]);
    }

    /**
     * Clear all caches
     */
    public static function ajax_clear_cache(): void {
        if (!self::verify_nonce()) {
            return;
        }

        global $wpdb;

        // Delete all plugin transients
        $wpdb->query(
            "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_pausatf_%' OR option_name LIKE '_transient_timeout_pausatf_%'"
        );

        // Clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('pausatf_results');
        }

        wp_send_json_success([
            'message' => __('All caches cleared successfully.', 'pausatf-results'),
        ]);
    }

    /**
     * Delete all data
     */
    public static function ajax_delete_all(): void {
        if (!self::verify_nonce()) {
            return;
        }

        global $wpdb;

        // Delete all results
        $table_results = $wpdb->prefix . 'pausatf_results';
        $wpdb->query("TRUNCATE TABLE $table_results");

        // Delete all imports
        $table_imports = $wpdb->prefix . 'pausatf_imports';
        $wpdb->query("TRUNCATE TABLE $table_imports");

        // Delete events
        $events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($events as $event_id) {
            wp_delete_post($event_id, true);
        }

        // Delete athletes
        $athletes = get_posts([
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($athletes as $athlete_id) {
            wp_delete_post($athlete_id, true);
        }

        // Clear feature-specific tables
        $feature_tables = [
            'pausatf_records',
            'pausatf_rankings',
            'pausatf_grand_prix',
            'pausatf_grand_prix_events',
            'pausatf_grand_prix_points',
            'pausatf_webhooks',
            'pausatf_webhook_deliveries',
        ];

        foreach ($feature_tables as $table) {
            $full_table = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table) {
                $wpdb->query("TRUNCATE TABLE $full_table");
            }
        }

        // Clear caches
        self::ajax_clear_cache();

        wp_send_json_success([
            'message' => __('All results data has been deleted.', 'pausatf-results'),
        ]);
    }
}

// Initialize admin settings
add_action('admin_init', [AdminSettings::class, 'init']);
