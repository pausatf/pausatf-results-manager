<?php
/**
 * Plugin Name: PAUSATF Results Manager
 * Plugin URI: https://github.com/pausatf/pausatf-results-manager
 * Description: Import, manage, and display PAUSATF legacy competition results with full athlete tracking.
 * Version: 2.1.0
 * Author: PAUSATF
 * Author URI: https://www.pausatf.org
 * License: GPL v2 or later
 * Text Domain: pausatf-results
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('PAUSATF_RESULTS_VERSION', '2.1.0');
define('PAUSATF_RESULTS_FILE', __FILE__);
define('PAUSATF_RESULTS_DIR', plugin_dir_path(__FILE__));
define('PAUSATF_RESULTS_URL', plugin_dir_url(__FILE__));
define('PAUSATF_RESULTS_BASENAME', plugin_basename(__FILE__));

// Legacy content source URL
define('PAUSATF_LEGACY_SOURCE_URL', 'https://www.pausatf.org/data/');

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'PAUSATF\\Results\\';
    $base_dir = PAUSATF_RESULTS_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main plugin class
 */
final class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    public function activate(): void {
        // Load feature manager first
        require_once PAUSATF_RESULTS_DIR . 'includes/class-feature-manager.php';

        // Create custom database tables (core - always needed)
        $this->create_tables();

        // Create additional tables from components (conditionally based on features)
        if (FeatureManager::is_enabled('records_database')) {
            if (class_exists('PAUSATF\\Results\\RecordsDatabase')) {
                RecordsDatabase::create_table();
            }
        }

        if (FeatureManager::is_enabled('ranking_system')) {
            if (class_exists('PAUSATF\\Results\\RankingSystem')) {
                RankingSystem::create_table();
            }
        }

        if (FeatureManager::is_enabled('grand_prix')) {
            if (class_exists('PAUSATF\\Results\\GrandPrix')) {
                GrandPrix::create_tables();
            }
        }

        if (FeatureManager::is_enabled('webhooks')) {
            if (class_exists('PAUSATF\\Results\\Webhooks')) {
                Webhooks::create_tables();
            }
        }

        // Schedule sync cron
        if (!wp_next_scheduled('pausatf_results_sync')) {
            wp_schedule_event(time(), 'daily', 'pausatf_results_sync');
        }

