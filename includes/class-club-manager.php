<?php
/**
 * Club Manager - Club profiles and team standings
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages club profiles, rosters, and team standings
 */
class ClubManager {
    private static ?ClubManager $instance = null;

    public static function instance(): ClubManager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_post_type']);
    }

    /**
     * Register Club post type
     */
    public function register_post_type(): void {
        register_post_type('pausatf_club', [
            'labels' => [
                'name' => __('Clubs', 'pausatf-results'),
                'singular_name' => __('Club', 'pausatf-results'),
                'add_new' => __('Add New Club', 'pausatf-results'),
                'add_new_item' => __('Add New Club', 'pausatf-results'),
                'edit_item' => __('Edit Club', 'pausatf-results'),
                'view_item' => __('View Club', 'pausatf-results'),
                'search_items' => __('Search Clubs', 'pausatf-results'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'clubs'],
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'menu_icon' => 'dashicons-groups',
            'show_in_rest' => true,
        ]);

        // Club region taxonomy
        register_taxonomy('pausatf_region', 'pausatf_club', [
            'labels' => [
                'name' => __('Regions', 'pausatf-results'),
                'singular_name' => __('Region', 'pausatf-results'),
            ],
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => 'region'],
        ]);
    }

    /**
     * Find or create a club by name
     *
     * @param string $name Club name
     * @return int|null Club post ID
     */
    public function find_or_create_club(string $name): ?int {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        // Normalize common abbreviations
        $name = $this->normalize_club_name($name);

        // Check if exists
        $existing = get_page_by_title($name, OBJECT, 'pausatf_club');
        if ($existing) {
            return $existing->ID;
        }

        // Create new club
        $club_id = wp_insert_post([
            'post_type' => 'pausatf_club',
            'post_title' => $name,
            'post_status' => 'publish',
        ]);

        return $club_id ?: null;
    }

    /**
     * Normalize club name abbreviations
     */
    private function normalize_club_name(string $name): string {
        $replacements = [
            '/\bTC\b/i' => 'Track Club',
            '/\bRC\b/i' => 'Running Club',
            '/\bAC\b/i' => 'Athletic Club',
            '/\bWVTC\b/i' => 'West Valley Track Club',
            '/\bIMPALA\b/i' => 'Impala Racing Team',
            '/\bTAMALPA\b/i' => 'Tamalpa Runners',
            '/\bPAMACELERS\b/i' => 'Pamakids',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name);
        }

        return $name;
    }

    /**
     * Get club members (athletes associated with this club)
     *
     * @param int $club_id Club post ID
     * @return array Athletes
     */
    public function get_club_members(int $club_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $club_name = get_the_title($club_id);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT
                r.athlete_name,
                r.athlete_id,
                COUNT(*) as event_count,
                MAX(m.meta_value) as last_event_date
             FROM {$table} r
             LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
             WHERE r.club = %s
             GROUP BY r.athlete_name, r.athlete_id
             ORDER BY event_count DESC",
            $club_name
        ), ARRAY_A);
    }

    /**
     * Get club statistics
     *
     * @param int $club_id Club post ID
     * @return array Statistics
     */
    public function get_club_stats(int $club_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $club_name = get_the_title($club_id);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT r.athlete_name) as total_athletes,
                COUNT(DISTINCT r.event_id) as events_participated,
                SUM(CASE WHEN r.place = 1 THEN 1 ELSE 0 END) as total_wins,
                SUM(CASE WHEN r.place <= 3 THEN 1 ELSE 0 END) as total_podiums,
                SUM(COALESCE(r.points, 0)) as total_points
             FROM {$table} r
             WHERE r.club = %s",
            $club_name
        ), ARRAY_A) ?: [];
    }

    /**
     * Get team standings for an event
     *
     * @param int $event_id Event post ID
     * @return array Team standings
     */
    public function get_event_team_standings(int $event_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        // Standard XC scoring: sum of top 5 places, low score wins
        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                club,
                COUNT(*) as finishers,
                SUM(place) as total_score,
                MIN(place) as best_finish,
                GROUP_CONCAT(athlete_name ORDER BY place SEPARATOR ', ') as top_runners
             FROM (
                SELECT r.club, r.place, r.athlete_name,
                       @rn := IF(@club = r.club, @rn + 1, 1) as row_num,
                       @club := r.club
                FROM {$table} r, (SELECT @rn := 0, @club := '') vars
                WHERE r.event_id = %d
                AND r.club IS NOT NULL
                AND r.club != ''
                ORDER BY r.club, r.place
             ) ranked
             WHERE row_num <= 5
             GROUP BY club
             HAVING finishers >= 5
             ORDER BY total_score ASC",
            $event_id
        ), ARRAY_A);
    }

    /**
     * Get season team standings
     *
     * @param string      $season Year
     * @param string|null $event_type Optional event type filter
     * @return array Season standings
     */
    public function get_season_team_standings(string $season, ?string $event_type = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $join = "";
        $where = "";
        $params = [$season];

        if ($event_type) {
            $join = "LEFT JOIN {$wpdb->term_relationships} tr ON r.event_id = tr.object_id
                     LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id";
            $where = "AND t.name = %s";
            $params[] = $event_type;
        }

        $params[] = 50; // limit

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.club,
                COUNT(DISTINCT r.event_id) as events,
                COUNT(DISTINCT r.athlete_name) as athletes,
                SUM(CASE WHEN r.place = 1 THEN 1 ELSE 0 END) as wins,
                SUM(COALESCE(r.points, 0)) as total_points,
                AVG(r.place) as avg_place
             FROM {$table} r
             LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
             {$join}
             WHERE YEAR(m.meta_value) = %s
             AND r.club IS NOT NULL
             AND r.club != ''
             {$where}
             GROUP BY r.club
             ORDER BY total_points DESC
             LIMIT %d",
            ...$params
        ), ARRAY_A);
    }

    /**
     * Get club results history
     *
     * @param int $club_id Club post ID
     * @param int $limit Max results
     * @return array Results
     */
    public function get_club_results(int $club_id, int $limit = 100): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $club_name = get_the_title($club_id);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                r.*,
                p.post_title as event_name,
                m.meta_value as event_date
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
             WHERE r.club = %s
             ORDER BY m.meta_value DESC, r.place ASC
             LIMIT %d",
            $club_name,
            $limit
        ), ARRAY_A);
    }

    /**
     * Bulk extract and create clubs from results
     *
     * @return array Creation results
     */
    public function bulk_create_clubs(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $clubs = $wpdb->get_col(
            "SELECT DISTINCT club FROM {$table}
             WHERE club IS NOT NULL AND club != ''
             ORDER BY club"
        );

        $created = 0;
        $existing = 0;

        foreach ($clubs as $club_name) {
            $result = $this->find_or_create_club($club_name);
            if ($result) {
                $existing_post = get_page_by_title($club_name, OBJECT, 'pausatf_club');
                if ($existing_post && $existing_post->ID === $result) {
                    $existing++;
                } else {
                    $created++;
                }
            }
        }

        return [
            'created' => $created,
            'existing' => $existing,
            'total' => count($clubs),
        ];
    }
}

// Initialize
add_action('plugins_loaded', function() {
    ClubManager::instance();
});
