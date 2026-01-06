<?php
/**
 * Grand Prix Scoring System
 *
 * Multi-race series point tracking for seasonal competitions
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grand Prix series management
 */
class GrandPrix {
    /**
     * Grand Prix table
     */
    private string $series_table;
    private string $standings_table;

    public function __construct() {
        global $wpdb;
        $this->series_table = $wpdb->prefix . 'pausatf_grand_prix_series';
        $this->standings_table = $wpdb->prefix . 'pausatf_grand_prix_standings';

        add_shortcode('pausatf_grand_prix', [$this, 'shortcode_standings']);
        add_action('pausatf_result_imported', [$this, 'update_standings_for_event'], 10, 2);
    }

    /**
     * Create Grand Prix tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Series configuration table
        $series_table = $wpdb->prefix . 'pausatf_grand_prix_series';
        $sql_series = "CREATE TABLE {$series_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            season_year int NOT NULL,
            description text DEFAULT NULL,
            scoring_system enum('place','time','age_graded','custom') DEFAULT 'place',
            place_points text DEFAULT NULL,
            min_races int DEFAULT 3,
            max_races int DEFAULT NULL,
            drop_worst int DEFAULT 0,
            bonus_points text DEFAULT NULL,
            divisions text DEFAULT NULL,
            status enum('active','completed','archived') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug_year (slug, season_year),
            KEY season (season_year),
            KEY status (status)
        ) {$charset_collate};";

        // Series events mapping
        $events_table = $wpdb->prefix . 'pausatf_grand_prix_events';
        $sql_events = "CREATE TABLE {$events_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            series_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            event_order int DEFAULT 0,
            multiplier decimal(3,2) DEFAULT 1.00,
            is_required tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY series_event (series_id, event_id),
            KEY series_id (series_id),
            KEY event_id (event_id)
        ) {$charset_collate};";

        // Standings table
        $standings_table = $wpdb->prefix . 'pausatf_grand_prix_standings';
        $sql_standings = "CREATE TABLE {$standings_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            series_id bigint(20) unsigned NOT NULL,
            athlete_id bigint(20) unsigned DEFAULT NULL,
            athlete_name varchar(255) NOT NULL,
            division varchar(50) DEFAULT 'OPEN',
            gender enum('M','F') NOT NULL,
            total_points decimal(10,2) DEFAULT 0,
            races_completed int DEFAULT 0,
            rank_position int DEFAULT NULL,
            results_data text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY series_athlete (series_id, athlete_name, division, gender),
            KEY series_division (series_id, division, gender),
            KEY rank_position (rank_position)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_series);
        dbDelta($sql_events);
        dbDelta($sql_standings);
    }

    /**
     * Create a new Grand Prix series
     *
     * @param array $data Series configuration
     * @return int|false Series ID or false
     */
    public function create_series(array $data): int|false {
        global $wpdb;

        $defaults = [
            'name' => '',
            'slug' => '',
            'season_year' => (int) date('Y'),
            'scoring_system' => 'place',
            'place_points' => json_encode([100, 90, 82, 75, 69, 64, 60, 56, 53, 50, 48, 46, 44, 42, 40, 38, 36, 34, 32, 30]),
            'min_races' => 3,
            'max_races' => null,
            'drop_worst' => 0,
            'bonus_points' => json_encode([
                'age_record' => 50,
                'association_record' => 100,
                'pr' => 10,
            ]),
            'divisions' => json_encode(['OPEN', 'M40', 'M50', 'M60', 'M70', 'W40', 'W50', 'W60', 'W70']),
            'status' => 'active',
        ];

        $data = array_merge($defaults, $data);

        if (empty($data['name'])) {
            return false;
        }

        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }

