<?php
/**
 * Plugin Name: PAUSATF Results Manager
 * Plugin URI: https://github.com/pausatf/pausatf-results-manager
 * Description: Import, manage, and display PAUSATF legacy competition results with full athlete tracking.
 * Version: 2.5.1
 * Author: PAUSATF
 * Author URI: https://www.pausatf.org
 * License: GPL v2 or later
 * Text Domain: pausatf-results
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.4
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('PAUSATF_RESULTS_VERSION', '2.5.1');
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
        add_action('admin_init', [$this, 'maybe_upgrade']);
    }

    public function activate(): void {
        // Load feature manager first
        require_once PAUSATF_RESULTS_DIR . 'includes/class-feature-manager.php';

        // Create custom database tables (core - always needed)
        require_once PAUSATF_RESULTS_DIR . 'includes/class-listed-sex.php';
        $this->create_tables();
        update_option(ListedSex::SCHEMA_OPTION, ListedSex::SCHEMA_VERSION);

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
        require_once PAUSATF_RESULTS_DIR . 'includes/class-sanctions-retirement.php';
        wp_clear_scheduled_hook(SanctionsRetirement::CRON_HOOK);
        flush_rewrite_rules();
    }

    public function init(): void {
        $this->register_post_types();
        $this->register_taxonomies();
        $this->register_event_meta();
        $this->load_components();
    }

    /** Register the canonical event data contract in the WordPress REST schema. */
    private function register_event_meta(): void {
        $fields = [
            'event_uid' => ['type' => 'string', 'description' => 'Stable canonical event identifier.'],
            'start_datetime' => ['type' => 'string', 'description' => 'Event start in ISO 8601 format.'],
            'end_datetime' => ['type' => 'string', 'description' => 'Event end in ISO 8601 format.'],
            'event_status' => ['type' => 'string', 'description' => 'Event status such as scheduled, postponed, or cancelled.'],
            'attendance_mode' => ['type' => 'string', 'description' => 'In-person, online, or mixed attendance.'],
            'venue_name' => ['type' => 'string', 'description' => 'Venue name.'],
            'address_locality' => ['type' => 'string', 'description' => 'Venue city.'],
            'address_region' => ['type' => 'string', 'description' => 'Venue state or region.'],
            'postal_code' => ['type' => 'string', 'description' => 'Venue postal code.'],
            'country' => ['type' => 'string', 'description' => 'Venue country code.'],
            'map_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Verified map URL.'],
            'registration_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Verified registration URL.'],
            'registration_status' => ['type' => 'string', 'description' => 'Registration state.'],
            'organizer_name' => ['type' => 'string', 'description' => 'Organizer name.'],
            'organizer_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Organizer URL.'],
            'source_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Authoritative source URL.'],
            'source_site' => ['type' => 'string', 'description' => 'Source site name.'],
            'source_hash' => ['type' => 'string', 'description' => 'Hash of the canonical source record.'],
            'import_confidence' => ['type' => 'number', 'description' => 'Extraction confidence from 0 to 1.'],
            'review_status' => ['type' => 'string', 'description' => 'Import review state.'],
            'last_synced_at' => ['type' => 'string', 'description' => 'Last canonical sync timestamp.'],
        ];

        foreach ($fields as $name => $args) {
            register_post_meta('pausatf_event', '_pausatf_' . $name, [
                'type' => $args['type'],
                'description' => $args['description'],
                'single' => true,
                'show_in_rest' => true,
                // sanitize_meta() passes four arguments; internal floatval() accepts only one.
                'sanitize_callback' => $args['type'] === 'number' ? static fn($value): float => (float) $value : 'sanitize_text_field',
                'auth_callback' => static fn() => current_user_can('edit_posts'),
            ]);
        }

        register_post_meta('pausatf_athlete', '_pausatf_athlete_uid', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => static fn() => current_user_can('edit_posts'),
        ]);
        register_post_meta('pausatf_athlete', '_pausatf_name_normalized', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => static fn() => current_user_can('edit_posts'),
        ]);
    }

    private function register_post_types(): void {
        // PAUSATF Site's Events module owns pausatf_event when it is active: it
        // registers the type on init priority 7, before this callback runs at 10.
        // Defer to it so one plugin controls the rewrite slug and capabilities;
        // the taxonomies and meta below attach to whichever registration exists.
        // Its type omits custom-fields support, which REST needs to expose the
        // meta registered in register_event_meta().
        $owner = post_type_exists('pausatf_event') ? 'pausatf-site' : 'pausatf-results';
        if ($owner === 'pausatf-site') {
            add_post_type_support('pausatf_event', 'custom-fields');
        } else {
            $this->register_event_post_type();
        }
        // The owner decides the permalink base (/events/ or /results/). When it
        // changes, rebuild rewrite rules once every plugin has added its own.
        if (get_option('pausatf_results_event_type_owner') !== $owner) {
            update_option('pausatf_results_event_type_owner', $owner, false);
            add_action('wp_loaded', static fn() => flush_rewrite_rules(false));
        }

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

    private function register_event_post_type(): void {
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

        register_taxonomy('pausatf_discipline', 'pausatf_event', [
            'labels' => ['name' => __('Disciplines', 'pausatf-results'), 'singular_name' => __('Discipline', 'pausatf-results')],
            'hierarchical' => true, 'public' => true, 'show_in_rest' => true,
            'rewrite' => ['slug' => 'discipline'],
        ]);

        register_taxonomy('pausatf_series', 'pausatf_event', [
            'labels' => ['name' => __('Event Series', 'pausatf-results'), 'singular_name' => __('Event Series', 'pausatf-results')],
            'hierarchical' => false, 'public' => true, 'show_in_rest' => true,
            'rewrite' => ['slug' => 'event-series'],
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
            sex varchar(1) DEFAULT NULL,
            sex_listed varchar(20) DEFAULT NULL,
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
            parser varchar(100) DEFAULT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            status enum('pending','processing','completed','failed') DEFAULT 'pending',
            records_imported int unsigned DEFAULT 0,
            error_message text DEFAULT NULL,
            imported_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_url (source_url(255)),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_results);
        dbDelta($sql_imports);
    }

    /** Run non-destructive schema upgrades for sites upgraded without reactivation. */
    public function maybe_upgrade(): void {
        $installed = get_option('pausatf_results_version', '0');
        // The schema option lets a column be added without a release version bump.
        if (version_compare($installed, PAUSATF_RESULTS_VERSION, '<') || !ListedSex::schema_ready()) {
            $this->create_tables();
            update_option('pausatf_results_version', PAUSATF_RESULTS_VERSION);
            update_option(ListedSex::SCHEMA_OPTION, ListedSex::SCHEMA_VERSION);
        }
    }

    private function load_components(): void {
        // Load Feature Manager first (always required)
        require_once PAUSATF_RESULTS_DIR . 'includes/class-feature-manager.php';
        FeatureManager::init();

        // Load parsers (always required for core functionality)
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/interface-parser.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-detector.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-table.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-pre.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/parsers/class-parser-word.php';

        // Archive candidates use their own review and publication boundary.
        // PAUSATF Site's Archive owns the admin menu, handler, REST routes,
        // shortcode, and CLI when it is active, so none of them load here.
        // The code stays until production runs PAUSATF Site.
        require_once PAUSATF_RESULTS_DIR . 'includes/archive/class-archive-ownership.php';
        if (!Archive\ArchiveOwnership::site_provides()) {
            foreach (['bundle', 'review', 'database', 'schema', 'workflow', 'view', 'cli', 'module'] as $component) {
                require_once PAUSATF_RESULTS_DIR . 'includes/archive/class-archive-' . $component . '.php';
            }
            Archive\ArchiveModule::register();
        }

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
        require_once PAUSATF_RESULTS_DIR . 'includes/class-age-grading.php';
        AgeGrading::register_cli();
        require_once PAUSATF_RESULTS_DIR . 'includes/class-athlete-identity.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-gp-eligibility.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-xc-team-scoring.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-event-date.php';
        EventDate::register();
        require_once PAUSATF_RESULTS_DIR . 'includes/class-listed-sex.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-placement-time.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-results-importer.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/storage/class-results-repository.php';
        require_once PAUSATF_RESULTS_DIR . 'includes/class-canonical-event-service.php';
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
            add_action('pausatf_results_age_grading_imported', [RankingSystem::class, 'create_table']);
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

        if (FeatureManager::is_enabled('rdf_support')) {
            require_once PAUSATF_RESULTS_DIR . 'includes/class-rdf-exporter.php';
            require_once PAUSATF_RESULTS_DIR . 'includes/class-sparql-endpoint.php';
        }


        // Sanctions are applied for and granted through USATF (Governance
        // Handbook, Regulation 23-C). The retired manager's tables stay in place.
        require_once PAUSATF_RESULTS_DIR . 'includes/class-sanctions-retirement.php';
        SanctionsRetirement::register();

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
        require_once PAUSATF_RESULTS_DIR . 'public/class-event-jsonld.php';

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

        // Conditionally add Semantic Web page
        if (FeatureManager::is_enabled('rdf_support')) {
            add_submenu_page(
                'pausatf-results',
                __('Semantic Web', 'pausatf-results'),
                __('RDF/SPARQL', 'pausatf-results'),
                'manage_options',
                'pausatf-results-semantic',
                [$this, 'render_semantic_page']
            );
        }
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

    public function render_semantic_page(): void {
        include PAUSATF_RESULTS_DIR . 'admin/views/semantic-web.php';
    }
}

// Initialize plugin
function pausatf_results(): Plugin {
    return Plugin::instance();
}

add_action('plugins_loaded', 'PAUSATF\\Results\\pausatf_results');
