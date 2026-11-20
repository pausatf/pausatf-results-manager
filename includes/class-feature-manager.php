<?php
/**
 * Feature Manager
 *
 * Manages enabling/disabling of plugin features through the admin panel.
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feature Manager class
 */
class FeatureManager {
    /**
     * Option key for storing feature settings
     */
    private const OPTION_KEY = 'pausatf_features';

    /**
     * All available features with their metadata
     *
     * @var array
     */
    private static array $features = [];

    /**
     * Cached feature states
     *
     * @var array|null
     */
    private static ?array $cached_states = null;

    /**
     * Initialize the feature manager
     */
    public static function init(): void {
        self::register_default_features();
        add_action('admin_init', [self::class, 'register_settings']);
    }

    /**
     * Register all default features
     */
    private static function register_default_features(): void {
        // Core Features (always enabled, not toggleable)
        self::register_feature('core_results', [
            'name' => __('Results Import & Display', 'pausatf-results'),
            'description' => __('Core functionality for importing and displaying race results.', 'pausatf-results'),
            'category' => 'core',
            'default' => true,
            'toggleable' => false,
            'icon' => 'dashicons-chart-line',
        ]);

        self::register_feature('core_athletes', [
            'name' => __('Athlete Management', 'pausatf-results'),
            'description' => __('Core athlete profiles and tracking system.', 'pausatf-results'),
            'category' => 'core',
            'default' => true,
            'toggleable' => false,
            'icon' => 'dashicons-groups',
        ]);

        // USATF Rules Engine
        self::register_feature('usatf_rules_engine', [
            'name' => __('USATF Rules Engine', 'pausatf-results'),
            'description' => __('Complete USATF competition rules including age divisions, record categories, and event standards.', 'pausatf-results'),
            'category' => 'rules',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-book',
            'files' => [
                'includes/rules/class-usatf-rules-engine.php',
                'includes/rules/class-usatf-age-divisions.php',
                'includes/rules/class-usatf-record-categories.php',
                'includes/rules/class-usatf-competition-rules.php',
                'includes/rules/class-usatf-championship-rules.php',
                'includes/rules/class-usatf-event-standards.php',
            ],
        ]);

        // Records & Rankings
        self::register_feature('records_database', [
            'name' => __('Records Database', 'pausatf-results'),
            'description' => __('Track association records by event, age group, and division with verification workflow.', 'pausatf-results'),
            'category' => 'analytics',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-awards',
            'depends_on' => ['usatf_rules_engine'],
            'files' => ['includes/class-records-database.php'],
            'has_tables' => true,
        ]);

        self::register_feature('ranking_system', [
            'name' => __('Ranking System', 'pausatf-results'),
            'description' => __('Seasonal and all-time rankings with age-graded performance comparisons.', 'pausatf-results'),
            'category' => 'analytics',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-sort',
            'depends_on' => ['usatf_rules_engine'],
            'files' => ['includes/class-ranking-system.php'],
            'has_tables' => true,
        ]);

        // Athlete Experience
        self::register_feature('athlete_dashboard', [
            'name' => __('Athlete Dashboard', 'pausatf-results'),
            'description' => __('Personal portal for athletes to view results, PRs, rankings, and manage their profile.', 'pausatf-results'),
            'category' => 'athlete',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-admin-users',
            'files' => ['includes/class-athlete-dashboard.php'],
            'shortcodes' => ['pausatf_athlete_dashboard'],
        ]);

        self::register_feature('athlete_claim', [
            'name' => __('Athlete Self-Claim', 'pausatf-results'),
            'description' => __('Allow users to claim and link their athlete profiles to WordPress accounts.', 'pausatf-results'),
            'category' => 'athlete',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-admin-links',
            'files' => ['includes/class-athlete-claim.php'],
        ]);

        self::register_feature('certificates', [
            'name' => __('Certificates & Social Sharing', 'pausatf-results'),
            'description' => __('Generate PDF certificates and social media share cards for results.', 'pausatf-results'),
            'category' => 'athlete',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-media-document',
            'files' => ['includes/class-certificates.php'],
            'requirements' => ['gd'],
        ]);

        // Competition Features
        self::register_feature('grand_prix', [
            'name' => __('Grand Prix Series', 'pausatf-results'),
            'description' => __('Multi-race series point tracking with configurable scoring systems.', 'pausatf-results'),
            'category' => 'competition',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-star-filled',
            'files' => ['includes/class-grand-prix.php'],
            'has_tables' => true,
            'shortcodes' => ['pausatf_grand_prix'],
        ]);

        self::register_feature('performance_tracker', [
            'name' => __('Performance Tracker', 'pausatf-results'),
            'description' => __('Track athlete performance trends and personal records over time.', 'pausatf-results'),
            'category' => 'analytics',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-chart-area',
            'files' => ['includes/class-performance-tracker.php'],
        ]);

        self::register_feature('club_manager', [
            'name' => __('Club Manager', 'pausatf-results'),
            'description' => __('Manage clubs/teams and aggregate team results.', 'pausatf-results'),
            'category' => 'competition',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-building',
            'files' => ['includes/class-club-manager.php'],
        ]);

        // Race Director Tools
        self::register_feature('race_director_portal', [
            'name' => __('Race Director Portal', 'pausatf-results'),
            'description' => __('Self-service portal for race directors to submit events and upload results.', 'pausatf-results'),
            'category' => 'race_director',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-clipboard',
            'files' => ['includes/class-race-director-portal.php'],
            'shortcodes' => ['pausatf_rd_portal'],
            'capabilities' => ['race_director'],
        ]);

        // Import/Export
        self::register_feature('csv_importer', [
            'name' => __('CSV Import', 'pausatf-results'),
            'description' => __('Import results from CSV files with flexible column mapping.', 'pausatf-results'),
            'category' => 'import',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-upload',
            'files' => ['includes/class-csv-importer.php'],
        ]);

        self::register_feature('data_exporter', [
            'name' => __('Data Export', 'pausatf-results'),
            'description' => __('Export results and athlete data in various formats (CSV, JSON, XML).', 'pausatf-results'),
            'category' => 'import',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-download',
            'files' => ['includes/class-data-exporter.php'],
        ]);

        // Third-Party Integrations
        self::register_feature('hytek_importer', [
            'name' => __('Hy-Tek Import', 'pausatf-results'),
            'description' => __('Import results from Hy-Tek Meet Manager timing software.', 'pausatf-results'),
            'category' => 'integration',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-clock',
            'files' => ['includes/integrations/class-hytek-importer.php'],
        ]);

        self::register_feature('runsignup_integration', [
            'name' => __('RunSignUp Integration', 'pausatf-results'),
            'description' => __('Sync events and results from RunSignUp race management platform.', 'pausatf-results'),
            'category' => 'integration',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-migrate',
            'files' => ['includes/integrations/class-runsignup-integration.php'],
            'settings' => ['runsignup_api_key', 'runsignup_api_secret'],
        ]);

        self::register_feature('athlinks_integration', [
            'name' => __('Athlinks Integration', 'pausatf-results'),
            'description' => __('Sync athlete race history from Athlinks database.', 'pausatf-results'),
            'category' => 'integration',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-database-import',
            'files' => ['includes/integrations/class-athlinks-integration.php'],
            'settings' => ['athlinks_api_key'],
        ]);

        self::register_feature('usatf_verification', [
            'name' => __('USATF Membership Verification', 'pausatf-results'),
            'description' => __('Verify athlete USATF membership status for championship eligibility.', 'pausatf-results'),
            'category' => 'integration',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-yes-alt',
            'files' => ['includes/integrations/class-usatf-verification.php'],
            'settings' => ['usatf_api_key'],
        ]);

        self::register_feature('timing_systems', [
            'name' => __('Timing Systems Import', 'pausatf-results'),
            'description' => __('Import from ChronoTrack, MYLAPS, Webscorer, and RaceTab timing systems.', 'pausatf-results'),
            'category' => 'integration',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-performance',
            'files' => ['includes/integrations/class-timing-systems.php'],
        ]);

        self::register_feature('strava_sync', [
            'name' => __('Strava Integration', 'pausatf-results'),
            'description' => __('OAuth integration to link results with Strava activities.', 'pausatf-results'),
            'category' => 'integration',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-share',
            'files' => ['includes/integrations/class-strava-sync.php'],
            'settings' => ['strava_client_id', 'strava_client_secret'],
        ]);

        self::register_feature('ultrasignup_import', [
            'name' => __('UltraSignup Import', 'pausatf-results'),
            'description' => __('Import ultra and trail race results from UltraSignup.', 'pausatf-results'),
            'category' => 'integration',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-palmtree',
            'files' => ['includes/integrations/class-ultrasignup-import.php'],
        ]);

        // API Features
        self::register_feature('rest_api', [
            'name' => __('REST API', 'pausatf-results'),
            'description' => __('WordPress REST API endpoints for results, athletes, and events.', 'pausatf-results'),
            'category' => 'api',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-rest-api',
            'files' => ['public/class-rest-api.php'],
        ]);

        self::register_feature('graphql_api', [
            'name' => __('GraphQL API', 'pausatf-results'),
            'description' => __('GraphQL endpoint for flexible data queries.', 'pausatf-results'),
            'category' => 'api',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-admin-site',
            'files' => ['includes/class-graphql-api.php'],
        ]);

        self::register_feature('webhooks', [
            'name' => __('Webhooks', 'pausatf-results'),
            'description' => __('Send event notifications to external systems when results are added.', 'pausatf-results'),
            'category' => 'api',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-randomize',
            'files' => ['includes/class-webhooks.php'],
            'has_tables' => true,
        ]);

        self::register_feature('rdf_support', [
            'name' => __('RDF & Linked Data', 'pausatf-results'),
            'description' => __('Export data as RDF using Schema.org, FOAF, and Dublin Core schemas. Includes SPARQL query endpoint.', 'pausatf-results'),
            'category' => 'api',
            'default' => false,
            'toggleable' => true,
            'icon' => 'dashicons-networking',
            'files' => [
                'includes/class-rdf-exporter.php',
                'includes/class-sparql-endpoint.php',
            ],
            'endpoints' => ['/rdf/', '/sparql'],
        ]);
    }