        $result = $wpdb->insert($this->series_table, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Add event to series
     *
     * @param int $series_id Series ID
     * @param int $event_id Event post ID
     * @param array $options Event options
     * @return bool Success
     */
    public function add_event_to_series(int $series_id, int $event_id, array $options = []): bool {
        global $wpdb;
        $events_table = $wpdb->prefix . 'pausatf_grand_prix_events';

        $data = [
            'series_id' => $series_id,
            'event_id' => $event_id,
            'event_order' => $options['order'] ?? 0,
            'multiplier' => $options['multiplier'] ?? 1.0,
            'is_required' => $options['required'] ?? 0,
        ];

        return (bool) $wpdb->insert($events_table, $data);
    }

    /**
     * Get series by ID or slug
     */
    public function get_series($identifier, ?int $year = null): ?array {
        global $wpdb;

        if (is_numeric($identifier)) {
            $series = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->series_table} WHERE id = %d",
                $identifier
            ), ARRAY_A);
        } else {
            $year = $year ?? (int) date('Y');
            $series = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->series_table} WHERE slug = %s AND season_year = %d",
                $identifier,
                $year
            ), ARRAY_A);
        }

        if ($series) {
            $series['place_points'] = json_decode($series['place_points'], true);
            $series['bonus_points'] = json_decode($series['bonus_points'], true);
            $series['divisions'] = json_decode($series['divisions'], true);
        }

        return $series;
    }

    /**
     * Get events in a series
     */
    public function get_series_events(int $series_id): array {
        global $wpdb;
        $events_table = $wpdb->prefix . 'pausatf_grand_prix_events';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT gpe.*, p.post_title as event_name, pm.meta_value as event_date
             FROM {$events_table} gpe
             INNER JOIN {$wpdb->posts} p ON gpe.event_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_pausatf_event_date'
             WHERE gpe.series_id = %d
             ORDER BY gpe.event_order, pm.meta_value",
            $series_id
        ), ARRAY_A);
    }

    /**
     * Calculate points for a place
     */
    public function calculate_place_points(int $place, array $series): float {
        $points_table = $series['place_points'] ?? [];

        if ($place <= 0) {
            return 0;
        }

        if (isset($points_table[$place - 1])) {
            return (float) $points_table[$place - 1];
        }

        // For places beyond the table, give 1 point
        return 1;
    }

    /**
     * Calculate standings for a series
     *
     * @param int $series_id Series ID
     * @return int Number of athletes scored
     */
    public function calculate_standings(int $series_id): int {
        global $wpdb;
        $results_table = $wpdb->prefix . 'pausatf_results';
        $events_table = $wpdb->prefix . 'pausatf_grand_prix_events';

        $series = $this->get_series($series_id);
        if (!$series) {
            return 0;
        }

        // Get all events in series
        $events = $this->get_series_events($series_id);
        $event_ids = array_column($events, 'event_id');
        $event_multipliers = array_column($events, 'multiplier', 'event_id');

        if (empty($event_ids)) {
            return 0;
        }

        // Get all results for series events
        $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
        $sql = "SELECT r.*, p.post_title as event_name
                FROM {$results_table} r
                INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
                WHERE r.event_id IN ({$placeholders})
                ORDER BY r.athlete_name, r.event_id";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$event_ids), ARRAY_A);

        // Group results by athlete
        $athletes = [];
        foreach ($results as $result) {
            $key = $result['athlete_name'] . '|' . ($result['division'] ?? 'OPEN');
            if (!isset($athletes[$key])) {
                $athletes[$key] = [
                    'name' => $result['athlete_name'],
                    'athlete_id' => $result['athlete_id'],
                    'division' => $result['division'] ?? 'OPEN',
                    'gender' => $this->infer_gender($result['division']),
                    'results' => [],
                    'total_points' => 0,
                ];
            }

            $multiplier = $event_multipliers[$result['event_id']] ?? 1.0;
            $points = $this->calculate_place_points($result['place'], $series) * $multiplier;

            // Add bonus points
            $bonus = 0;
            // Could check for PR, records, etc.

            $athletes[$key]['results'][] = [
                'event_id' => $result['event_id'],
                'event_name' => $result['event_name'],
                'place' => $result['place'],
                'points' => $points,
                'bonus' => $bonus,
                'time' => $result['time_display'],
            ];
        }

        // Calculate totals with drop rules
        foreach ($athletes as &$athlete) {
            $points_list = array_column($athlete['results'], 'points');

            // Sort descending and drop worst
            rsort($points_list);

            if ($series['drop_worst'] > 0 && count($points_list) > $series['min_races']) {
                $points_list = array_slice($points_list, 0, -$series['drop_worst']);
            }

            // Apply max races limit
            if ($series['max_races'] && count($points_list) > $series['max_races']) {
                $points_list = array_slice($points_list, 0, $series['max_races']);
            }

            $athlete['total_points'] = array_sum($points_list);
            $athlete['races_completed'] = count($athlete['results']);
        }

        // Clear existing standings
        $wpdb->delete($this->standings_table, ['series_id' => $series_id]);

        // Insert new standings by division
        $divisions = $series['divisions'] ?? ['OPEN'];
        $count = 0;

        foreach ($divisions as $division) {
            foreach (['M', 'F'] as $gender) {
                $division_athletes = array_filter($athletes, function ($a) use ($division, $gender) {
                    return $a['division'] === $division && $a['gender'] === $gender;
                });

                // Sort by points
                usort($division_athletes, fn($a, $b) => $b['total_points'] <=> $a['total_points']);

                // Assign ranks and insert
                $rank = 0;
                foreach ($division_athletes as $athlete) {
                    // Check minimum races
                    if ($athlete['races_completed'] < $series['min_races']) {
                        continue;
                    }

                    $rank++;
                    $count++;

                    $wpdb->insert($this->standings_table, [
                        'series_id' => $series_id,
                        'athlete_id' => $athlete['athlete_id'],
                        'athlete_name' => $athlete['name'],
                        'division' => $division,
                        'gender' => $gender,
                        'total_points' => $athlete['total_points'],
                        'races_completed' => $athlete['races_completed'],
                        'rank_position' => $rank,
                        'results_data' => json_encode($athlete['results']),
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Infer gender from division string
     */
    private function infer_gender(?string $division): string {
        if (!$division) {
            return 'M';
        }

        if (preg_match('/^[WF]/i', $division) || stripos($division, 'women') !== false) {
            return 'F';
        }

        return 'M';
    }

    /**
     * Get standings for a series
     *
     * @param int $series_id Series ID
     * @param array $filters Optional filters
     * @return array Standings
     */
    public function get_standings(int $series_id, array $filters = []): array {
        global $wpdb;

        $where = ['series_id = %d'];
        $params = [$series_id];

        if (!empty($filters['division'])) {
            $where[] = 'division = %s';
            $params[] = $filters['division'];
        }

        if (!empty($filters['gender'])) {
            $where[] = 'gender = %s';
            $params[] = $filters['gender'];
        }

        $limit = $filters['limit'] ?? 100;
        $params[] = $limit;

        $where_sql = implode(' AND ', $where);

        $standings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->standings_table}
             WHERE {$where_sql}
             ORDER BY division, gender, rank_position
             LIMIT %d",
            ...$params
        ), ARRAY_A);

        foreach ($standings as &$standing) {
            $standing['results_data'] = json_decode($standing['results_data'], true);
        }

        return $standings;
    }

    /**
     * Update standings when new results are imported
     */
    public function update_standings_for_event(int $event_id, int $results_count): void {
        global $wpdb;
        $events_table = $wpdb->prefix . 'pausatf_grand_prix_events';

        // Find series containing this event
        $series_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT series_id FROM {$events_table} WHERE event_id = %d",
            $event_id
        ));

        foreach ($series_ids as $series_id) {
            $this->calculate_standings((int) $series_id);
        }
    }

    /**
     * Shortcode for Grand Prix standings
     */
    public function shortcode_standings(array $atts): string {
        $atts = shortcode_atts([
            'series' => '',
            'year' => date('Y'),
            'division' => '',
            'gender' => '',
            'limit' => 50,
        ], $atts);

        if (empty($atts['series'])) {
            return '<p>Please specify a series.</p>';
        }

        $series = $this->get_series($atts['series'], (int) $atts['year']);
        if (!$series) {
            return '<p>Series not found.</p>';
        }

        $standings = $this->get_standings($series['id'], [
            'division' => $atts['division'],
            'gender' => $atts['gender'],
            'limit' => (int) $atts['limit'],
        ]);

        $events = $this->get_series_events($series['id']);

        ob_start();
        ?>
        <div class="pausatf-grand-prix">
            <h2><?php echo esc_html($series['name']); ?> - <?php echo esc_html($series['season_year']); ?></h2>

            <?php if ($series['description']): ?>
                <p class="series-description"><?php echo esc_html($series['description']); ?></p>
            <?php endif; ?>

            <div class="series-info">
                <span>Events: <?php echo count($events); ?></span>
                <span>Min Races: <?php echo esc_html($series['min_races']); ?></span>
                <?php if ($series['drop_worst']): ?>
                    <span>Drop Worst: <?php echo esc_html($series['drop_worst']); ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($standings)): ?>
                <p>No standings available yet.</p>
            <?php else: ?>
                <?php
                // Group by division
                $by_division = [];
                foreach ($standings as $standing) {
                    $key = $standing['gender'] . '_' . $standing['division'];
                    $by_division[$key][] = $standing;
                }
                ?>

                <?php foreach ($by_division as $key => $division_standings): ?>
                    <?php
                    $first = $division_standings[0];
                    $label = ($first['gender'] === 'F' ? 'Women' : 'Men') . ' ' . $first['division'];
                    ?>
                    <div class="division-standings">
                        <h3><?php echo esc_html($label); ?></h3>
                        <table class="standings-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Athlete</th>
                                    <th>Races</th>
                                    <th>Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($division_standings as $standing): ?>
                                    <tr>
                                        <td><?php echo esc_html($standing['rank_position']); ?></td>
                                        <td>
                                            <?php if ($standing['athlete_id']): ?>
                                                <a href="<?php echo get_permalink($standing['athlete_id']); ?>">
                                                    <?php echo esc_html($standing['athlete_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo esc_html($standing['athlete_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($standing['races_completed']); ?></td>
                                        <td><strong><?php echo number_format($standing['total_points'], 1); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get active series
     */
    public function get_active_series(?int $year = null): array {
        global $wpdb;

        $year = $year ?? (int) date('Y');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->series_table}
             WHERE season_year = %d AND status = 'active'
             ORDER BY name",
            $year
        ), ARRAY_A);
    }
}

// Initialize
new GrandPrix();
