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

require_once __DIR__ . '/class-athlete-identity.php';

require_once __DIR__ . '/class-grand-prix-scoring.php';

require_once __DIR__ . '/class-grand-prix-standings.php';

/**
 * Grand Prix series management
 */
class GrandPrix {
    /**
     * Grand Prix table
     */
    private string $series_table;
    private string $standings_table;

    /** Option holding the GP schema revision, so upgrades run without a version bump. */
    public const SCHEMA_OPTION = 'pausatf_results_gp_schema';
    public const SCHEMA = 2;

    public function __construct() {
        global $wpdb;
        $this->series_table = $wpdb->prefix . 'pausatf_grand_prix_series';
        $this->standings_table = $wpdb->prefix . 'pausatf_grand_prix_standings';

        add_shortcode('pausatf_grand_prix', [$this, 'shortcode_standings']);
        // Importers fire pausatf_results_imported; pausatf_result_imported stays for sites that fire it themselves.
        add_action('pausatf_results_imported', [$this, 'update_standings_for_event'], 10, 2);
        add_action('pausatf_result_imported', [$this, 'update_standings_for_event'], 10, 2);
        add_action('admin_init', [self::class, 'maybe_upgrade']);
    }

    /** Apply schema changes on sites that upgraded the plugin without reactivating it. */
    public static function maybe_upgrade(): void {
        if ((int) get_option(self::SCHEMA_OPTION, 0) < self::SCHEMA) {
            self::create_tables();
        }
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
            min_races int DEFAULT 0,
            max_races int DEFAULT NULL,
            drop_worst int DEFAULT 0,
            bonus_points text DEFAULT NULL,
            divisions text DEFAULT NULL,
            ruleset varchar(32) DEFAULT NULL,
            series_part varchar(10) DEFAULT NULL,
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
            team_multiplier decimal(3,2) DEFAULT NULL,
            is_championship tinyint(1) DEFAULT 0,
            distance_m int unsigned DEFAULT NULL,
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
            athlete_key varchar(191) NOT NULL DEFAULT '',
            entry_type varchar(20) NOT NULL DEFAULT 'individual',
            standing_type varchar(20) NOT NULL DEFAULT 'division',
            verified tinyint(1) NOT NULL DEFAULT 0,
            division varchar(50) DEFAULT 'OPEN',
            gender enum('M','F','X') NOT NULL,
            total_points decimal(10,2) DEFAULT 0,
            races_completed int DEFAULT 0,
            rank_position int DEFAULT NULL,
            results_data text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY series_entry (series_id, entry_type, standing_type, division, gender),
            KEY series_division (series_id, division, gender),
            KEY rank_position (rank_position)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_series);
        dbDelta($sql_events);
        dbDelta($sql_standings);

        // The old unique key on printed name merged homonyms and blocks the same athlete in a
        // division and an age+ standing. dbDelta never drops indexes, so drop it here.
        // Only the index goes; standings rows are untouched.
        $old_index = $wpdb->get_var($wpdb->prepare(
            "SHOW INDEX FROM {$standings_table} WHERE Key_name = %s",
            'series_athlete'
        ));
        if ($old_index !== null) {
            $wpdb->query("ALTER TABLE {$standings_table} DROP INDEX series_athlete");
        }

