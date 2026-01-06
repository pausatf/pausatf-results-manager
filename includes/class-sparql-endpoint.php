<?php
/**
 * SPARQL Endpoint
 *
 * Provides a SPARQL query interface for the RDF data.
 * Supports SELECT, CONSTRUCT, ASK, and DESCRIBE query forms.
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SPARQL Endpoint class
 */
class SPARQLEndpoint {

    /**
     * Namespace prefixes
     */
    private const PREFIXES = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'owl' => 'http://www.w3.org/2002/07/owl#',
        'schema' => 'http://schema.org/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'pausatf' => 'https://www.pausatf.org/ontology/',
        'usatf' => 'https://www.usatf.org/ontology/',
    ];

    /**
     * Base URI
     *
     * @var string
     */
    private string $base_uri;

    /**
     * In-memory triple store
     *
     * @var array
     */
    private array $triples = [];

    /**
     * Query timeout in seconds
     *
     * @var int
     */
    private int $timeout = 30;

    /**
     * Maximum results
     *
     * @var int
     */
    private int $max_results = 10000;

    /**
     * Constructor
     */
    public function __construct() {
        $this->base_uri = trailingslashit(home_url()) . 'rdf/';
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_sparql_request']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Add rewrite rules
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^sparql/?$',
            'index.php?pausatf_sparql=1',
            'top'
        );
    }

    /**
     * Add query vars
     *
     * @param array $vars Query vars
     * @return array Modified query vars
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'pausatf_sparql';
        return $vars;
    }

    /**
     * Handle SPARQL request
     */
    public function handle_sparql_request(): void {
        if (!get_query_var('pausatf_sparql')) {
            return;
        }

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->send_cors_headers();
            exit;
        }

        // Get query from request
        $query = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

            if (strpos($content_type, 'application/sparql-query') !== false) {
                $query = file_get_contents('php://input');
            } elseif (strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
                $query = $_POST['query'] ?? '';
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $query = $_GET['query'] ?? '';
        }

        if (empty($query)) {
            $this->output_service_description();
            return;
        }

        // Execute query
        $result = $this->execute_query($query);

        // Determine output format
        $accept = $_SERVER['HTTP_ACCEPT'] ?? 'application/sparql-results+json';
        $format = $_GET['format'] ?? $this->detect_format($accept);

        $this->output_results($result, $format);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('pausatf/v1', '/sparql', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_sparql_query'],
                'permission_callback' => '__return_true',
                'args' => [
                    'query' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'format' => [
                        'default' => 'json',
                    ],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_sparql_query'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * REST API handler for SPARQL queries
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function rest_sparql_query(\WP_REST_Request $request): \WP_REST_Response {
        $query = $request->get_param('query');
        $format = $request->get_param('format') ?? 'json';

        if (empty($query)) {
            return new \WP_REST_Response(['error' => 'No query provided'], 400);
        }

        $result = $this->execute_query($query);

        if (isset($result['error'])) {
            return new \WP_REST_Response($result, 400);
        }

        $response = new \WP_REST_Response($result);
        $response->set_headers([
            'Content-Type' => $this->get_content_type($format),
            'Access-Control-Allow-Origin' => '*',
        ]);

        return $response;
    }

    /**
     * Execute SPARQL query
     *
     * @param string $query SPARQL query string
     * @return array Query results
     */
    public function execute_query(string $query): array {
        try {
            // Parse query
            $parsed = $this->parse_query($query);

            if (isset($parsed['error'])) {
                return $parsed;
            }

            // Load triples into memory
            $this->load_triples();

            // Execute based on query type
            switch ($parsed['type']) {
                case 'SELECT':
                    return $this->execute_select($parsed);
                case 'CONSTRUCT':
                    return $this->execute_construct($parsed);
                case 'ASK':
                    return $this->execute_ask($parsed);
                case 'DESCRIBE':
                    return $this->execute_describe($parsed);
                default:
                    return ['error' => 'Unsupported query type: ' . $parsed['type']];
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Parse SPARQL query
     *
     * @param string $query SPARQL query
     * @return array Parsed query structure
     */
    private function parse_query(string $query): array {
        $query = trim($query);

        // Extract and process PREFIX declarations
        $prefixes = self::PREFIXES;
        while (preg_match('/^PREFIX\s+(\w+):\s*<([^>]+)>\s*/i', $query, $matches)) {
            $prefixes[$matches[1]] = $matches[2];
            $query = substr($query, strlen($matches[0]));
        }

        // Extract BASE if present
        $base = $this->base_uri;
        if (preg_match('/^BASE\s*<([^>]+)>\s*/i', $query, $matches)) {
            $base = $matches[1];
            $query = substr($query, strlen($matches[0]));
        }

        // Determine query type
        if (preg_match('/^SELECT\s+(DISTINCT\s+)?(.+?)\s+WHERE\s*\{(.+)\}(.*)$/is', $query, $matches)) {
            return [
                'type' => 'SELECT',
                'distinct' => !empty($matches[1]),
                'variables' => $this->parse_select_variables($matches[2]),
                'where' => $this->parse_where_clause($matches[3], $prefixes, $base),
                'modifiers' => $this->parse_modifiers($matches[4]),
                'prefixes' => $prefixes,
                'base' => $base,
            ];
        } elseif (preg_match('/^CONSTRUCT\s*\{(.+?)\}\s*WHERE\s*\{(.+)\}(.*)$/is', $query, $matches)) {
            return [
                'type' => 'CONSTRUCT',
                'template' => $this->parse_where_clause($matches[1], $prefixes, $base),
                'where' => $this->parse_where_clause($matches[2], $prefixes, $base),
                'modifiers' => $this->parse_modifiers($matches[3]),
                'prefixes' => $prefixes,
                'base' => $base,
            ];
        } elseif (preg_match('/^ASK\s*\{(.+)\}$/is', $query, $matches)) {
            return [
                'type' => 'ASK',
                'where' => $this->parse_where_clause($matches[1], $prefixes, $base),
                'prefixes' => $prefixes,
                'base' => $base,
            ];
        } elseif (preg_match('/^DESCRIBE\s+(.+?)(?:\s+WHERE\s*\{(.+)\})?$/is', $query, $matches)) {
            return [
                'type' => 'DESCRIBE',
                'resources' => $this->parse_describe_resources($matches[1], $prefixes, $base),
                'where' => isset($matches[2]) ? $this->parse_where_clause($matches[2], $prefixes, $base) : [],
                'prefixes' => $prefixes,
                'base' => $base,
            ];
        }

        return ['error' => 'Could not parse query. Supported forms: SELECT, CONSTRUCT, ASK, DESCRIBE'];
    }

    /**
     * Parse SELECT variables
     *
     * @param string $vars Variable string
     * @return array Variables
     */
    private function parse_select_variables(string $vars): array {
        $vars = trim($vars);

        if ($vars === '*') {
            return ['*'];
        }

        preg_match_all('/\?(\w+)/', $vars, $matches);
        return $matches[1];
    }

    /**
     * Parse WHERE clause into triple patterns
     *
     * @param string $where WHERE clause content
     * @param array $prefixes Namespace prefixes
     * @param string $base Base URI
     * @return array Triple patterns
     */
    private function parse_where_clause(string $where, array $prefixes, string $base): array {
        $patterns = [];
        $where = trim($where);

        // Handle OPTIONAL
        $optional_patterns = [];
        while (preg_match('/OPTIONAL\s*\{([^{}]+)\}/i', $where, $matches)) {
            $optional_patterns[] = [
                'type' => 'optional',
                'patterns' => $this->parse_triple_patterns($matches[1], $prefixes, $base),
            ];
            $where = str_replace($matches[0], '', $where);
        }

        // Handle FILTER
        $filters = [];
        while (preg_match('/FILTER\s*\(([^)]+)\)/i', $where, $matches)) {
            $filters[] = $this->parse_filter($matches[1], $prefixes);
            $where = str_replace($matches[0], '', $where);
        }

        // Parse remaining as triple patterns
        $patterns = $this->parse_triple_patterns($where, $prefixes, $base);

        return [
            'patterns' => $patterns,
            'optional' => $optional_patterns,
            'filters' => $filters,
        ];
    }

    /**
     * Parse triple patterns
     *
     * @param string $content Pattern content
     * @param array $prefixes Namespace prefixes
     * @param string $base Base URI
     * @return array Triple patterns
     */
    private function parse_triple_patterns(string $content, array $prefixes, string $base): array {
        $patterns = [];

        // Split by . (end of triple) - careful with literal strings
        $statements = preg_split('/\s*\.\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($content));

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }

            // Parse subject predicate object
            // Handle ; for same subject, different predicates
            $triples = $this->parse_statement($statement, $prefixes, $base);
            $patterns = array_merge($patterns, $triples);
        }

        return $patterns;
    }

    /**
     * Parse a single statement (may contain ; for predicate-object lists)
     *
     * @param string $statement Statement string
     * @param array $prefixes Namespace prefixes
     * @param string $base Base URI
     * @return array Triple patterns
     */
    private function parse_statement(string $statement, array $prefixes, string $base): array {
        $patterns = [];

        // Match subject
        $subject = null;
        $rest = $statement;

        if (preg_match('/^(\??\w+|<[^>]+>|[a-z]+:\w+)\s+/i', $statement, $matches)) {
            $subject = $this->resolve_term($matches[1], $prefixes, $base);
            $rest = substr($statement, strlen($matches[0]));
        } else {
            return $patterns;
        }

        // Split by ; for predicate-object pairs
        $pairs = preg_split('/\s*;\s*/', $rest);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            // Match predicate object (handle , for multiple objects)
            if (preg_match('/^(\??\w+|<[^>]+>|[a-z]+:\w+|a)\s+(.+)$/i', $pair, $matches)) {
                $predicate = $matches[1];
                if ($predicate === 'a') {
                    $predicate = 'rdf:type';
                }
                $predicate = $this->resolve_term($predicate, $prefixes, $base);

                // Handle multiple objects separated by ,
                $objects = preg_split('/\s*,\s*/', $matches[2]);

                foreach ($objects as $obj) {
                    $obj = trim($obj);
                    if (empty($obj)) {
                        continue;
                    }

                    $patterns[] = [
                        'subject' => $subject,
                        'predicate' => $predicate,
                        'object' => $this->resolve_term($obj, $prefixes, $base),
                    ];
                }
            }
        }

        return $patterns;
    }

    /**
     * Resolve a term (variable, URI, prefixed name, or literal)
     *
     * @param string $term Term string
     * @param array $prefixes Namespace prefixes
     * @param string $base Base URI
     * @return array Term structure
     */
    private function resolve_term(string $term, array $prefixes, string $base): array {
        $term = trim($term);

        // Variable
        if (strpos($term, '?') === 0) {
            return ['type' => 'variable', 'value' => substr($term, 1)];
        }

        // Full URI
        if (preg_match('/^<([^>]+)>$/', $term, $matches)) {
            $uri = $matches[1];
            // Resolve relative URIs
            if (strpos($uri, 'http') !== 0 && strpos($uri, 'https') !== 0) {
                $uri = $base . $uri;
            }
            return ['type' => 'uri', 'value' => $uri];
        }

        // Prefixed name
        if (preg_match('/^([a-z]+):(\w+)$/i', $term, $matches)) {
            $prefix = $matches[1];
            $local = $matches[2];
            if (isset($prefixes[$prefix])) {
                return ['type' => 'uri', 'value' => $prefixes[$prefix] . $local];
            }
        }

        // Typed literal
        if (preg_match('/^"([^"]*)"(?:\^\^(.+))?$/', $term, $matches)) {
            $datatype = isset($matches[2]) ? $this->resolve_term($matches[2], $prefixes, $base)['value'] : null;
            return ['type' => 'literal', 'value' => $matches[1], 'datatype' => $datatype];
        }

        // Language-tagged literal
        if (preg_match('/^"([^"]*)"@(\w+)$/', $term, $matches)) {
            return ['type' => 'literal', 'value' => $matches[1], 'lang' => $matches[2]];
        }

        // Plain literal (in quotes)
        if (preg_match('/^"([^"]*)"$/', $term, $matches)) {
            return ['type' => 'literal', 'value' => $matches[1]];
        }

        // Numeric literal
        if (is_numeric($term)) {
            $datatype = strpos($term, '.') !== false ? 'http://www.w3.org/2001/XMLSchema#decimal' : 'http://www.w3.org/2001/XMLSchema#integer';
            return ['type' => 'literal', 'value' => $term, 'datatype' => $datatype];
        }

        // Default: treat as URI
        return ['type' => 'uri', 'value' => $term];
    }

    /**
     * Parse FILTER expression
     *
     * @param string $filter Filter expression
     * @param array $prefixes Namespace prefixes
     * @return array Filter structure
     */
    private function parse_filter(string $filter, array $prefixes): array {
        $filter = trim($filter);

        // regex filter
        if (preg_match('/regex\s*\(\s*\?(\w+)\s*,\s*"([^"]+)"(?:\s*,\s*"([^"]+)")?\s*\)/i', $filter, $matches)) {
            return [
                'type' => 'regex',
                'variable' => $matches[1],
                'pattern' => $matches[2],
                'flags' => $matches[3] ?? '',
            ];
        }

        // Comparison filter
        if (preg_match('/\?(\w+)\s*(=|!=|<|>|<=|>=)\s*(.+)/', $filter, $matches)) {
            return [
                'type' => 'comparison',
                'variable' => $matches[1],
                'operator' => $matches[2],
                'value' => $this->resolve_term(trim($matches[3]), $prefixes, $this->base_uri),
            ];
        }

        // CONTAINS filter
        if (preg_match('/contains\s*\(\s*\?(\w+)\s*,\s*"([^"]+)"\s*\)/i', $filter, $matches)) {
            return [
                'type' => 'contains',
                'variable' => $matches[1],
                'value' => $matches[2],
            ];
        }

        return ['type' => 'unknown', 'expression' => $filter];
    }

    /**
     * Parse query modifiers (ORDER BY, LIMIT, OFFSET)
     *
     * @param string $modifiers Modifier string
     * @return array Modifiers
     */
    private function parse_modifiers(string $modifiers): array {
        $result = [
            'order_by' => [],
            'limit' => null,
            'offset' => null,
        ];

        // ORDER BY
        if (preg_match('/ORDER\s+BY\s+((?:(?:ASC|DESC)\s*\(\s*\?\w+\s*\)|\?\w+\s*)+)/i', $modifiers, $matches)) {
            preg_match_all('/(ASC|DESC)?\s*\(?\s*\?(\w+)\s*\)?/i', $matches[1], $order_matches, PREG_SET_ORDER);
            foreach ($order_matches as $m) {
                $result['order_by'][] = [
                    'variable' => $m[2],
                    'direction' => strtoupper($m[1] ?? 'ASC'),
                ];
            }
        }

        // LIMIT
        if (preg_match('/LIMIT\s+(\d+)/i', $modifiers, $matches)) {
            $result['limit'] = min((int) $matches[1], $this->max_results);
        }

        // OFFSET
        if (preg_match('/OFFSET\s+(\d+)/i', $modifiers, $matches)) {
            $result['offset'] = (int) $matches[1];
        }

        return $result;
    }

    /**
     * Parse DESCRIBE resources
     *
     * @param string $resources Resource string
     * @param array $prefixes Namespace prefixes
     * @param string $base Base URI
     * @return array Resources
     */
    private function parse_describe_resources(string $resources, array $prefixes, string $base): array {
        $result = [];

        preg_match_all('/(\??\w+|<[^>]+>|[a-z]+:\w+)/i', $resources, $matches);

        foreach ($matches[1] as $resource) {
            $result[] = $this->resolve_term($resource, $prefixes, $base);
        }

        return $result;
    }

    /**
     * Load triples into memory
     */
    private function load_triples(): void {
        if (!empty($this->triples)) {
            return; // Already loaded
        }

        global $wpdb;

        // Load events
        $events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($events as $event) {
            $this->add_event_triples($event);
        }

        // Load athletes
        $athletes = get_posts([
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($athletes as $athlete) {
            $this->add_athlete_triples($athlete);
        }

        // Load results
        $table = $wpdb->prefix . 'pausatf_results';
        $results = $wpdb->get_results("SELECT * FROM $table");

        foreach ($results as $result) {
            $this->add_result_triples($result);
        }
    }

    /**
     * Add event triples to store
     *
     * @param \WP_Post $event Event post
     */
    private function add_event_triples(\WP_Post $event): void {
        $uri = $this->base_uri . 'events/' . $event->ID;

        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['schema'] . 'SportsEvent'];
        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['pausatf'] . 'AthleticsCompetition'];
        $this->triples[] = [$uri, self::PREFIXES['schema'] . 'name', ['literal', $event->post_title]];
        $this->triples[] = [$uri, self::PREFIXES['dcterms'] . 'identifier', ['literal', (string) $event->ID]];

        $event_date = get_post_meta($event->ID, '_event_date', true);
        if ($event_date) {
            $this->triples[] = [$uri, self::PREFIXES['schema'] . 'startDate', ['literal', $event_date, 'xsd:date']];
        }

        $location = get_post_meta($event->ID, '_event_location', true);
        if ($location) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'location', ['literal', $location]];
        }

        $distance = get_post_meta($event->ID, '_event_distance', true);
        if ($distance) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'distance', ['literal', $distance]];
        }

        // Event types
        $event_types = wp_get_post_terms($event->ID, 'pausatf_event_type');
        foreach ($event_types as $type) {
            $type_uri = $this->base_uri . 'event-types/' . $type->slug;
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'eventType', $type_uri];
            $this->triples[] = [$type_uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['skos'] . 'Concept'];
            $this->triples[] = [$type_uri, self::PREFIXES['skos'] . 'prefLabel', ['literal', $type->name]];
        }
    }

    /**
     * Add athlete triples to store
     *
     * @param \WP_Post $athlete Athlete post
     */
    private function add_athlete_triples(\WP_Post $athlete): void {
        $uri = $this->base_uri . 'athletes/' . $athlete->ID;

        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['schema'] . 'Person'];
        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['foaf'] . 'Person'];
        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['pausatf'] . 'Athlete'];
        $this->triples[] = [$uri, self::PREFIXES['schema'] . 'name', ['literal', $athlete->post_title]];
        $this->triples[] = [$uri, self::PREFIXES['foaf'] . 'name', ['literal', $athlete->post_title]];
        $this->triples[] = [$uri, self::PREFIXES['dcterms'] . 'identifier', ['literal', (string) $athlete->ID]];

        $gender = get_post_meta($athlete->ID, '_athlete_gender', true);
        if ($gender) {
            $this->triples[] = [$uri, self::PREFIXES['schema'] . 'gender', ['literal', $gender]];
        }

        $club = get_post_meta($athlete->ID, '_athlete_club', true);
        if ($club) {
            $club_uri = $this->base_uri . 'clubs/' . sanitize_title($club);
            $this->triples[] = [$uri, self::PREFIXES['schema'] . 'memberOf', $club_uri];
            $this->triples[] = [$club_uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['schema'] . 'SportsTeam'];
            $this->triples[] = [$club_uri, self::PREFIXES['schema'] . 'name', ['literal', $club]];
        }
    }

    /**
     * Add result triples to store
     *
     * @param object $result Result object
     */
    private function add_result_triples(object $result): void {
        $uri = $this->base_uri . 'results/' . $result->id;
        $event_uri = $this->base_uri . 'events/' . $result->event_id;

        $this->triples[] = [$uri, self::PREFIXES['rdf'] . 'type', self::PREFIXES['pausatf'] . 'CompetitionResult'];
        $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'inEvent', $event_uri];
        $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'athleteName', ['literal', $result->athlete_name]];

        if ($result->athlete_id) {
            $athlete_uri = $this->base_uri . 'athletes/' . $result->athlete_id;
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'athlete', $athlete_uri];
        }

        if ($result->place) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'overallPlace', ['literal', (string) $result->place, 'xsd:integer']];
        }

        if ($result->division) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'division', ['literal', $result->division]];
        }

        if ($result->division_place) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'divisionPlace', ['literal', (string) $result->division_place, 'xsd:integer']];
        }

        if ($result->time_seconds) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'timeInSeconds', ['literal', (string) $result->time_seconds, 'xsd:integer']];
        }

        if ($result->time_display) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'displayTime', ['literal', $result->time_display]];
        }

        if ($result->athlete_age) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'competitionAge', ['literal', (string) $result->athlete_age, 'xsd:integer']];
        }

        if ($result->club) {
            $this->triples[] = [$uri, self::PREFIXES['pausatf'] . 'representingClub', ['literal', $result->club]];
        }
    }

    /**
     * Execute SELECT query
     *
     * @param array $parsed Parsed query
     * @return array Results
     */
    private function execute_select(array $parsed): array {
        $bindings = $this->match_patterns($parsed['where']);

        // Apply filters
        if (!empty($parsed['where']['filters'])) {
            $bindings = $this->apply_filters($bindings, $parsed['where']['filters']);
        }

        // Select requested variables
        $variables = $parsed['variables'];
        if ($variables[0] === '*') {
            // Get all variables from bindings
            $variables = [];
            foreach ($bindings as $binding) {
                $variables = array_unique(array_merge($variables, array_keys($binding)));
            }
        }

        // Apply DISTINCT
        if ($parsed['distinct']) {
            $bindings = $this->apply_distinct($bindings, $variables);
        }

        // Apply ORDER BY
        if (!empty($parsed['modifiers']['order_by'])) {
            $bindings = $this->apply_order($bindings, $parsed['modifiers']['order_by']);
        }

        // Apply OFFSET and LIMIT
        $offset = $parsed['modifiers']['offset'] ?? 0;
        $limit = $parsed['modifiers']['limit'] ?? $this->max_results;
        $bindings = array_slice($bindings, $offset, $limit);

        // Format results
        $results = [];
        foreach ($bindings as $binding) {
            $row = [];
            foreach ($variables as $var) {
                if (isset($binding[$var])) {
                    $row[$var] = $this->format_binding_value($binding[$var]);
                } else {
                    $row[$var] = null;
                }
            }
            $results[] = $row;
        }

        return [
            'head' => ['vars' => $variables],
            'results' => ['bindings' => $results],
        ];
    }

    /**
     * Execute CONSTRUCT query
     *
     * @param array $parsed Parsed query
     * @return array Triples
     */
    private function execute_construct(array $parsed): array {
        $bindings = $this->match_patterns($parsed['where']);

        $triples = [];

        foreach ($bindings as $binding) {
            foreach ($parsed['template']['patterns'] as $pattern) {
                $subject = $this->substitute_binding($pattern['subject'], $binding);
                $predicate = $this->substitute_binding($pattern['predicate'], $binding);
                $object = $this->substitute_binding($pattern['object'], $binding);

                if ($subject && $predicate && $object) {
                    $triples[] = [$subject, $predicate, $object];
                }
            }
        }

        return ['triples' => $triples];
    }

    /**
     * Execute ASK query
     *
     * @param array $parsed Parsed query
     * @return array Boolean result
     */
    private function execute_ask(array $parsed): array {
        $bindings = $this->match_patterns($parsed['where']);

        return ['boolean' => !empty($bindings)];
    }

    /**
     * Execute DESCRIBE query
     *
     * @param array $parsed Parsed query
     * @return array Triples describing resources
     */
    private function execute_describe(array $parsed): array {
        $resources = [];

        // Get resources from query or WHERE clause
        foreach ($parsed['resources'] as $resource) {
            if ($resource['type'] === 'uri') {
                $resources[] = $resource['value'];
            } elseif ($resource['type'] === 'variable' && !empty($parsed['where'])) {
                $bindings = $this->match_patterns($parsed['where']);
                foreach ($bindings as $binding) {
                    if (isset($binding[$resource['value']])) {
                        $resources[] = $binding[$resource['value']];
                    }
                }
            }
        }

        $resources = array_unique($resources);

        // Get all triples about these resources
        $result_triples = [];

        foreach ($this->triples as $triple) {
            if (in_array($triple[0], $resources)) {
                $result_triples[] = $triple;
            }
        }

        return ['triples' => $result_triples];
    }

    /**
     * Match triple patterns against store
     *
     * @param array $where WHERE clause structure
     * @return array Bindings
     */
    private function match_patterns(array $where): array {
        $bindings = [[]]; // Start with empty binding

        // Match required patterns
        foreach ($where['patterns'] as $pattern) {
            $bindings = $this->join_pattern($bindings, $pattern);
        }

        // Match optional patterns
        foreach ($where['optional'] ?? [] as $optional) {
            $new_bindings = [];

            foreach ($bindings as $binding) {
                $optional_bindings = [$binding];

                foreach ($optional['patterns'] as $pattern) {
                    $optional_bindings = $this->join_pattern($optional_bindings, $pattern);
                }

                if (!empty($optional_bindings)) {
                    $new_bindings = array_merge($new_bindings, $optional_bindings);
                } else {
                    $new_bindings[] = $binding;
                }
            }

            $bindings = $new_bindings;
        }

        return $bindings;
    }

    /**
     * Join a pattern with existing bindings
     *
     * @param array $bindings Current bindings
     * @param array $pattern Triple pattern
     * @return array New bindings
     */
    private function join_pattern(array $bindings, array $pattern): array {
        $new_bindings = [];

        foreach ($bindings as $binding) {
            // Substitute known values into pattern
            $s = $this->substitute_binding($pattern['subject'], $binding);
            $p = $this->substitute_binding($pattern['predicate'], $binding);
            $o = $this->substitute_binding($pattern['object'], $binding);

            // Find matching triples
            foreach ($this->triples as $triple) {
                $match = true;
                $new_binding = $binding;

                // Match subject
                if ($pattern['subject']['type'] === 'variable') {
                    if ($s === null) {
                        $new_binding[$pattern['subject']['value']] = $triple[0];
                    } elseif ($s !== $triple[0]) {
                        $match = false;
                    }
                } elseif ($s !== $triple[0]) {
                    $match = false;
                }

                // Match predicate
                if ($match && $pattern['predicate']['type'] === 'variable') {
                    if ($p === null) {
                        $new_binding[$pattern['predicate']['value']] = $triple[1];
                    } elseif ($p !== $triple[1]) {
                        $match = false;
                    }
                } elseif ($match && $p !== $triple[1]) {
                    $match = false;
                }

                // Match object
                if ($match) {
                    $triple_obj = is_array($triple[2]) ? $triple[2][1] : $triple[2];
                    $pattern_obj = $pattern['object']['type'] === 'literal' ? $pattern['object']['value'] : $o;

                    if ($pattern['object']['type'] === 'variable') {
                        if ($o === null) {
                            $new_binding[$pattern['object']['value']] = is_array($triple[2]) ? $triple[2][1] : $triple[2];
                        } elseif ($o !== $triple_obj) {
                            $match = false;
                        }
                    } elseif ($pattern_obj !== $triple_obj) {
                        $match = false;
                    }
                }

                if ($match) {
                    $new_bindings[] = $new_binding;
                }
            }
        }

        return $new_bindings;
    }

    /**
     * Substitute binding values into a term
     *
     * @param array $term Term structure
     * @param array $binding Current binding
     * @return mixed Substituted value or null if variable not bound
     */
    private function substitute_binding(array $term, array $binding) {
        if ($term['type'] === 'variable') {
            return $binding[$term['value']] ?? null;
        }

        return $term['value'];
    }

    /**
     * Apply FILTER expressions
     *
     * @param array $bindings Current bindings
     * @param array $filters Filter expressions
     * @return array Filtered bindings
     */
    private function apply_filters(array $bindings, array $filters): array {
        foreach ($filters as $filter) {
            $bindings = array_filter($bindings, function($binding) use ($filter) {
                return $this->evaluate_filter($filter, $binding);
            });
        }

        return array_values($bindings);
    }

    /**
     * Evaluate a filter expression
     *
     * @param array $filter Filter structure
     * @param array $binding Current binding
     * @return bool Whether binding passes filter
     */
    private function evaluate_filter(array $filter, array $binding): bool {
        $var = $filter['variable'] ?? null;

        if (!$var || !isset($binding[$var])) {
            return false;
        }

        $value = $binding[$var];

        switch ($filter['type']) {
            case 'regex':
                $flags = '';
                if (strpos($filter['flags'] ?? '', 'i') !== false) {
                    $flags = 'i';
                }
                return preg_match('/' . $filter['pattern'] . '/' . $flags, $value) === 1;

            case 'contains':
                return strpos(strtolower($value), strtolower($filter['value'])) !== false;

            case 'comparison':
                $compare_value = $filter['value']['value'];

                switch ($filter['operator']) {
                    case '=':
                        return $value == $compare_value;
                    case '!=':
                        return $value != $compare_value;
                    case '<':
                        return $value < $compare_value;
                    case '>':
                        return $value > $compare_value;
                    case '<=':
                        return $value <= $compare_value;
                    case '>=':
                        return $value >= $compare_value;
                }
                break;
        }

        return true;
    }

    /**
     * Apply DISTINCT
     *
     * @param array $bindings Bindings
     * @param array $variables Variables to consider
     * @return array Distinct bindings
     */
    private function apply_distinct(array $bindings, array $variables): array {
        $seen = [];
        $result = [];

        foreach ($bindings as $binding) {
            $key = [];
            foreach ($variables as $var) {
                $key[] = $binding[$var] ?? '';
            }
            $key = implode("\0", $key);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $binding;
            }
        }

        return $result;
    }

    /**
     * Apply ORDER BY
     *
     * @param array $bindings Bindings
     * @param array $order_by Order specifications
     * @return array Sorted bindings
     */
    private function apply_order(array $bindings, array $order_by): array {
        usort($bindings, function($a, $b) use ($order_by) {
            foreach ($order_by as $order) {
                $var = $order['variable'];
                $val_a = $a[$var] ?? '';
                $val_b = $b[$var] ?? '';

                if ($val_a === $val_b) {
                    continue;
                }

                // Numeric comparison if both are numeric
                if (is_numeric($val_a) && is_numeric($val_b)) {
                    $cmp = $val_a - $val_b;
                } else {
                    $cmp = strcmp($val_a, $val_b);
                }

                if ($order['direction'] === 'DESC') {
                    $cmp = -$cmp;
                }

                if ($cmp !== 0) {
                    return $cmp < 0 ? -1 : 1;
                }
            }

            return 0;
        });

        return $bindings;
    }

    /**
     * Format binding value for output
     *
     * @param mixed $value Binding value
     * @return array Formatted value
     */
    private function format_binding_value($value): array {
        if (is_array($value)) {
            // Already formatted literal
            return [
                'type' => 'literal',
                'value' => $value[1],
                'datatype' => $value[2] ?? null,
            ];
        }

        // Check if URI or literal
        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
            return ['type' => 'uri', 'value' => $value];
        }

        return ['type' => 'literal', 'value' => $value];
    }

    /**
     * Send CORS headers
     */
    private function send_cors_headers(): void {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Detect output format from Accept header
     *
     * @param string $accept Accept header
     * @return string Format identifier
     */
    private function detect_format(string $accept): string {
        if (strpos($accept, 'application/sparql-results+xml') !== false) {
            return 'xml';
        } elseif (strpos($accept, 'text/csv') !== false) {
            return 'csv';
        } elseif (strpos($accept, 'text/tab-separated-values') !== false) {
            return 'tsv';
        }

        return 'json';
    }

    /**
     * Get content type for format
     *
     * @param string $format Format identifier
     * @return string Content type
     */
    private function get_content_type(string $format): string {
        $types = [
            'json' => 'application/sparql-results+json',
            'xml' => 'application/sparql-results+xml',
            'csv' => 'text/csv',
            'tsv' => 'text/tab-separated-values',
        ];

        return $types[$format] ?? 'application/sparql-results+json';
    }

    /**
     * Output query results
     *
     * @param array $result Query result
     * @param string $format Output format
     */
    private function output_results(array $result, string $format): void {
        $this->send_cors_headers();
        header('Content-Type: ' . $this->get_content_type($format));

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        switch ($format) {
            case 'xml':
                echo $this->results_to_xml($result);
                break;
            case 'csv':
                echo $this->results_to_csv($result);
                break;
            case 'tsv':
                echo $this->results_to_csv($result, "\t");
                break;
            case 'json':
            default:
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                break;
        }

        exit;
    }

    /**
     * Convert results to SPARQL Results XML
     *
     * @param array $result Results
     * @return string XML content
     */
    private function results_to_xml(array $result): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $sparql = $doc->createElementNS('http://www.w3.org/2005/sparql-results#', 'sparql');
        $doc->appendChild($sparql);

        // Boolean result
        if (isset($result['boolean'])) {
            $boolean = $doc->createElement('boolean', $result['boolean'] ? 'true' : 'false');
            $sparql->appendChild($boolean);
            return $doc->saveXML();
        }

        // SELECT results
        if (isset($result['head'])) {
            $head = $doc->createElement('head');
            $sparql->appendChild($head);

            foreach ($result['head']['vars'] as $var) {
                $variable = $doc->createElement('variable');
                $variable->setAttribute('name', $var);
                $head->appendChild($variable);
            }

            $results = $doc->createElement('results');
            $sparql->appendChild($results);

            foreach ($result['results']['bindings'] as $binding) {
                $resultEl = $doc->createElement('result');
                $results->appendChild($resultEl);

                foreach ($binding as $var => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $bindingEl = $doc->createElement('binding');
                    $bindingEl->setAttribute('name', $var);
                    $resultEl->appendChild($bindingEl);

                    if ($value['type'] === 'uri') {
                        $uri = $doc->createElement('uri', $value['value']);
                        $bindingEl->appendChild($uri);
                    } else {
                        $literal = $doc->createElement('literal', $value['value']);
                        if (isset($value['datatype'])) {
                            $literal->setAttribute('datatype', $value['datatype']);
                        }
                        if (isset($value['xml:lang'])) {
                            $literal->setAttribute('xml:lang', $value['xml:lang']);
                        }
                        $bindingEl->appendChild($literal);
                    }
                }
            }
        }

        return $doc->saveXML();
    }

    /**
     * Convert results to CSV/TSV
     *
     * @param array $result Results
     * @param string $delimiter Field delimiter
     * @return string CSV content
     */
    private function results_to_csv(array $result, string $delimiter = ','): string {
        if (!isset($result['head'])) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Header row
        fputcsv($output, $result['head']['vars'], $delimiter);

        // Data rows
        foreach ($result['results']['bindings'] as $binding) {
            $row = [];
            foreach ($result['head']['vars'] as $var) {
                $value = $binding[$var] ?? null;
                $row[] = $value ? $value['value'] : '';
            }
            fputcsv($output, $row, $delimiter);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Output SPARQL service description
     */
    private function output_service_description(): void {
        $this->send_cors_headers();
        header('Content-Type: text/turtle; charset=utf-8');

        $service_uri = home_url('/sparql');
        $dataset_uri = $this->base_uri;

        echo "@prefix sd: <http://www.w3.org/ns/sparql-service-description#> .\n";
        echo "@prefix void: <http://rdfs.org/ns/void#> .\n";
        echo "@prefix dcterms: <http://purl.org/dc/terms/> .\n\n";

        echo "<{$service_uri}> a sd:Service ;\n";
        echo "    sd:endpoint <{$service_uri}> ;\n";
        echo "    sd:supportedLanguage sd:SPARQL11Query ;\n";
        echo "    sd:resultFormat <http://www.w3.org/ns/formats/SPARQL_Results_JSON>,\n";
        echo "                    <http://www.w3.org/ns/formats/SPARQL_Results_XML>,\n";
        echo "                    <http://www.w3.org/ns/formats/SPARQL_Results_CSV> ;\n";
        echo "    sd:feature sd:BasicFederatedQuery ;\n";
        echo "    sd:defaultDataset [\n";
        echo "        a sd:Dataset ;\n";
        echo "        sd:defaultGraph [\n";
        echo "            a sd:Graph ;\n";
        echo "            void:dataDump <{$dataset_uri}> ;\n";
        echo "            dcterms:title \"PA-USATF Competition Results\" ;\n";
        echo "        ]\n";
        echo "    ] .\n";

        exit;
    }

    /**
     * Get example queries for documentation/testing
     *
     * @return array Example queries
     */
    public static function get_example_queries(): array {
        return [
            [
                'title' => 'List all events',
                'description' => 'Get all events with their names and dates',
                'query' => "PREFIX schema: <http://schema.org/>
PREFIX pausatf: <https://www.pausatf.org/ontology/>

SELECT ?event ?name ?date
WHERE {
    ?event a schema:SportsEvent ;
           schema:name ?name .
    OPTIONAL { ?event schema:startDate ?date }
}
ORDER BY DESC(?date)
LIMIT 20",
            ],
            [
                'title' => 'Find athlete results',
                'description' => 'Get all results for a specific athlete',
                'query' => 'PREFIX pausatf: <https://www.pausatf.org/ontology/>

SELECT ?result ?event ?place ?time
WHERE {
    ?result a pausatf:CompetitionResult ;
            pausatf:athleteName "John Smith" ;
            pausatf:inEvent ?event .
    OPTIONAL { ?result pausatf:overallPlace ?place }
    OPTIONAL { ?result pausatf:displayTime ?time }
}',
            ],
            [
                'title' => 'Top finishers',
                'description' => 'Get top 10 finishers across all events',
                'query' => "PREFIX pausatf: <https://www.pausatf.org/ontology/>

SELECT ?name ?event ?place ?time
WHERE {
    ?result a pausatf:CompetitionResult ;
            pausatf:athleteName ?name ;
            pausatf:overallPlace ?place ;
            pausatf:inEvent ?event .
    OPTIONAL { ?result pausatf:displayTime ?time }
    FILTER (?place <= 3)
}
ORDER BY ?place
LIMIT 100",
            ],
            [
                'title' => 'Results by division',
                'description' => 'Get results for a specific age division',
                'query' => 'PREFIX pausatf: <https://www.pausatf.org/ontology/>

SELECT ?name ?event ?place ?time
WHERE {
    ?result a pausatf:CompetitionResult ;
            pausatf:athleteName ?name ;
            pausatf:division "M40-44" ;
            pausatf:inEvent ?event .
    OPTIONAL { ?result pausatf:divisionPlace ?place }
    OPTIONAL { ?result pausatf:displayTime ?time }
}
ORDER BY ?place',
            ],
            [
                'title' => 'Search by name',
                'description' => 'Find athletes whose name contains a string',
                'query' => 'PREFIX pausatf: <https://www.pausatf.org/ontology/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>

SELECT ?athlete ?name
WHERE {
    ?athlete a pausatf:Athlete ;
             foaf:name ?name .
    FILTER(regex(?name, "smith", "i"))
}',
            ],
            [
                'title' => 'Count results per event',
                'description' => 'Get number of finishers for each event',
                'query' => "PREFIX schema: <http://schema.org/>
PREFIX pausatf: <https://www.pausatf.org/ontology/>

SELECT ?event ?eventName (COUNT(?result) AS ?finishers)
WHERE {
    ?event a schema:SportsEvent ;
           schema:name ?eventName .
    ?result pausatf:inEvent ?event .
}
GROUP BY ?event ?eventName
ORDER BY DESC(?finishers)
LIMIT 20",
            ],
            [
                'title' => 'Describe an event',
                'description' => 'Get all information about a specific event',
                'query' => 'PREFIX schema: <http://schema.org/>

DESCRIBE ?event
WHERE {
    ?event a schema:SportsEvent ;
           schema:name "Example Race 5K" .
}',
            ],
            [
                'title' => 'Check for athlete',
                'description' => 'Check if an athlete exists in the database',
                'query' => 'PREFIX pausatf: <https://www.pausatf.org/ontology/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>

ASK {
    ?athlete a pausatf:Athlete ;
             foaf:name "John Smith" .
}',
            ],
        ];
    }
}

// Initialize SPARQL Endpoint
add_action('init', function() {
    if (FeatureManager::is_enabled('rdf_support')) {
        new SPARQLEndpoint();
    }
}, 20);
