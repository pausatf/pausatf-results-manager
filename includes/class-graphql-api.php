<?php
/**
 * GraphQL API
 *
 * Provides GraphQL endpoint for flexible queries
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GraphQL API implementation
 */
class GraphQLAPI {
    /**
     * GraphQL endpoint
     */
    private const ENDPOINT = 'pausatf-graphql';

    public function __construct() {
        add_action('init', [$this, 'register_endpoint']);
        add_action('template_redirect', [$this, 'handle_request']);
    }

    /**
     * Register GraphQL endpoint
     */
    public function register_endpoint(): void {
        add_rewrite_rule(
            '^' . self::ENDPOINT . '/?$',
            'index.php?pausatf_graphql=1',
            'top'
        );

        add_rewrite_tag('%pausatf_graphql%', '1');
    }

    /**
     * Handle GraphQL request
     */
    public function handle_request(): void {
        if (!get_query_var('pausatf_graphql')) {
            return;
        }

        // Set JSON content type
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        // Handle preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }

        // Get query
        $input = json_decode(file_get_contents('php://input'), true);
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? [];

        if (empty($query)) {
            echo json_encode(['errors' => [['message' => 'No query provided']]]);
            exit;
        }

        // Execute query
        $result = $this->execute_query($query, $variables);

        echo json_encode($result);
        exit;
    }

    /**
     * Execute GraphQL query
     */
    private function execute_query(string $query, array $variables): array {
        // Parse query
        $parsed = $this->parse_query($query);

        if (isset($parsed['errors'])) {
            return $parsed;
        }

        $data = [];

        foreach ($parsed['fields'] as $field => $args) {
            $resolver = $this->get_resolver($field);
            if ($resolver) {
                $data[$field] = $resolver($args, $variables);
            }
        }

        return ['data' => $data];
    }

    /**
     * Simple query parser
     */
    private function parse_query(string $query): array {
        // This is a simplified parser - real implementation would use proper GraphQL library

        $fields = [];

        // Match field patterns
        if (preg_match_all('/(\w+)(?:\(([^)]*)\))?\s*\{([^}]+)\}/', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field_name = $match[1];
                $args_str = $match[2] ?? '';
                $subfields = $match[3] ?? '';

                $args = $this->parse_args($args_str);
                $args['_fields'] = array_map('trim', explode("\n", trim($subfields)));

                $fields[$field_name] = $args;
            }
        }

        return ['fields' => $fields];
    }

    /**
     * Parse arguments string
     */
    private function parse_args(string $args_str): array {
        $args = [];

        if (preg_match_all('/(\w+):\s*(?:"([^"]+)"|(\d+)|(\$\w+))/', $args_str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2] ?: $match[3] ?: $match[4];
                $args[$key] = $value;
            }
        }