    /**
     * Register a feature
     *
     * @param string $feature_id Unique feature identifier
     * @param array  $args       Feature arguments
     */
    public static function register_feature(string $feature_id, array $args): void {
        $defaults = [
            'name' => $feature_id,
            'description' => '',
            'category' => 'other',
            'default' => true,
            'toggleable' => true,
            'icon' => 'dashicons-admin-generic',
            'depends_on' => [],
            'files' => [],
            'has_tables' => false,
            'shortcodes' => [],
            'settings' => [],
            'capabilities' => [],
            'requirements' => [],
        ];

        self::$features[$feature_id] = wp_parse_args($args, $defaults);
    }

    /**
     * Register settings with WordPress
     */
    public static function register_settings(): void {
        register_setting('pausatf_features', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_features'],
            'default' => [],
        ]);
    }

    /**
     * Sanitize feature settings
     *
     * @param mixed $value The value to sanitize
     * @return array Sanitized feature states
     */
    public static function sanitize_features($value): array {
        if (!is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach (self::$features as $feature_id => $feature) {
            if (!$feature['toggleable']) {
                $sanitized[$feature_id] = true;
            } else {
                $sanitized[$feature_id] = isset($value[$feature_id]) && $value[$feature_id] ? true : false;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a feature is enabled
     *
     * @param string $feature_id Feature identifier
     * @return bool Whether the feature is enabled
     */
    public static function is_enabled(string $feature_id): bool {
        if (null === self::$cached_states) {
            self::$cached_states = self::get_all_states();
        }

        return self::$cached_states[$feature_id] ?? false;
    }

    /**
     * Get all feature states
     *
     * @return array Feature states
     */
    public static function get_all_states(): array {
        $saved = get_option(self::OPTION_KEY, []);
        $states = [];

        foreach (self::$features as $feature_id => $feature) {
            if (!$feature['toggleable']) {
                $states[$feature_id] = true;
            } elseif (isset($saved[$feature_id])) {
                $states[$feature_id] = (bool) $saved[$feature_id];
            } else {
                $states[$feature_id] = $feature['default'];
            }

            // Check dependencies
            if ($states[$feature_id] && !empty($feature['depends_on'])) {
                foreach ($feature['depends_on'] as $dependency) {
                    if (!($states[$dependency] ?? false)) {
                        $states[$feature_id] = false;
                        break;
                    }
                }
            }

            // Check system requirements
            if ($states[$feature_id] && !empty($feature['requirements'])) {
                foreach ($feature['requirements'] as $requirement) {
                    if (!self::check_requirement($requirement)) {
                        $states[$feature_id] = false;
                        break;
                    }
                }
            }
        }

        return $states;
    }

    /**
     * Check a system requirement
     *
     * @param string $requirement Requirement to check
     * @return bool Whether requirement is met
     */
    private static function check_requirement(string $requirement): bool {
        switch ($requirement) {
            case 'gd':
                return extension_loaded('gd');
            case 'imagick':
                return extension_loaded('imagick');
            case 'curl':
                return extension_loaded('curl');
            default:
                return true;
        }
    }

    /**
     * Get all features
     *
     * @return array All registered features
     */
    public static function get_all_features(): array {
        return self::$features;
    }

    /**
     * Get features grouped by category
     *
     * @return array Features grouped by category
     */
    public static function get_features_by_category(): array {
        $categories = [
            'core' => [
                'label' => __('Core Features', 'pausatf-results'),
                'description' => __('Essential functionality that cannot be disabled.', 'pausatf-results'),
                'features' => [],
            ],
            'rules' => [
                'label' => __('USATF Rules & Standards', 'pausatf-results'),
                'description' => __('Competition rules, age divisions, and event standards.', 'pausatf-results'),
                'features' => [],
            ],
            'analytics' => [
                'label' => __('Analytics & Records', 'pausatf-results'),
                'description' => __('Performance tracking, rankings, and record management.', 'pausatf-results'),
                'features' => [],
            ],
            'athlete' => [
                'label' => __('Athlete Experience', 'pausatf-results'),
                'description' => __('Features for athletes to manage their profiles and results.', 'pausatf-results'),
                'features' => [],
            ],
            'competition' => [
                'label' => __('Competition Features', 'pausatf-results'),
                'description' => __('Series scoring, team management, and event features.', 'pausatf-results'),
                'features' => [],
            ],
            'race_director' => [
                'label' => __('Race Director Tools', 'pausatf-results'),
                'description' => __('Self-service tools for race organizers.', 'pausatf-results'),
                'features' => [],
            ],
            'import' => [
                'label' => __('Import & Export', 'pausatf-results'),
                'description' => __('Data import from various sources and export capabilities.', 'pausatf-results'),
                'features' => [],
            ],
            'integration' => [
                'label' => __('Third-Party Integrations', 'pausatf-results'),
                'description' => __('Connect with external timing systems and platforms.', 'pausatf-results'),
                'features' => [],
            ],
            'api' => [
                'label' => __('API & Webhooks', 'pausatf-results'),
                'description' => __('Programmatic access and external notifications.', 'pausatf-results'),
                'features' => [],
            ],
        ];

        foreach (self::$features as $feature_id => $feature) {
            $category = $feature['category'];
            if (isset($categories[$category])) {
                $categories[$category]['features'][$feature_id] = $feature;
            }
        }

        return $categories;
    }

    /**
     * Get feature info
     *
     * @param string $feature_id Feature identifier
     * @return array|null Feature info or null if not found
     */
    public static function get_feature(string $feature_id): ?array {
        return self::$features[$feature_id] ?? null;
    }

    /**
     * Get features that a feature depends on
     *
     * @param string $feature_id Feature identifier
     * @return array Dependencies
     */
    public static function get_dependencies(string $feature_id): array {
        $feature = self::get_feature($feature_id);
        return $feature['depends_on'] ?? [];
    }

    /**
     * Get features that depend on a given feature
     *
     * @param string $feature_id Feature identifier
     * @return array Dependents
     */
    public static function get_dependents(string $feature_id): array {
        $dependents = [];

        foreach (self::$features as $id => $feature) {
            if (in_array($feature_id, $feature['depends_on'] ?? [], true)) {
                $dependents[] = $id;
            }
        }

        return $dependents;
    }

    /**
     * Enable a feature
     *
     * @param string $feature_id Feature identifier
     * @return bool Whether feature was enabled
     */
    public static function enable_feature(string $feature_id): bool {
        $feature = self::get_feature($feature_id);

        if (!$feature || !$feature['toggleable']) {
            return false;
        }

        $states = get_option(self::OPTION_KEY, []);
        $states[$feature_id] = true;

        // Also enable dependencies
        foreach ($feature['depends_on'] ?? [] as $dependency) {
            $states[$dependency] = true;
        }

        self::$cached_states = null;
        return update_option(self::OPTION_KEY, $states);
    }

    /**
     * Disable a feature
     *
     * @param string $feature_id Feature identifier
     * @return bool Whether feature was disabled
     */
    public static function disable_feature(string $feature_id): bool {
        $feature = self::get_feature($feature_id);

        if (!$feature || !$feature['toggleable']) {
            return false;
        }

        $states = get_option(self::OPTION_KEY, []);
        $states[$feature_id] = false;

        // Also disable dependents
        foreach (self::get_dependents($feature_id) as $dependent) {
            $states[$dependent] = false;
        }

        self::$cached_states = null;
        return update_option(self::OPTION_KEY, $states);
    }

    /**
     * Check if all requirements for a feature are met
     *
     * @param string $feature_id Feature identifier
     * @return array Array with 'met' boolean and 'missing' array of missing requirements
     */
    public static function check_requirements(string $feature_id): array {
        $feature = self::get_feature($feature_id);
        $missing = [];

        if ($feature && !empty($feature['requirements'])) {
            foreach ($feature['requirements'] as $requirement) {
                if (!self::check_requirement($requirement)) {
                    $missing[] = $requirement;
                }
            }
        }

        return [
            'met' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Get human-readable name for a requirement
     *
     * @param string $requirement Requirement identifier
     * @return string Human-readable name
     */
    public static function get_requirement_name(string $requirement): string {
        $names = [
            'gd' => __('GD Image Library', 'pausatf-results'),
            'imagick' => __('ImageMagick', 'pausatf-results'),
            'curl' => __('cURL Extension', 'pausatf-results'),
        ];

        return $names[$requirement] ?? $requirement;
    }

    /**
     * Clear cached states (use after saving)
     */
    public static function clear_cache(): void {
        self::$cached_states = null;
    }
}

// Initialize feature manager
add_action('init', [FeatureManager::class, 'init'], 5);
