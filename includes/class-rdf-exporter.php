<?php
/**
 * RDF Exporter
 *
 * Exports results, athletes, and events in RDF format using standard schemas.
 *
 * Supported Schemas:
 * - Schema.org (SportsEvent, Person, SportsOrganization, SportsTeam)
 * - FOAF (Friend of a Friend) for people
 * - Dublin Core for metadata
 * - SKOS for taxonomies
 * - Custom PAUSATF ontology for athletics-specific concepts
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RDF Exporter class
 */
class RDFExporter {

    /**
     * Namespace URIs for RDF vocabularies
     */
    private const NAMESPACES = [
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'owl' => 'http://www.w3.org/2002/07/owl#',
        'schema' => 'http://schema.org/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'skos' => 'http://www.w3.org/2004/02/skos/core#',
        'geo' => 'http://www.w3.org/2003/01/geo/wgs84_pos#',
        'time' => 'http://www.w3.org/2006/time#',
        'org' => 'http://www.w3.org/ns/org#',
        'prov' => 'http://www.w3.org/ns/prov#',
        'pausatf' => 'https://www.pausatf.org/ontology/',
        'usatf' => 'https://www.usatf.org/ontology/',
    ];

    /**
     * Base URI for resources
     *
     * @var string
     */
    private string $base_uri;

    /**
     * Output format
     *
     * @var string
     */
    private string $format = 'turtle';

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
        // Add RDF endpoints
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_rdf_request']);

        // Add RDF link headers
        add_action('wp_head', [$this, 'add_rdf_link_headers']);

