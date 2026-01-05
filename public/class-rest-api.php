<?php
/**
 * REST API endpoints for results
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API controller
 */
class RestAPI {
    private const NAMESPACE = 'pausatf/v1';

    private static ?RestAPI $instance = null;

    public static function instance(): RestAPI {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        // Get event results
        register_rest_route(self::NAMESPACE, '/events/(?P<id>\d+)/results', [
            'methods' => 'GET',
            'callback' => [$this, 'get_event_results'],
            'permission_callback' => '__return_true',
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'division' => [
                    'type' => 'string',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 100,
                ],
            ],
        ]);

        // Search athletes
        register_rest_route(self::NAMESPACE, '/athletes/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search_athletes'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => [
                    'required' => true,
                    'type' => 'string',
                    'minLength' => 2,
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 20,
                ],
            ],
        ]);

        // Get athlete results
        register_rest_route(self::NAMESPACE, '/athletes/(?P<name>.+)/results', [
            'methods' => 'GET',
            'callback' => [$this, 'get_athlete_results'],
            'permission_callback' => '__return_true',
            'args' => [
                'name' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        // Get leaderboard
        register_rest_route(self::NAMESPACE, '/leaderboard', [
            'methods' => 'GET',
            'callback' => [$this, 'get_leaderboard'],
            'permission_callback' => '__return_true',
            'args' => [
                'division' => [
                    'type' => 'string',
                ],
                'year' => [
                    'type' => 'integer',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                ],
            ],
        ]);

        // Import endpoint (requires auth)
        register_rest_route(self::NAMESPACE, '/import', [
            'methods' => 'POST',
            'callback' => [$this, 'import_results'],
            'permission_callback' => [$this, 'can_import'],
            'args' => [
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'uri',
                ],
            ],
        ]);

        // Get divisions
        register_rest_route(self::NAMESPACE, '/divisions', [
            'methods' => 'GET',
            'callback' => [$this, 'get_divisions'],
            'permission_callback' => '__return_true',
        ]);

        // Get seasons/years
        register_rest_route(self::NAMESPACE, '/seasons', [
            'methods' => 'GET',
            'callback' => [$this, 'get_seasons'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get results for an event
     */
    public function get_event_results(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $event_id = $request->get_param('id');
        $division = $request->get_param('division');
        $limit = min($request->get_param('limit'), 500);

        $where = ['event_id = %d'];
        $params = [$event_id];

        if ($division) {
            $where[] = 'division = %s';
            $params[] = $division;
        }

        $where_clause = implode(' AND ', $where);
        $params[] = $limit;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pausatf_results
             WHERE {$where_clause}
             ORDER BY place
             LIMIT %d",
            ...$params
        ), ARRAY_A);

        // Get event info
        $event = get_post($event_id);
        $event_date = get_post_meta($event_id, '_pausatf_event_date', true);

        return new \WP_REST_Response([
            'event' => [
                'id' => $event_id,
                'name' => $event ? $event->post_title : '',
                'date' => $event_date,
            ],
            'results' => $results,
            'count' => count($results),
        ]);
    }

    /**
     * Search for athletes
     */
    public function search_athletes(\WP_REST_Request $request): \WP_REST_Response {
        $query = $request->get_param('q');
        $limit = min($request->get_param('limit'), 50);

        $athlete_db = new AthleteDatabase();
        $results = $athlete_db->search($query, $limit);

        return new \WP_REST_Response([
            'athletes' => $results,
            'count' => count($results),
        ]);
    }

    /**
     * Get athlete results history
     */
    public function get_athlete_results(\WP_REST_Request $request): \WP_REST_Response {
        $name = urldecode($request->get_param('name'));

        $athlete_db = new AthleteDatabase();
        $results = $athlete_db->get_athlete_results($name);
        $stats = $athlete_db->get_athlete_stats($name);

        return new \WP_REST_Response([
            'athlete' => $name,
            'stats' => $stats,
            'results' => $results,
            'count' => count($results),
        ]);
    }

    /**
     * Get leaderboard
     */
    public function get_leaderboard(\WP_REST_Request $request): \WP_REST_Response {
        $division = $request->get_param('division');
        $year = $request->get_param('year');
        $limit = min($request->get_param('limit'), 100);

        $athlete_db = new AthleteDatabase();
        $leaders = $athlete_db->get_leaderboard($division, $year, $limit);

        return new \WP_REST_Response([
            'division' => $division,
            'year' => $year,
            'leaders' => $leaders,
            'count' => count($leaders),
        ]);
    }

    /**
     * Import results from URL
     */
    public function import_results(\WP_REST_Request $request): \WP_REST_Response {
        $url = $request->get_param('url');

        $importer = new ResultsImporter();
        $result = $importer->import_from_url($url);

        if ($result['success']) {
            return new \WP_REST_Response($result);
        }

        return new \WP_REST_Response($result, 400);
    }

    /**
     * Get all divisions
     */
    public function get_divisions(\WP_REST_Request $request): \WP_REST_Response {
        $terms = get_terms([
            'taxonomy' => 'pausatf_division',
            'hide_empty' => true,
        ]);

        $divisions = [];
        foreach ($terms as $term) {
            $divisions[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => $term->count,
            ];
        }

        return new \WP_REST_Response(['divisions' => $divisions]);
    }

    /**
     * Get all seasons/years
     */
    public function get_seasons(\WP_REST_Request $request): \WP_REST_Response {
        $terms = get_terms([
            'taxonomy' => 'pausatf_season',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'DESC',
        ]);

        $seasons = [];
        foreach ($terms as $term) {
            $seasons[] = [
                'year' => (int) $term->name,
                'count' => $term->count,
            ];
        }

        return new \WP_REST_Response(['seasons' => $seasons]);
    }

    /**
     * Check if user can import
     */
    public function can_import(): bool {
        return current_user_can('manage_options');
    }
}

// Initialize
add_action('init', function() {
    RestAPI::instance();
});