        flush_rewrite_rules();
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('pausatf_results_sync');
        flush_rewrite_rules();
    }

    public function init(): void {
        $this->register_post_types();
        $this->register_taxonomies();
        $this->load_components();
    }

    private function register_post_types(): void {
        register_post_type('pausatf_event', [
            'labels' => [
                'name' => __('Events', 'pausatf-results'),
                'singular_name' => __('Event', 'pausatf-results'),
                'add_new' => __('Add New Event', 'pausatf-results'),
                'add_new_item' => __('Add New Event', 'pausatf-results'),
                'edit_item' => __('Edit Event', 'pausatf-results'),
                'view_item' => __('View Event', 'pausatf-results'),
                'search_items' => __('Search Events', 'pausatf-results'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'results'],
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'menu_icon' => 'dashicons-chart-line',
            'show_in_rest' => true,
        ]);

        register_post_type('pausatf_athlete', [
            'labels' => [
                'name' => __('Athletes', 'pausatf-results'),
                'singular_name' => __('Athlete', 'pausatf-results'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'athletes'],
            'supports' => ['title', 'thumbnail', 'custom-fields'],
            'menu_icon' => 'dashicons-groups',
            'show_in_rest' => true,
        ]);
    }

    private function register_taxonomies(): void {
        // Event Type: XC, Road Race, Track, Race Walk, Mountain/Ultra/Trail
        register_taxonomy('pausatf_event_type', 'pausatf_event', [
            'labels' => [
                'name' => __('Event Types', 'pausatf-results'),
                'singular_name' => __('Event Type', 'pausatf-results'),
            ],
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'event-type'],
        ]);

        // Season/Year
        register_taxonomy('pausatf_season', 'pausatf_event', [
            'labels' => [
                'name' => __('Seasons', 'pausatf-results'),
                'singular_name' => __('Season', 'pausatf-results'),
            ],
            'hierarchical' => false,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'season'],
        ]);

        // Age Division: Open, Masters (40+), Seniors (50+), etc.
        register_taxonomy('pausatf_division', ['pausatf_event', 'pausatf_athlete'], [
            'labels' => [
                'name' => __('Divisions', 'pausatf-results'),
                'singular_name' => __('Division', 'pausatf-results'),
            ],
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'division'],
        ]);
    }

    private function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Individual results table (for fast queries)
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

        // Import log table
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_results);
        dbDelta($sql_imports);
    }

    private function load_components(): void {
        // Load Feature Manager first (always required)
        require_once PAUSATF_RESULTS_DIR . 'includes/class-feature-manager.php';

        // Load parsers (always required for core functionality)
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/interface-parser.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-detector.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-table.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-pre.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-word.php';

        // Load USATF rules engine (conditionally)
        if (FeatureManager::is_enabled('usatf_rules_engine')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-rules-engine.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-age-divisions.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-record-categories.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-competition-rules.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-championship-rules.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/rules/class-usatf-event-standards.php';
        }

        // Load core classes (always required)
        require_once PAUSATF_RESULTS_DIR . 'includes/class-results-importer.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-athlete-database.php';

        // Load optional core features
        if (FeatureManager::is_enabled('performance_tracker')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-performance-tracker.php';
        }

        if (FeatureManager::is_enabled('club_manager')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-club-manager.php';
        }

        if (FeatureManager::is_enabled('csv_importer')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-csv-importer.php';
        }

        if (FeatureManager::is_enabled('data_exporter')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-data-exporter.php';
        }

        if (FeatureManager::is_enabled('athlete_claim')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-athlete-claim.php';
        }

        if (FeatureManager::is_enabled('records_database')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-records-database.php';
        }

        if (FeatureManager::is_enabled('ranking_system')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-ranking-system.php';
        }

        if (FeatureManager::is_enabled('athlete_dashboard')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-athlete-dashboard.php';
        }

        if (FeatureManager::is_enabled('certificates')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-certificates.php';
        }

        if (FeatureManager::is_enabled('grand_prix')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-grand-prix.php';
        }

        if (FeatureManager::is_enabled('race_director_portal')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-race-director-portal.php';
        }

        if (FeatureManager::is_enabled('graphql_api')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-graphql-api.php';
        }

        if (FeatureManager::is_enabled('webhooks')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-webhooks.php';
        }

        // Load integrations (conditionally)
        if (FeatureManager::is_enabled('hytek_importer')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-hytek-importer.php';
        }

        if (FeatureManager::is_enabled('runsignup_integration')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-runsignup-integration.php';
        }

        if (FeatureManager::is_enabled('athlinks_integration')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-athlinks-integration.php';
        }

        if (FeatureManager::is_enabled('usatf_verification')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-usatf-verification.php';
        }

        if (FeatureManager::is_enabled('timing_systems')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-timing-systems.php';
        }

        if (FeatureManager::is_enabled('strava_sync')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-strava-sync.php';
        }

        if (FeatureManager::is_enabled('ultrasignup_import')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/integrations/class-ultrasignup-import.php';
        }

        // Admin (always load for settings management)
        if (is_admin()) {
            require_once PAUSATF_RESULTS_DIR . 'admin/class-admin-settings.php';
            require_once PAUSATF_RESULTS_DIR . 'admin/class-admin-import.php';
        }

        // Public
        require_once PAUSATF_RESULTS_DIR . 'public/class-shortcodes.php';

        if (FeatureManager::is_enabled('rest_api')) {
            require_once PAUSATF_RESULTS_DIR . 'public/class-rest-api.php';
        }

        require_once PAUSATF_RESULTS_DIR . 'public/class-frontend-display.php';

        // Cron
        require_once PAUSATF_RESULTS_DIR . 'cron/class-sync-scheduler.php';
    }

    public function admin_menu(): void {
        add_menu_page(
            __('PAUSATF Results', 'pausatf-results'),
            __('PAUSATF Results', 'pausatf-results'),
            'manage_options',
            'pausatf-results',
            [$this, 'render_admin_page'],
            'dashicons-chart-line',
            30
        );

        add_submenu_page(
            'pausatf-results',
            __('Import Results', 'pausatf-results'),
            __('Import', 'pausatf-results'),
            'manage_options',
            'pausatf-results-import',
            [$this, 'render_import_page']
        );

        add_submenu_page(
            'pausatf-results',
            __('Settings', 'pausatf-results'),
            __('Settings', 'pausatf-results'),
            'manage_options',
            'pausatf-results-settings',
            [$this, 'render_settings_page']
        );
    }

    public function admin_assets(string $hook): void {
        if (strpos($hook, 'pausatf-results') === false) {
            return;
        }

        wp_enqueue_style(
            'pausatf-results-admin',
            PAUSATF_RESULTS_URL . 'assets/css/admin.css',
            [],
            PAUSATF_RESULTS_VERSION
        );

        wp_enqueue_script(
            'pausatf-results-admin',
            PAUSATF_RESULTS_URL . 'assets/js/admin.js',
            ['jquery'],
            PAUSATF_RESULTS_VERSION,
            true
        );

        wp_localize_script('pausatf-results-admin', 'pausatfResults', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pausatf_results_nonce'),
        ]);
    }

    public function render_admin_page(): void {
        include PAUSATF_RESULTS_DIR . 'admin/views/dashboard.php';
    }

    public function render_import_page(): void {
        include PAUSATF_RESULTS_DIR . 'admin/views/import.php';
    }

    public function render_settings_page(): void {
        include PAUSATF_RESULTS_DIR . 'admin/views/settings.php';
    }
}

// Initialize plugin
function pausatf_results(): Plugin {
    return Plugin::instance();
}

add_action('plugins_loaded', 'PAUSATF\\Results\\pausatf_results');