        // REST API endpoint for RDF
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Add rewrite rules for RDF endpoints
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^rdf/events/?$',
            'index.php?pausatf_rdf=events',
            'top'
        );
        add_rewrite_rule(
            '^rdf/events/([0-9]+)/?$',
            'index.php?pausatf_rdf=event&pausatf_rdf_id=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^rdf/athletes/?$',
            'index.php?pausatf_rdf=athletes',
            'top'
        );
        add_rewrite_rule(
            '^rdf/athletes/([0-9]+)/?$',
            'index.php?pausatf_rdf=athlete&pausatf_rdf_id=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^rdf/results/?$',
            'index.php?pausatf_rdf=results',
            'top'
        );
        add_rewrite_rule(
            '^rdf/results/([0-9]+)/?$',
            'index.php?pausatf_rdf=result&pausatf_rdf_id=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^rdf/ontology/?$',
            'index.php?pausatf_rdf=ontology',
            'top'
        );
        add_rewrite_rule(
            '^rdf/void/?$',
            'index.php?pausatf_rdf=void',
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
        $vars[] = 'pausatf_rdf';
        $vars[] = 'pausatf_rdf_id';
        $vars[] = 'pausatf_rdf_format';
        return $vars;
    }

    /**
     * Handle RDF request
     */
    public function handle_rdf_request(): void {
        $rdf_type = get_query_var('pausatf_rdf');

        if (empty($rdf_type)) {
            return;
        }

        $id = get_query_var('pausatf_rdf_id');
        $format = get_query_var('pausatf_rdf_format') ?: $this->detect_format();

        $this->format = $format;

        switch ($rdf_type) {
            case 'events':
                $this->output_events_rdf();
                break;
            case 'event':
                $this->output_event_rdf((int) $id);
                break;
            case 'athletes':
                $this->output_athletes_rdf();
                break;
            case 'athlete':
                $this->output_athlete_rdf((int) $id);
                break;
            case 'results':
                $this->output_results_rdf();
                break;
            case 'result':
                $this->output_result_rdf((int) $id);
                break;
            case 'ontology':
                $this->output_ontology();
                break;
            case 'void':
                $this->output_void_description();
                break;
        }
    }

    /**
     * Detect requested RDF format from Accept header
     *
     * @return string Format identifier
     */
    private function detect_format(): string {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? 'text/turtle';

        if (strpos($accept, 'application/rdf+xml') !== false) {
            return 'rdfxml';
        } elseif (strpos($accept, 'application/ld+json') !== false || strpos($accept, 'application/json') !== false) {
            return 'jsonld';
        } elseif (strpos($accept, 'application/n-triples') !== false) {
            return 'ntriples';
        } elseif (strpos($accept, 'application/n-quads') !== false) {
            return 'nquads';
        }

        return 'turtle';
    }

    /**
     * Get content type for format
     *
     * @return string Content type
     */
    private function get_content_type(): string {
        $types = [
            'turtle' => 'text/turtle; charset=utf-8',
            'rdfxml' => 'application/rdf+xml; charset=utf-8',
            'jsonld' => 'application/ld+json; charset=utf-8',
            'ntriples' => 'application/n-triples; charset=utf-8',
            'nquads' => 'application/n-quads; charset=utf-8',
        ];

        return $types[$this->format] ?? 'text/turtle; charset=utf-8';
    }

    /**
     * Output RDF with proper headers
     *
     * @param string $content RDF content
     */
    private function output_rdf(string $content): void {
        header('Content-Type: ' . $this->get_content_type());
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
        echo $content;
        exit;
    }

    /**
     * Add RDF link headers to HTML pages
     */
    public function add_rdf_link_headers(): void {
        if (is_singular('pausatf_event')) {
            $id = get_the_ID();
            echo '<link rel="alternate" type="text/turtle" href="' . esc_url($this->base_uri . 'events/' . $id) . '" />' . "\n";
            echo '<link rel="alternate" type="application/ld+json" href="' . esc_url($this->base_uri . 'events/' . $id . '?format=jsonld') . '" />' . "\n";
        } elseif (is_singular('pausatf_athlete')) {
            $id = get_the_ID();
            echo '<link rel="alternate" type="text/turtle" href="' . esc_url($this->base_uri . 'athletes/' . $id) . '" />' . "\n";
            echo '<link rel="alternate" type="application/ld+json" href="' . esc_url($this->base_uri . 'athletes/' . $id . '?format=jsonld') . '" />' . "\n";
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('pausatf/v1', '/rdf/(?P<type>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_rdf'],
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return in_array($param, ['events', 'athletes', 'results', 'ontology', 'void']);
                    },
                ],
                'format' => [
                    'default' => 'turtle',
                    'validate_callback' => function($param) {
                        return in_array($param, ['turtle', 'rdfxml', 'jsonld', 'ntriples']);
                    },
                ],
            ],
        ]);

        register_rest_route('pausatf/v1', '/rdf/(?P<type>[a-z]+)/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_rdf_single'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * REST API handler for RDF collections
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function rest_get_rdf(\WP_REST_Request $request): \WP_REST_Response {
        $type = $request->get_param('type');
        $this->format = $request->get_param('format') ?? 'turtle';

        $content = '';

        switch ($type) {
            case 'events':
                $content = $this->generate_events_rdf();
                break;
            case 'athletes':
                $content = $this->generate_athletes_rdf();
                break;
            case 'results':
                $content = $this->generate_results_rdf();
                break;
            case 'ontology':
                $content = $this->generate_ontology();
                break;
            case 'void':
                $content = $this->generate_void_description();
                break;
        }

        $response = new \WP_REST_Response($content);
        $response->set_headers(['Content-Type' => $this->get_content_type()]);
        return $response;
    }

    /**
     * REST API handler for single RDF resource
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response object
     */
    public function rest_get_rdf_single(\WP_REST_Request $request): \WP_REST_Response {
        $type = $request->get_param('type');
        $id = (int) $request->get_param('id');
        $this->format = $request->get_param('format') ?? 'turtle';

        $content = '';

        switch ($type) {
            case 'events':
            case 'event':
                $content = $this->generate_event_rdf($id);
                break;
            case 'athletes':
            case 'athlete':
                $content = $this->generate_athlete_rdf($id);
                break;
            case 'results':
            case 'result':
                $content = $this->generate_result_rdf($id);
                break;
        }

        $response = new \WP_REST_Response($content);
        $response->set_headers(['Content-Type' => $this->get_content_type()]);
        return $response;
    }

    /**
     * Output all events as RDF
     */
    private function output_events_rdf(): void {
        $this->output_rdf($this->generate_events_rdf());
    }

    /**
     * Generate RDF for all events
     *
     * @return string RDF content
     */
    public function generate_events_rdf(): string {
        $events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $triples = [];

        foreach ($events as $event) {
            $triples = array_merge($triples, $this->event_to_triples($event));
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Output single event as RDF
     *
     * @param int $id Event ID
     */
    private function output_event_rdf(int $id): void {
        $this->output_rdf($this->generate_event_rdf($id));
    }

    /**
     * Generate RDF for single event
     *
     * @param int $id Event ID
     * @return string RDF content
     */
    public function generate_event_rdf(int $id): string {
        $event = get_post($id);

        if (!$event || $event->post_type !== 'pausatf_event') {
            return '';
        }

        $triples = $this->event_to_triples($event);

        // Include results for this event
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %d ORDER BY place ASC",
            $id
        ));

        foreach ($results as $result) {
            $triples = array_merge($triples, $this->result_to_triples($result, $event));
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Convert event post to RDF triples
     *
     * @param \WP_Post $event Event post
     * @return array Array of triples
     */
    private function event_to_triples(\WP_Post $event): array {
        $uri = $this->base_uri . 'events/' . $event->ID;
        $triples = [];

        // Type declarations (Schema.org SportsEvent)
        $triples[] = [$uri, 'rdf:type', 'schema:SportsEvent'];
        $triples[] = [$uri, 'rdf:type', 'pausatf:AthleticsCompetition'];

        // Basic properties
        $triples[] = [$uri, 'schema:name', $this->literal($event->post_title)];
        $triples[] = [$uri, 'schema:description', $this->literal(wp_strip_all_tags($event->post_content))];
        $triples[] = [$uri, 'schema:url', $this->literal(get_permalink($event->ID))];
        $triples[] = [$uri, 'dcterms:identifier', $this->literal((string) $event->ID)];

        // Date
        $event_date = get_post_meta($event->ID, '_event_date', true);
        if ($event_date) {
            $triples[] = [$uri, 'schema:startDate', $this->literal($event_date, 'xsd:date')];
        }

        // Location
        $location = get_post_meta($event->ID, '_event_location', true);
        if ($location) {
            $location_uri = $uri . '/location';
            $triples[] = [$uri, 'schema:location', $location_uri];
            $triples[] = [$location_uri, 'rdf:type', 'schema:Place'];
            $triples[] = [$location_uri, 'schema:name', $this->literal($location)];
        }

        // Event type taxonomy
        $event_types = wp_get_post_terms($event->ID, 'pausatf_event_type');
        foreach ($event_types as $type) {
            $type_uri = $this->base_uri . 'event-types/' . $type->slug;
            $triples[] = [$uri, 'pausatf:eventType', $type_uri];
            $triples[] = [$type_uri, 'rdf:type', 'skos:Concept'];
            $triples[] = [$type_uri, 'skos:prefLabel', $this->literal($type->name)];
        }

        // Season
        $seasons = wp_get_post_terms($event->ID, 'pausatf_season');
        foreach ($seasons as $season) {
            $triples[] = [$uri, 'pausatf:season', $this->literal($season->name)];
        }

        // Distance/event specifics
        $distance = get_post_meta($event->ID, '_event_distance', true);
        if ($distance) {
            $triples[] = [$uri, 'pausatf:distance', $this->literal($distance)];
        }

        // Organizer (PA-USATF)
        $org_uri = 'https://www.pausatf.org/#organization';
        $triples[] = [$uri, 'schema:organizer', $org_uri];
        $triples[] = [$org_uri, 'rdf:type', 'schema:SportsOrganization'];
        $triples[] = [$org_uri, 'schema:name', $this->literal('Pennsylvania Association of USA Track & Field')];

        // Dublin Core metadata
        $triples[] = [$uri, 'dc:creator', $this->literal('PA-USATF')];
        $triples[] = [$uri, 'dcterms:created', $this->literal($event->post_date, 'xsd:dateTime')];
        $triples[] = [$uri, 'dcterms:modified', $this->literal($event->post_modified, 'xsd:dateTime')];

        return $triples;
    }

    /**
     * Output all athletes as RDF
     */
    private function output_athletes_rdf(): void {
        $this->output_rdf($this->generate_athletes_rdf());
    }

    /**
     * Generate RDF for all athletes
     *
     * @return string RDF content
     */
    public function generate_athletes_rdf(): string {
        $athletes = get_posts([
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $triples = [];

        foreach ($athletes as $athlete) {
            $triples = array_merge($triples, $this->athlete_to_triples($athlete));
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Output single athlete as RDF
     *
     * @param int $id Athlete ID
     */
    private function output_athlete_rdf(int $id): void {
        $this->output_rdf($this->generate_athlete_rdf($id));
    }

    /**
     * Generate RDF for single athlete
     *
     * @param int $id Athlete ID
     * @return string RDF content
     */
    public function generate_athlete_rdf(int $id): string {
        $athlete = get_post($id);

        if (!$athlete || $athlete->post_type !== 'pausatf_athlete') {
            return '';
        }

        $triples = $this->athlete_to_triples($athlete);

        // Include results for this athlete
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.athlete_id = %d
             ORDER BY r.created_at DESC",
            $id
        ));

        foreach ($results as $result) {
            $event = get_post($result->event_id);
            if ($event) {
                $triples = array_merge($triples, $this->result_to_triples($result, $event));
            }
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Convert athlete post to RDF triples
     *
     * @param \WP_Post $athlete Athlete post
     * @return array Array of triples
     */
    private function athlete_to_triples(\WP_Post $athlete): array {
        $uri = $this->base_uri . 'athletes/' . $athlete->ID;
        $triples = [];

        // Type declarations (Schema.org Person + FOAF Person)
        $triples[] = [$uri, 'rdf:type', 'schema:Person'];
        $triples[] = [$uri, 'rdf:type', 'foaf:Person'];
        $triples[] = [$uri, 'rdf:type', 'pausatf:Athlete'];

        // Name
        $triples[] = [$uri, 'schema:name', $this->literal($athlete->post_title)];
        $triples[] = [$uri, 'foaf:name', $this->literal($athlete->post_title)];

        // Parse name parts if possible
        $name_parts = explode(' ', $athlete->post_title, 2);
        if (count($name_parts) >= 2) {
            $triples[] = [$uri, 'schema:givenName', $this->literal($name_parts[0])];
            $triples[] = [$uri, 'foaf:givenName', $this->literal($name_parts[0])];
            $triples[] = [$uri, 'schema:familyName', $this->literal($name_parts[1])];
            $triples[] = [$uri, 'foaf:familyName', $this->literal($name_parts[1])];
        }

        // URL
        $triples[] = [$uri, 'schema:url', $this->literal(get_permalink($athlete->ID))];
        $triples[] = [$uri, 'foaf:page', $this->literal(get_permalink($athlete->ID))];

        // Identifier
        $triples[] = [$uri, 'dcterms:identifier', $this->literal((string) $athlete->ID)];

        // Gender
        $gender = get_post_meta($athlete->ID, '_athlete_gender', true);
        if ($gender) {
            $triples[] = [$uri, 'schema:gender', $this->literal($gender)];
            $triples[] = [$uri, 'foaf:gender', $this->literal($gender)];
        }

        // Birth year (not full date for privacy)
        $birth_year = get_post_meta($athlete->ID, '_athlete_birth_year', true);
        if ($birth_year) {
            $triples[] = [$uri, 'pausatf:birthYear', $this->literal($birth_year, 'xsd:gYear')];
        }

        // Club affiliation
        $club = get_post_meta($athlete->ID, '_athlete_club', true);
        if ($club) {
            $club_uri = $this->base_uri . 'clubs/' . sanitize_title($club);
            $triples[] = [$uri, 'schema:memberOf', $club_uri];
            $triples[] = [$club_uri, 'rdf:type', 'schema:SportsTeam'];
            $triples[] = [$club_uri, 'schema:name', $this->literal($club)];
        }

        // USATF membership
        $usatf_number = get_post_meta($athlete->ID, '_usatf_membership', true);
        if ($usatf_number) {
            $triples[] = [$uri, 'usatf:membershipNumber', $this->literal($usatf_number)];
        }

        // Divisions
        $divisions = wp_get_post_terms($athlete->ID, 'pausatf_division');
        foreach ($divisions as $division) {
            $div_uri = $this->base_uri . 'divisions/' . $division->slug;
            $triples[] = [$uri, 'pausatf:competesIn', $div_uri];
            $triples[] = [$div_uri, 'rdf:type', 'pausatf:AgeDivision'];
            $triples[] = [$div_uri, 'skos:prefLabel', $this->literal($division->name)];
        }

        // Dublin Core
        $triples[] = [$uri, 'dcterms:created', $this->literal($athlete->post_date, 'xsd:dateTime')];

        return $triples;
    }

    /**
     * Output all results as RDF
     */
    private function output_results_rdf(): void {
        $this->output_rdf($this->generate_results_rdf());
    }

    /**
     * Generate RDF for all results
     *
     * @param int $limit Maximum results to return
     * @return string RDF content
     */
    public function generate_results_rdf(int $limit = 1000): string {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             ORDER BY r.created_at DESC
             LIMIT %d",
            $limit
        ));

        $triples = [];

        foreach ($results as $result) {
            $event = get_post($result->event_id);
            if ($event) {
                $triples = array_merge($triples, $this->result_to_triples($result, $event));
            }
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Output single result as RDF
     *
     * @param int $id Result ID
     */
    private function output_result_rdf(int $id): void {
        $this->output_rdf($this->generate_result_rdf($id));
    }

    /**
     * Generate RDF for single result
     *
     * @param int $id Result ID
     * @return string RDF content
     */
    public function generate_result_rdf(int $id): string {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));

        if (!$result) {
            return '';
        }

        $event = get_post($result->event_id);
        if (!$event) {
            return '';
        }

        $triples = $this->result_to_triples($result, $event);

        return $this->serialize_triples($triples);
    }

    /**
     * Convert result to RDF triples
     *
     * @param object $result Result object
     * @param \WP_Post $event Event post
     * @return array Array of triples
     */
    private function result_to_triples(object $result, \WP_Post $event): array {
        $uri = $this->base_uri . 'results/' . $result->id;
        $event_uri = $this->base_uri . 'events/' . $event->ID;
        $triples = [];

        // Type declarations
        $triples[] = [$uri, 'rdf:type', 'pausatf:CompetitionResult'];
        $triples[] = [$uri, 'rdf:type', 'schema:SportsActivityLocation']; // Not ideal, Schema.org lacks good result type

        // Link to event
        $triples[] = [$uri, 'pausatf:inEvent', $event_uri];
        $triples[] = [$uri, 'schema:event', $event_uri];

        // Athlete
        if ($result->athlete_id) {
            $athlete_uri = $this->base_uri . 'athletes/' . $result->athlete_id;
            $triples[] = [$uri, 'pausatf:athlete', $athlete_uri];
            $triples[] = [$uri, 'schema:participant', $athlete_uri];
        }

        $triples[] = [$uri, 'pausatf:athleteName', $this->literal($result->athlete_name)];

        // Place/rank
        if ($result->place) {
            $triples[] = [$uri, 'pausatf:overallPlace', $this->literal((string) $result->place, 'xsd:integer')];
        }

        // Division
        if ($result->division) {
            $div_uri = $this->base_uri . 'divisions/' . sanitize_title($result->division);
            $triples[] = [$uri, 'pausatf:division', $div_uri];
            $triples[] = [$div_uri, 'rdf:type', 'pausatf:AgeDivision'];
            $triples[] = [$div_uri, 'rdfs:label', $this->literal($result->division)];
        }

        if ($result->division_place) {
            $triples[] = [$uri, 'pausatf:divisionPlace', $this->literal((string) $result->division_place, 'xsd:integer')];
        }

        // Time
        if ($result->time_seconds) {
            $triples[] = [$uri, 'pausatf:timeInSeconds', $this->literal((string) $result->time_seconds, 'xsd:integer')];

            // Also express as ISO 8601 duration
            $duration = $this->seconds_to_duration($result->time_seconds);
            $triples[] = [$uri, 'schema:duration', $this->literal($duration, 'xsd:duration')];
        }

        if ($result->time_display) {
            $triples[] = [$uri, 'pausatf:displayTime', $this->literal($result->time_display)];
        }

        // Age
        if ($result->athlete_age) {
            $triples[] = [$uri, 'pausatf:competitionAge', $this->literal((string) $result->athlete_age, 'xsd:integer')];
        }

        // Club
        if ($result->club) {
            $club_uri = $this->base_uri . 'clubs/' . sanitize_title($result->club);
            $triples[] = [$uri, 'pausatf:representingClub', $club_uri];
            $triples[] = [$club_uri, 'rdf:type', 'schema:SportsTeam'];
            $triples[] = [$club_uri, 'schema:name', $this->literal($result->club)];
        }

        // Bib number
        if ($result->bib) {
            $triples[] = [$uri, 'pausatf:bibNumber', $this->literal($result->bib)];
        }

        // Points (for scored events)
        if ($result->points) {
            $triples[] = [$uri, 'pausatf:points', $this->literal((string) $result->points, 'xsd:decimal')];
        }

        // Pace
        if ($result->pace) {
            $triples[] = [$uri, 'pausatf:pace', $this->literal($result->pace)];
        }

        // Provenance
        $triples[] = [$uri, 'dcterms:created', $this->literal($result->created_at, 'xsd:dateTime')];
        $triples[] = [$uri, 'prov:wasGeneratedBy', $this->literal('PA-USATF Results Import')];

        return $triples;
    }

    /**
     * Output PAUSATF ontology
     */
    private function output_ontology(): void {
        $this->output_rdf($this->generate_ontology());
    }

    /**
     * Generate PAUSATF ontology definition
     *
     * @return string RDF content
     */
    public function generate_ontology(): string {
        $ont_uri = 'https://www.pausatf.org/ontology/';
        $triples = [];

        // Ontology metadata
        $triples[] = [$ont_uri, 'rdf:type', 'owl:Ontology'];
        $triples[] = [$ont_uri, 'rdfs:label', $this->literal('PA-USATF Results Ontology')];
        $triples[] = [$ont_uri, 'rdfs:comment', $this->literal('Ontology for Pennsylvania Association of USA Track & Field competition results')];
        $triples[] = [$ont_uri, 'dcterms:creator', $this->literal('PA-USATF')];
        $triples[] = [$ont_uri, 'owl:versionInfo', $this->literal('1.0')];

        // Classes
        $classes = [
            'AthleticsCompetition' => 'An athletics (track & field, road running, etc.) competition event',
            'Athlete' => 'A competitor in athletics events',
            'CompetitionResult' => 'The result of an athlete in a competition',
            'AgeDivision' => 'An age-based competition division (e.g., Masters 40-44)',
            'EventType' => 'Type of athletics event (XC, Road Race, Track, etc.)',
            'Record' => 'An association record performance',
            'Club' => 'An athletics club or team',
        ];

        foreach ($classes as $class => $comment) {
            $class_uri = $ont_uri . $class;
            $triples[] = [$class_uri, 'rdf:type', 'owl:Class'];
            $triples[] = [$class_uri, 'rdfs:label', $this->literal($class)];
            $triples[] = [$class_uri, 'rdfs:comment', $this->literal($comment)];
        }

        // Subclass relationships
        $triples[] = [$ont_uri . 'AthleticsCompetition', 'rdfs:subClassOf', 'schema:SportsEvent'];
        $triples[] = [$ont_uri . 'Athlete', 'rdfs:subClassOf', 'schema:Person'];
        $triples[] = [$ont_uri . 'Club', 'rdfs:subClassOf', 'schema:SportsTeam'];

        // Properties
        $properties = [
            'inEvent' => ['CompetitionResult', 'AthleticsCompetition', 'The event this result is from'],
            'athlete' => ['CompetitionResult', 'Athlete', 'The athlete who achieved this result'],
            'athleteName' => ['CompetitionResult', 'xsd:string', 'Name of the athlete'],
            'overallPlace' => ['CompetitionResult', 'xsd:integer', 'Overall finishing place'],
            'division' => ['CompetitionResult', 'AgeDivision', 'The age division for this result'],
            'divisionPlace' => ['CompetitionResult', 'xsd:integer', 'Place within age division'],
            'timeInSeconds' => ['CompetitionResult', 'xsd:integer', 'Finishing time in seconds'],
            'displayTime' => ['CompetitionResult', 'xsd:string', 'Human-readable time display'],
            'competitionAge' => ['CompetitionResult', 'xsd:integer', 'Age of athlete at time of competition'],
            'representingClub' => ['CompetitionResult', 'Club', 'Club represented in the event'],
            'bibNumber' => ['CompetitionResult', 'xsd:string', 'Athlete bib/race number'],
            'points' => ['CompetitionResult', 'xsd:decimal', 'Points scored (for scored events)'],
            'pace' => ['CompetitionResult', 'xsd:string', 'Pace per mile/km'],
            'eventType' => ['AthleticsCompetition', 'EventType', 'Type of athletics event'],
            'distance' => ['AthleticsCompetition', 'xsd:string', 'Event distance'],
            'season' => ['AthleticsCompetition', 'xsd:string', 'Competition season/year'],
            'birthYear' => ['Athlete', 'xsd:gYear', 'Year of birth'],
            'competesIn' => ['Athlete', 'AgeDivision', 'Age divisions the athlete competes in'],
        ];

        foreach ($properties as $prop => $info) {
            $prop_uri = $ont_uri . $prop;
            $triples[] = [$prop_uri, 'rdf:type', 'owl:ObjectProperty'];
            $triples[] = [$prop_uri, 'rdfs:label', $this->literal($prop)];
            $triples[] = [$prop_uri, 'rdfs:comment', $this->literal($info[2])];
            $triples[] = [$prop_uri, 'rdfs:domain', $ont_uri . $info[0]];

            if (strpos($info[1], 'xsd:') === 0) {
                $triples[] = [$prop_uri, 'rdf:type', 'owl:DatatypeProperty'];
                $triples[] = [$prop_uri, 'rdfs:range', $info[1]];
            } else {
                $triples[] = [$prop_uri, 'rdfs:range', $ont_uri . $info[1]];
            }
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Output VoID (Vocabulary of Interlinked Datasets) description
     */
    private function output_void_description(): void {
        $this->output_rdf($this->generate_void_description());
    }

    /**
     * Generate VoID dataset description
     *
     * @return string RDF content
     */
    public function generate_void_description(): string {
        $dataset_uri = $this->base_uri;
        $triples = [];

        // Dataset metadata
        $triples[] = [$dataset_uri, 'rdf:type', 'http://rdfs.org/ns/void#Dataset'];
        $triples[] = [$dataset_uri, 'dcterms:title', $this->literal('PA-USATF Competition Results')];
        $triples[] = [$dataset_uri, 'dcterms:description', $this->literal('Competition results from Pennsylvania Association of USA Track & Field events')];
        $triples[] = [$dataset_uri, 'dcterms:publisher', $this->literal('PA-USATF')];
        $triples[] = [$dataset_uri, 'dcterms:license', $this->literal('https://creativecommons.org/licenses/by/4.0/')];

        // SPARQL endpoint
        $sparql_uri = home_url('/sparql');
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#sparqlEndpoint', $sparql_uri];

        // Data dumps
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#dataDump', $this->base_uri . 'events'];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#dataDump', $this->base_uri . 'athletes'];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#dataDump', $this->base_uri . 'results'];

        // Vocabularies used
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#vocabulary', 'http://schema.org/'];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#vocabulary', 'http://xmlns.com/foaf/0.1/'];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#vocabulary', 'http://purl.org/dc/terms/'];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#vocabulary', 'https://www.pausatf.org/ontology/'];

        // Statistics
        global $wpdb;
        $results_table = $wpdb->prefix . 'pausatf_results';
        $result_count = $wpdb->get_var("SELECT COUNT(*) FROM $results_table");
        $event_count = wp_count_posts('pausatf_event')->publish;
        $athlete_count = wp_count_posts('pausatf_athlete')->publish;

        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#triples', $this->literal((string) (($result_count * 15) + ($event_count * 10) + ($athlete_count * 10)), 'xsd:integer')];
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#entities', $this->literal((string) ($result_count + $event_count + $athlete_count), 'xsd:integer')];

        // Subsets
        $events_subset = $this->base_uri . 'events';
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#subset', $events_subset];
        $triples[] = [$events_subset, 'rdf:type', 'http://rdfs.org/ns/void#Dataset'];
        $triples[] = [$events_subset, 'dcterms:title', $this->literal('PA-USATF Events')];

        $athletes_subset = $this->base_uri . 'athletes';
        $triples[] = [$dataset_uri, 'http://rdfs.org/ns/void#subset', $athletes_subset];
        $triples[] = [$athletes_subset, 'rdf:type', 'http://rdfs.org/ns/void#Dataset'];
        $triples[] = [$athletes_subset, 'dcterms:title', $this->literal('PA-USATF Athletes')];

        return $this->serialize_triples($triples);
    }

    /**
     * Create a literal value
     *
     * @param string $value The value
     * @param string|null $datatype Optional datatype URI
     * @param string|null $lang Optional language tag
     * @return array Literal array
     */
    private function literal(string $value, ?string $datatype = null, ?string $lang = null): array {
        return [
            'type' => 'literal',
            'value' => $value,
            'datatype' => $datatype,
            'lang' => $lang,
        ];
    }

    /**
     * Convert seconds to ISO 8601 duration
     *
     * @param int $seconds Time in seconds
     * @return string ISO 8601 duration
     */
    private function seconds_to_duration(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $duration = 'PT';
        if ($hours > 0) {
            $duration .= $hours . 'H';
        }
        if ($minutes > 0) {
            $duration .= $minutes . 'M';
        }
        if ($secs > 0 || ($hours === 0 && $minutes === 0)) {
            $duration .= $secs . 'S';
        }

        return $duration;
    }

    /**
     * Serialize triples to requested format
     *
     * @param array $triples Array of triples
     * @return string Serialized RDF
     */
    private function serialize_triples(array $triples): string {
        switch ($this->format) {
            case 'jsonld':
                return $this->serialize_jsonld($triples);
            case 'rdfxml':
                return $this->serialize_rdfxml($triples);
            case 'ntriples':
                return $this->serialize_ntriples($triples);
            case 'turtle':
            default:
                return $this->serialize_turtle($triples);
        }
    }

    /**
     * Serialize to Turtle format
     *
     * @param array $triples Array of triples
     * @return string Turtle content
     */
    private function serialize_turtle(array $triples): string {
        $output = '';

        // Prefixes
        foreach (self::NAMESPACES as $prefix => $uri) {
            $output .= "@prefix {$prefix}: <{$uri}> .\n";
        }
        $output .= "@base <{$this->base_uri}> .\n\n";

        // Group triples by subject
        $grouped = [];
        foreach ($triples as $triple) {
            $subject = $triple[0];
            if (!isset($grouped[$subject])) {
                $grouped[$subject] = [];
            }
            $grouped[$subject][] = [$triple[1], $triple[2]];
        }

        // Output grouped triples
        foreach ($grouped as $subject => $predicates) {
            // Use relative URI if possible
            if (strpos($subject, $this->base_uri) === 0) {
                $subject = '<' . substr($subject, strlen($this->base_uri)) . '>';
            } elseif (!$this->is_prefixed($subject)) {
                $subject = '<' . $subject . '>';
            }

            $output .= "{$subject}\n";

            $pred_count = count($predicates);
            foreach ($predicates as $i => $po) {
                $predicate = $this->compact_uri($po[0]);
                $object = $this->format_object($po[1]);

                $separator = ($i === $pred_count - 1) ? ' .' : ' ;';
                $output .= "    {$predicate} {$object}{$separator}\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Serialize to N-Triples format
     *
     * @param array $triples Array of triples
     * @return string N-Triples content
     */
    private function serialize_ntriples(array $triples): string {
        $output = '';

        foreach ($triples as $triple) {
            $subject = $this->expand_uri($triple[0]);
            $predicate = $this->expand_uri($triple[1]);
            $object = $triple[2];

            if (is_array($object) && $object['type'] === 'literal') {
                $value = addcslashes($object['value'], "\"\\\n\r\t");
                if ($object['datatype']) {
                    $datatype = $this->expand_uri($object['datatype']);
                    $obj_str = "\"{$value}\"^^<{$datatype}>";
                } elseif ($object['lang']) {
                    $obj_str = "\"{$value}\"@{$object['lang']}";
                } else {
                    $obj_str = "\"{$value}\"";
                }
            } else {
                $obj_uri = $this->expand_uri($object);
                $obj_str = "<{$obj_uri}>";
            }

            $output .= "<{$subject}> <{$predicate}> {$obj_str} .\n";
        }

        return $output;
    }

    /**
     * Serialize to RDF/XML format
     *
     * @param array $triples Array of triples
     * @return string RDF/XML content
     */
    private function serialize_rdfxml(array $triples): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rdf = $doc->createElementNS(self::NAMESPACES['rdf'], 'rdf:RDF');
        $doc->appendChild($rdf);

        // Add namespace declarations
        foreach (self::NAMESPACES as $prefix => $uri) {
            if ($prefix !== 'rdf') {
                $rdf->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$prefix}", $uri);
            }
        }
        $rdf->setAttribute('xml:base', $this->base_uri);

        // Group triples by subject
        $grouped = [];
        foreach ($triples as $triple) {
            $subject = $triple[0];
            if (!isset($grouped[$subject])) {
                $grouped[$subject] = [];
            }
            $grouped[$subject][] = [$triple[1], $triple[2]];
        }

        foreach ($grouped as $subject => $predicates) {
            // Find rdf:type to determine element name
            $types = array_filter($predicates, fn($p) => $p[0] === 'rdf:type');
            $main_type = !empty($types) ? reset($types)[1] : 'rdf:Description';

            $element = $doc->createElement($this->compact_uri_for_xml($main_type));
            $element->setAttribute('rdf:about', $subject);
            $rdf->appendChild($element);

            foreach ($predicates as $po) {
                if ($po[0] === 'rdf:type' && $po[1] === $main_type) {
                    continue; // Skip, already represented by element name
                }

                $pred_name = $this->compact_uri_for_xml($po[0]);
                $pred_element = $doc->createElement($pred_name);

                $object = $po[1];
                if (is_array($object) && $object['type'] === 'literal') {
                    $pred_element->textContent = $object['value'];
                    if ($object['datatype']) {
                        $pred_element->setAttribute('rdf:datatype', $this->expand_uri($object['datatype']));
                    }
                    if ($object['lang']) {
                        $pred_element->setAttribute('xml:lang', $object['lang']);
                    }
                } else {
                    $pred_element->setAttribute('rdf:resource', $this->expand_uri($object));
                }

                $element->appendChild($pred_element);
            }
        }

        return $doc->saveXML();
    }

    /**
     * Serialize to JSON-LD format
     *
     * @param array $triples Array of triples
     * @return string JSON-LD content
     */
    private function serialize_jsonld(array $triples): string {
        // Build context
        $context = [
            '@base' => $this->base_uri,
            'schema' => 'http://schema.org/',
            'foaf' => 'http://xmlns.com/foaf/0.1/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'dcterms' => 'http://purl.org/dc/terms/',
            'pausatf' => 'https://www.pausatf.org/ontology/',
            'usatf' => 'https://www.usatf.org/ontology/',
            'skos' => 'http://www.w3.org/2004/02/skos/core#',
        ];

        // Group triples by subject
        $grouped = [];
        foreach ($triples as $triple) {
            $subject = $triple[0];
            if (!isset($grouped[$subject])) {
                $grouped[$subject] = ['@id' => $subject];
            }

            $predicate = $triple[1];
            $object = $triple[2];

            // Convert predicate to JSON-LD key
            $key = $this->compact_uri($predicate);
            if ($key === 'rdf:type') {
                $key = '@type';
            }

            // Convert object
            if (is_array($object) && $object['type'] === 'literal') {
                if ($object['datatype'] && $object['datatype'] !== 'xsd:string') {
                    $value = [
                        '@value' => $object['value'],
                        '@type' => $this->compact_uri($object['datatype']),
                    ];
                } elseif ($object['lang']) {
                    $value = [
                        '@value' => $object['value'],
                        '@language' => $object['lang'],
                    ];
                } else {
                    $value = $object['value'];
                }
            } else {
                $obj_uri = is_string($object) ? $object : $this->expand_uri($object);
                $value = ['@id' => $obj_uri];
            }

            // Handle multiple values for same predicate
            if (isset($grouped[$subject][$key])) {
                if (!is_array($grouped[$subject][$key]) || !isset($grouped[$subject][$key][0])) {
                    $grouped[$subject][$key] = [$grouped[$subject][$key]];
                }
                $grouped[$subject][$key][] = $value;
            } else {
                $grouped[$subject][$key] = $value;
            }
        }

        // Build final structure
        $jsonld = [
            '@context' => $context,
            '@graph' => array_values($grouped),
        ];

        return json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Check if URI is already prefixed
     *
     * @param string $uri URI to check
     * @return bool Whether URI is prefixed
     */
    private function is_prefixed(string $uri): bool {
        return preg_match('/^[a-z]+:[^\/]/', $uri) === 1;
    }

    /**
     * Compact URI using known prefixes
     *
     * @param string $uri URI to compact
     * @return string Compacted URI
     */
    private function compact_uri(string $uri): string {
        if ($this->is_prefixed($uri)) {
            return $uri;
        }

        foreach (self::NAMESPACES as $prefix => $namespace) {
            if (strpos($uri, $namespace) === 0) {
                return $prefix . ':' . substr($uri, strlen($namespace));
            }
        }

        return '<' . $uri . '>';
    }

    /**
     * Compact URI for XML element names
     *
     * @param string $uri URI to compact
     * @return string Compacted URI suitable for XML
     */
    private function compact_uri_for_xml(string $uri): string {
        $compact = $this->compact_uri($uri);
        // Remove angle brackets if present
        return trim($compact, '<>');
    }

    /**
     * Expand prefixed URI to full URI
     *
     * @param string $uri URI to expand
     * @return string Full URI
     */
    private function expand_uri(string $uri): string {
        if (!$this->is_prefixed($uri)) {
            return trim($uri, '<>');
        }

        $parts = explode(':', $uri, 2);
        if (count($parts) === 2 && isset(self::NAMESPACES[$parts[0]])) {
            return self::NAMESPACES[$parts[0]] . $parts[1];
        }

        return $uri;
    }

    /**
     * Format object for Turtle output
     *
     * @param mixed $object Object value
     * @return string Formatted object
     */
    private function format_object($object): string {
        if (is_array($object) && $object['type'] === 'literal') {
            $value = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $object['value']);

            if ($object['datatype']) {
                $datatype = $this->compact_uri($object['datatype']);
                return "\"{$value}\"^^{$datatype}";
            } elseif ($object['lang']) {
                return "\"{$value}\"@{$object['lang']}";
            }

            return "\"{$value}\"";
        }

        // It's a URI
        $uri = is_string($object) ? $object : '';

        if ($this->is_prefixed($uri)) {
            return $uri;
        }

        if (strpos($uri, $this->base_uri) === 0) {
            return '<' . substr($uri, strlen($this->base_uri)) . '>';
        }

        // Check for known prefixes
        foreach (self::NAMESPACES as $prefix => $namespace) {
            if (strpos($uri, $namespace) === 0) {
                return $prefix . ':' . substr($uri, strlen($namespace));
            }
        }

        return '<' . $uri . '>';
    }

    /**
     * Export all data as single RDF file
     *
     * @param string $format Output format
     * @return string RDF content
     */
    public function export_all(string $format = 'turtle'): string {
        $this->format = $format;

        $triples = [];

        // Add ontology
        $triples = array_merge($triples, $this->ontology_triples());

        // Add all events
        $events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($events as $event) {
            $triples = array_merge($triples, $this->event_to_triples($event));
        }

        // Add all athletes
        $athletes = get_posts([
            'post_type' => 'pausatf_athlete',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($athletes as $athlete) {
            $triples = array_merge($triples, $this->athlete_to_triples($athlete));
        }

        // Add all results
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

        foreach ($results as $result) {
            $event = get_post($result->event_id);
            if ($event) {
                $triples = array_merge($triples, $this->result_to_triples($result, $event));
            }
        }

        return $this->serialize_triples($triples);
    }

    /**
     * Get ontology triples (for inclusion in export)
     *
     * @return array Ontology triples
     */
    private function ontology_triples(): array {
        // Store current format and generate ontology
        $old_format = $this->format;
        $this->format = 'internal';

        // We need the raw triples, not serialized
        $ont_uri = 'https://www.pausatf.org/ontology/';
        $triples = [];

        $triples[] = [$ont_uri, 'rdf:type', 'owl:Ontology'];
        $triples[] = [$ont_uri, 'rdfs:label', $this->literal('PA-USATF Results Ontology')];

        $this->format = $old_format;
        return $triples;
    }
}

// Initialize RDF Exporter
add_action('init', function() {
    if (FeatureManager::is_enabled('rdf_support')) {
        new RDFExporter();
    }
}, 20);