        update_option(self::SCHEMA_OPTION, self::SCHEMA);
    }

    /**
     * Create a new Grand Prix series
     *
     * @param array $data Series configuration
     * @return int|false Series ID or false
     */
    public function create_series(array $data): int|false {
        global $wpdb;

        $defaults = GrandPrixScoring::series_defaults();

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

        $distance_m = isset($options['distance_m']) ? (int) $options['distance_m'] : null;
        $part = $options['series_part'] ?? ($distance_m !== null ? ($this->get_series($series_id)['series_part'] ?? null) : null);
        if ($distance_m !== null && !empty($part) && GrandPrixRules::road_series_part($distance_m) !== $part) {
            return false; // A Short series race is 10K or shorter; a Long series race 12K or longer.
        }

        $data = [
            'series_id' => $series_id,
            'event_id' => $event_id,
            'event_order' => $options['order'] ?? 0,
            'multiplier' => $options['multiplier'] ?? 1.0,
            'is_required' => $options['required'] ?? 0,
            'team_multiplier' => $options['team_multiplier'] ?? null,
            'is_championship' => !empty($options['championship']) ? 1 : 0,
            'distance_m' => $distance_m,
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
            "SELECT gpe.*, p.post_title as event_name, " . EventDate::sql_expr('pm') . " as event_date
             FROM {$events_table} gpe
             INNER JOIN {$wpdb->posts} p ON gpe.event_id = p.ID
             " . EventDate::sql_join('p.ID', 'pm') . "
             WHERE gpe.series_id = %d
             ORDER BY gpe.event_order, " . EventDate::sql_expr('pm'),
            $series_id
        ), ARRAY_A);
    }

    /**
     * Calculate points for a place
     */
    public function calculate_place_points(int $place, array $series): float {
        // PA scores nothing beyond the last listed place.
        return GrandPrixScoring::points_for_place($place, $series['place_points'] ?? []);
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

        $rules = GrandPrixRules::get((string) ($series['ruleset'] ?? ''));
        if ($rules !== null) {
            return $this->store_rule_standings($series_id, GrandPrixStandings::compute($rules, $events, $results, (int) ($series['season_year'] ?? gmdate('Y'))));
        }

        // Group results by athlete
        $athletes = [];
        foreach ($results as $result) {
            $gender = GrandPrixScoring::resolve_gender($result['sex'] ?? null, $result['division'] ?? null);
            // Same person across events by the site archive's identity, not by printed name.
            $identity = AthleteIdentity::resolve((string) $result['athlete_name'], isset($result['athlete_id']) ? (int) $result['athlete_id'] : null);
            $key = $identity['key'] . '|' . ($result['division'] ?? 'OPEN') . '|' . $gender;
            if (!isset($athletes[$key])) {
                $athletes[$key] = [
                    'name' => $result['athlete_name'],
                    'identity' => $identity,
                    'athlete_id' => $result['athlete_id'],
                    'division' => $result['division'] ?? 'OPEN',
                    'gender' => $gender,
                    'results' => [],
                    'total_points' => 0,
                    'races_completed' => 0,
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

            if ($series['drop_worst'] > 0 && count($points_list) > max((int) $series['drop_worst'], (int) $series['min_races'])) {
                $points_list = array_slice($points_list, 0, -$series['drop_worst']);
            }

            // Apply max races limit
            if ($series['max_races'] && count($points_list) > $series['max_races']) {
                $points_list = array_slice($points_list, 0, $series['max_races']);
            }

            $athlete['total_points'] = array_sum($points_list);
            $athlete['races_completed'] = count($athlete['results']);
        }
        // Break the reference: a later foreach over $athlete would otherwise overwrite the last athlete.
        unset($athlete);

        // Clear existing standings
        $wpdb->delete($this->standings_table, ['series_id' => $series_id]);

        // Insert new standings by division
        $divisions = $series['divisions'] ?? ['OPEN'];
        $count = 0;

        foreach ($divisions as $division) {
            // 'X' holds results whose sex is neither recorded nor printed in the division.
            foreach (['M', 'F', 'X'] as $gender) {
                $division_athletes = array_values(array_filter($athletes, function ($a) use ($division, $gender, $series) {
                    return $a['division'] === $division && $a['gender'] === $gender
                        && $a['races_completed'] >= (int) $series['min_races'];
                }));

                usort($division_athletes, fn($a, $b) => $b['total_points'] <=> $a['total_points']);
                $ranks = GrandPrixScoring::competition_ranks(array_column($division_athletes, 'total_points'));

                foreach ($division_athletes as $index => $athlete) {
                    $rank = $ranks[$index];
                    $count++;

                    $wpdb->insert($this->standings_table, [
                        'series_id' => $series_id,
                        'athlete_id' => $athlete['athlete_id'],
                        'athlete_name' => $athlete['name'],
                        'athlete_key' => $athlete['identity']['key'],
                        'verified' => $athlete['identity']['verified'] ? 1 : 0,
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
     * Replace a series' standings with rows from GrandPrixStandings::compute().
     *
     * @param list<array<string, mixed>> $rows
     */
    private function store_rule_standings(int $series_id, array $rows): int {
        global $wpdb;
        $wpdb->delete($this->standings_table, ['series_id' => $series_id]);
        foreach ($rows as $row) {
            $wpdb->insert($this->standings_table, [
                'series_id' => $series_id,
                'athlete_id' => $row['athlete_id'],
                'athlete_name' => $row['name'],
                'athlete_key' => $row['key'],
                'entry_type' => $row['entry_type'],
                'standing_type' => $row['standing_type'],
                'verified' => $row['verified'] ? 1 : 0,
                'division' => $row['division'],
                'gender' => $row['gender'],
                'total_points' => $row['total_points'],
                'races_completed' => $row['races_completed'],
                'rank_position' => $row['rank_position'],
                'results_data' => json_encode($row['races']),
            ]);
        }
        return count($rows);
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

        // Division standings by default; 'age_plus' and 'team' select the others.
        $type = $filters['type'] ?? 'division';
        if ($type === 'team') {
            $where[] = 'entry_type = %s';
            $params[] = 'team';
        } else {
            $where[] = 'entry_type = %s AND standing_type = %s';
            $params[] = 'individual';
            $params[] = $type === 'age_plus' ? 'age_plus' : 'division';
        }

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
            'type' => 'division',
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
            'type' => $atts['type'],
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
                    $label = trim(self::sex_label((string) $first['gender'], (string) $first['division']) . ' ' . $first['division']);
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

    /** Heading word for a standings group's sex. X rows have no listed sex and are not called Men. */
    public static function sex_label(string $gender, string $division = ''): string {
        return match (true) {
            $gender === 'M' => 'Men',
            $gender === 'F' => 'Women',
            $division === 'OVERALL' => '',
            default => 'Sex not listed',
        };
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