        return $args;
    }

    /**
     * Get resolver for field
     */
    private function get_resolver(string $field): ?callable {
        $resolvers = [
            'events' => [$this, 'resolve_events'],
            'event' => [$this, 'resolve_event'],
            'athletes' => [$this, 'resolve_athletes'],
            'athlete' => [$this, 'resolve_athlete'],
            'results' => [$this, 'resolve_results'],
            'records' => [$this, 'resolve_records'],
            'rankings' => [$this, 'resolve_rankings'],
            'grandPrix' => [$this, 'resolve_grand_prix'],
        ];

        return $resolvers[$field] ?? null;
    }

    /**
     * Resolve events query
     */
    private function resolve_events(array $args, array $variables): array {
        $query_args = [
            'post_type' => 'pausatf_event',
            'posts_per_page' => min((int) ($args['limit'] ?? 20), 100),
            'post_status' => 'publish',
        ];

        if (!empty($args['season'])) {
            $query_args['tax_query'] = [[
                'taxonomy' => 'pausatf_season',
                'field' => 'name',
                'terms' => $args['season'],
            ]];
        }

        if (!empty($args['type'])) {
            $query_args['tax_query'][] = [
                'taxonomy' => 'pausatf_event_type',
                'field' => 'slug',
                'terms' => $args['type'],
            ];
        }

        $events = get_posts($query_args);

        return array_map([$this, 'format_event'], $events);
    }

    /**
     * Resolve single event
     */
    private function resolve_event(array $args, array $variables): ?array {
        $id = (int) ($args['id'] ?? $variables['id'] ?? 0);

        if (!$id) {
            return null;
        }

        $event = get_post($id);

        if (!$event || $event->post_type !== 'pausatf_event') {
            return null;
        }

        $formatted = $this->format_event($event);

        // Include results if requested
        if (in_array('results', $args['_fields'] ?? [])) {
            $formatted['results'] = $this->get_event_results($id);
        }

        return $formatted;
    }

    /**
     * Format event for response
     */
    private function format_event(\WP_Post $event): array {
        $types = wp_get_object_terms($event->ID, 'pausatf_event_type', ['fields' => 'names']);
        $seasons = wp_get_object_terms($event->ID, 'pausatf_season', ['fields' => 'names']);

        return [
            'id' => $event->ID,
            'name' => $event->post_title,
            'slug' => $event->post_name,
            'date' => get_post_meta($event->ID, '_pausatf_event_date', true),
            'location' => get_post_meta($event->ID, '_pausatf_event_location', true),
            'type' => !empty($types) ? $types[0] : null,
            'season' => !empty($seasons) ? $seasons[0] : null,
            'resultCount' => (int) get_post_meta($event->ID, '_pausatf_result_count', true),
            'url' => get_permalink($event->ID),
        ];
    }

    /**
     * Get results for event
     */
    private function get_event_results(int $event_id, int $limit = 100): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d ORDER BY place ASC LIMIT %d",
            $event_id,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'format_result'], $results);
    }

    /**
     * Resolve athletes query
     */
    private function resolve_athletes(array $args, array $variables): array {
        $query_args = [
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => min((int) ($args['limit'] ?? 20), 100),
            'post_status' => 'publish',
        ];

        if (!empty($args['search'])) {
            $query_args['s'] = sanitize_text_field($args['search']);
        }

        $athletes = get_posts($query_args);

        return array_map([$this, 'format_athlete'], $athletes);
    }

    /**
     * Resolve single athlete
     */
    private function resolve_athlete(array $args, array $variables): ?array {
        $id = (int) ($args['id'] ?? $variables['id'] ?? 0);

        if (!$id) {
            return null;
        }

        $athlete = get_post($id);

        if (!$athlete || $athlete->post_type !== 'pausatf_athlete') {
            return null;
        }

        return $this->format_athlete($athlete);
    }

    /**
     * Format athlete for response
     */
    private function format_athlete(\WP_Post $athlete): array {
        return [
            'id' => $athlete->ID,
            'name' => $athlete->post_title,
            'slug' => $athlete->post_name,
            'club' => get_post_meta($athlete->ID, '_pausatf_club', true),
            'usatfMember' => (bool) get_post_meta($athlete->ID, '_pausatf_usatf_verified', true),
            'url' => get_permalink($athlete->ID),
        ];
    }

    /**
     * Resolve results query
     */
    private function resolve_results(array $args, array $variables): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $where = ['1=1'];
        $params = [];

        if (!empty($args['eventId'])) {
            $where[] = 'event_id = %d';
            $params[] = (int) $args['eventId'];
        }

        if (!empty($args['athleteName'])) {
            $where[] = 'athlete_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['athleteName']) . '%';
        }

        if (!empty($args['division'])) {
            $where[] = 'division = %s';
            $params[] = $args['division'];
        }

        $limit = min((int) ($args['limit'] ?? 50), 500);
        $offset = (int) ($args['offset'] ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY place ASC LIMIT %d OFFSET %d",
            ...$params
        ), ARRAY_A);

        return array_map([$this, 'format_result'], $results);
    }

    /**
     * Format result for response
     */
    private function format_result(array $result): array {
        return [
            'id' => (int) $result['id'],
            'eventId' => (int) $result['event_id'],
            'athleteId' => $result['athlete_id'] ? (int) $result['athlete_id'] : null,
            'athleteName' => $result['athlete_name'],
            'athleteAge' => $result['athlete_age'] ? (int) $result['athlete_age'] : null,
            'place' => $result['place'] ? (int) $result['place'] : null,
            'division' => $result['division'],
            'divisionPlace' => $result['division_place'] ? (int) $result['division_place'] : null,
            'time' => $result['time_display'],
            'timeSeconds' => $result['time_seconds'] ? (int) $result['time_seconds'] : null,
            'club' => $result['club'],
            'bib' => $result['bib'],
        ];
    }

    /**
     * Resolve records query
     */
    private function resolve_records(array $args, array $variables): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_records';

        $where = ['verified = 1'];
        $params = [];

        if (!empty($args['event'])) {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }

        if (!empty($args['gender'])) {
            $where[] = 'gender = %s';
            $params[] = strtoupper($args['gender']);
        }

        if (!empty($args['division'])) {
            $where[] = 'division_code = %s';
            $params[] = $args['division'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY event, gender, division_code";

        $records = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return array_map(function ($record) {
            return [
                'id' => (int) $record['id'],
                'event' => $record['event'],
                'venueType' => $record['venue_type'],
                'gender' => $record['gender'],
                'division' => $record['division_code'],
                'performance' => $record['performance_display'],
                'athleteName' => $record['athlete_name'],
                'athleteAge' => $record['athlete_age'] ? (int) $record['athlete_age'] : null,
                'date' => $record['record_date'],
                'location' => $record['location'],
                'wind' => $record['wind_reading'],
            ];
        }, $records);
    }

    /**
     * Resolve rankings query
     */
    private function resolve_rankings(array $args, array $variables): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_rankings';

        $where = ['1=1'];
        $params = [];

        if (!empty($args['event'])) {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }

        if (!empty($args['season'])) {
            $where[] = 'season_year = %d';
            $params[] = (int) $args['season'];
        }

        if (!empty($args['division'])) {
            $where[] = 'division_code = %s';
            $params[] = $args['division'];
        }

        if (!empty($args['gender'])) {
            $where[] = 'gender = %s';
            $params[] = strtoupper($args['gender']);
        }

        $limit = min((int) ($args['limit'] ?? 50), 200);
        $params[] = $limit;

        $where_sql = implode(' AND ', $where);

        $rankings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY rank_position ASC LIMIT %d",
            ...$params
        ), ARRAY_A);

        return array_map(function ($ranking) {
            return [
                'rank' => (int) $ranking['rank_position'],
                'athleteId' => $ranking['athlete_id'] ? (int) $ranking['athlete_id'] : null,
                'athleteName' => $ranking['athlete_name'],
                'event' => $ranking['event'],
                'division' => $ranking['division_code'],
                'performance' => $ranking['best_performance_display'],
                'performanceDate' => $ranking['best_date'],
                'racesCount' => (int) $ranking['performances_count'],
                'ageGradedScore' => $ranking['age_graded_score'] ? (float) $ranking['age_graded_score'] : null,
            ];
        }, $rankings);
    }

    /**
     * Resolve Grand Prix query
     */
    private function resolve_grand_prix(array $args, array $variables): ?array {
        $grand_prix = new GrandPrix();

        $series_id = $args['seriesId'] ?? null;
        $slug = $args['slug'] ?? null;
        $year = (int) ($args['year'] ?? date('Y'));

        if ($series_id) {
            $series = $grand_prix->get_series((int) $series_id);
        } elseif ($slug) {
            $series = $grand_prix->get_series($slug, $year);
        } else {
            return null;
        }

        if (!$series) {
            return null;
        }

        $standings = $grand_prix->get_standings($series['id'], [
            'division' => $args['division'] ?? null,
            'gender' => $args['gender'] ?? null,
            'limit' => (int) ($args['limit'] ?? 100),
        ]);

        return [
            'id' => (int) $series['id'],
            'name' => $series['name'],
            'season' => (int) $series['season_year'],
            'status' => $series['status'],
            'minRaces' => (int) $series['min_races'],
            'standings' => array_map(function ($s) {
                return [
                    'rank' => (int) $s['rank_position'],
                    'athleteName' => $s['athlete_name'],
                    'division' => $s['division'],
                    'gender' => $s['gender'],
                    'totalPoints' => (float) $s['total_points'],
                    'racesCompleted' => (int) $s['races_completed'],
                ];
            }, $standings),
        ];
    }

    /**
     * Get GraphQL schema documentation
     */
    public function get_schema(): string {
        return <<<'GRAPHQL'
type Query {
    # Get list of events
    events(season: String, type: String, limit: Int): [Event]

    # Get single event by ID
    event(id: ID!): Event

    # Search athletes
    athletes(search: String, limit: Int): [Athlete]

    # Get single athlete
    athlete(id: ID!): Athlete

    # Query results
    results(eventId: ID, athleteName: String, division: String, limit: Int, offset: Int): [Result]

    # Get association records
    records(event: String, gender: String, division: String): [Record]

    # Get rankings
    rankings(event: String!, season: Int, division: String, gender: String, limit: Int): [Ranking]

    # Get Grand Prix series
    grandPrix(seriesId: ID, slug: String, year: Int, division: String, gender: String): GrandPrixSeries
}

type Event {
    id: ID!
    name: String!
    slug: String!
    date: String
    location: String
    type: String
    season: String
    resultCount: Int
    url: String
    results: [Result]
}

type Athlete {
    id: ID!
    name: String!
    slug: String!
    club: String
    usatfMember: Boolean
    url: String
}

type Result {
    id: ID!
    eventId: ID!
    athleteId: ID
    athleteName: String!
    athleteAge: Int
    place: Int
    division: String
    divisionPlace: Int
    time: String
    timeSeconds: Int
    club: String
    bib: String
}

type Record {
    id: ID!
    event: String!
    venueType: String!
    gender: String!
    division: String!
    performance: String!
    athleteName: String!
    athleteAge: Int
    date: String!
    location: String
    wind: Float
}

type Ranking {
    rank: Int!
    athleteId: ID
    athleteName: String!
    event: String!
    division: String!
    performance: String!
    performanceDate: String
    racesCount: Int
    ageGradedScore: Float
}

type GrandPrixSeries {
    id: ID!
    name: String!
    season: Int!
    status: String!
    minRaces: Int!
    standings: [GrandPrixStanding]
}

type GrandPrixStanding {
    rank: Int!
    athleteName: String!
    division: String!
    gender: String!
    totalPoints: Float!
    racesCompleted: Int!
}
GRAPHQL;
    }
}

// Initialize
new GraphQLAPI();
