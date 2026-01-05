<?php
/**
 * Shortcodes for displaying results
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode handlers
 */
class Shortcodes {
    private static ?Shortcodes $instance = null;

    public static function instance(): Shortcodes {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('pausatf_results', [$this, 'render_results']);
        add_shortcode('pausatf_athlete', [$this, 'render_athlete']);
        add_shortcode('pausatf_leaderboard', [$this, 'render_leaderboard']);
        add_shortcode('pausatf_search', [$this, 'render_search']);
    }

    /**
     * Render event results
     *
     * [pausatf_results event_id="123"]
     * [pausatf_results year="2024" type="xc"]
     */
    public function render_results(array $atts): string {
        $atts = shortcode_atts([
            'event_id' => 0,
            'year' => '',
            'type' => '',
            'division' => '',
            'limit' => 100,
        ], $atts);

        global $wpdb;
        $results_table = $wpdb->prefix . 'pausatf_results';

        if ($atts['event_id']) {
            // Single event results
            $event = get_post($atts['event_id']);
            if (!$event || $event->post_type !== 'pausatf_event') {
                return '<p>' . esc_html__('Event not found.', 'pausatf-results') . '</p>';
            }

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$results_table}
                 WHERE event_id = %d
                 ORDER BY division, place
                 LIMIT %d",
                $atts['event_id'],
                $atts['limit']
            ), ARRAY_A);

            return $this->render_results_table($results, $event->post_title);
        }

        // Query by filters
        $where = ['1=1'];
        $params = [];

        if ($atts['year']) {
            $where[] = "YEAR(m.meta_value) = %d";
            $params[] = (int) $atts['year'];
        }

        if ($atts['division']) {
            $where[] = "r.division = %s";
            $params[] = $atts['division'];
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT r.*, p.post_title as event_name, m.meta_value as event_date
                  FROM {$results_table} r
                  LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
                  LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
                  WHERE {$where_clause}
                  ORDER BY m.meta_value DESC, r.place
                  LIMIT %d";

        $params[] = $atts['limit'];

        $results = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);

        return $this->render_results_table($results);
    }

    /**
     * Render athlete profile/history
     *
     * [pausatf_athlete name="John Smith"]
     * [pausatf_athlete id="123"]
     */
    public function render_athlete(array $atts): string {
        $atts = shortcode_atts([
            'id' => 0,
            'name' => '',
        ], $atts);

        $athlete_db = new AthleteDatabase();

        if ($atts['id']) {
            $athlete = get_post($atts['id']);
            $name = $athlete ? $athlete->post_title : '';
        } else {
            $name = $atts['name'];
        }

        if (empty($name)) {
            return '<p>' . esc_html__('Athlete not found.', 'pausatf-results') . '</p>';
        }

        $results = $athlete_db->get_athlete_results($name, $atts['id'] ?: null);
        $stats = $athlete_db->get_athlete_stats($name);

        ob_start();
        ?>
        <div class="pausatf-athlete-profile">
            <h3><?php echo esc_html($name); ?></h3>

            <div class="pausatf-athlete-stats">
                <div class="stat">
                    <strong><?php echo number_format($stats['total_events'] ?? 0); ?></strong>
                    <span><?php esc_html_e('Events', 'pausatf-results'); ?></span>
                </div>
                <div class="stat">
                    <strong><?php echo number_format($stats['wins'] ?? 0); ?></strong>
                    <span><?php esc_html_e('Wins', 'pausatf-results'); ?></span>
                </div>
                <div class="stat">
                    <strong><?php echo number_format($stats['podiums'] ?? 0); ?></strong>
                    <span><?php esc_html_e('Podiums', 'pausatf-results'); ?></span>
                </div>
                <div class="stat">
                    <strong><?php echo number_format($stats['total_points'] ?? 0, 1); ?></strong>
                    <span><?php esc_html_e('Points', 'pausatf-results'); ?></span>
                </div>
            </div>

            <h4><?php esc_html_e('Results History', 'pausatf-results'); ?></h4>
            <?php echo $this->render_results_table($results); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render leaderboard
     *
     * [pausatf_leaderboard division="Open" year="2024"]
     */
    public function render_leaderboard(array $atts): string {
        $atts = shortcode_atts([
            'division' => '',
            'year' => '',
            'limit' => 25,
        ], $atts);

        $athlete_db = new AthleteDatabase();
        $leaders = $athlete_db->get_leaderboard(
            $atts['division'] ?: null,
            $atts['year'] ?: null,
            $atts['limit']
        );

        if (empty($leaders)) {
            return '<p>' . esc_html__('No leaderboard data available.', 'pausatf-results') . '</p>';
        }

        ob_start();
        ?>
        <div class="pausatf-leaderboard">
            <table class="pausatf-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Rank', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Athlete', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Points', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Events', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Wins', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaders as $rank => $leader) : ?>
                        <tr>
                            <td><?php echo $rank + 1; ?></td>
                            <td><?php echo esc_html($leader['athlete_name']); ?></td>
                            <td><?php echo number_format($leader['total_points'], 1); ?></td>
                            <td><?php echo number_format($leader['events']); ?></td>
                            <td><?php echo number_format($leader['wins']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render athlete search form
     *
     * [pausatf_search]
     */
    public function render_search(array $atts): string {
        ob_start();
        ?>
        <div class="pausatf-search">
            <form method="get" action="">
                <input type="text" name="athlete_search" placeholder="<?php esc_attr_e('Search athletes...', 'pausatf-results'); ?>"
                       value="<?php echo esc_attr($_GET['athlete_search'] ?? ''); ?>">
                <button type="submit"><?php esc_html_e('Search', 'pausatf-results'); ?></button>
            </form>

            <?php
            if (!empty($_GET['athlete_search'])) {
                $athlete_db = new AthleteDatabase();
                $results = $athlete_db->search(sanitize_text_field($_GET['athlete_search']));

                if ($results) {
                    echo '<div class="pausatf-search-results">';
                    echo '<h4>' . esc_html__('Search Results', 'pausatf-results') . '</h4>';
                    echo '<ul>';
                    foreach ($results as $athlete) {
                        printf(
                            '<li><strong>%s</strong> - %d events</li>',
                            esc_html($athlete['athlete_name']),
                            $athlete['event_count']
                        );
                    }
                    echo '</ul>';
                    echo '</div>';
                } else {
                    echo '<p>' . esc_html__('No athletes found.', 'pausatf-results') . '</p>';
                }
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render results table HTML
     */
    private function render_results_table(array $results, string $title = ''): string {
        if (empty($results)) {
            return '<p>' . esc_html__('No results found.', 'pausatf-results') . '</p>';
        }

        ob_start();
        ?>
        <div class="pausatf-results-table">
            <?php if ($title) : ?>
                <h3><?php echo esc_html($title); ?></h3>
            <?php endif; ?>

            <table class="pausatf-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Place', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Name', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Age', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Division', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Time', 'pausatf-results'); ?></th>
                        <th><?php esc_html_e('Points', 'pausatf-results'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result) : ?>
                        <tr>
                            <td><?php echo esc_html($result['place'] ?? '-'); ?></td>
                            <td><?php echo esc_html($result['athlete_name']); ?></td>
                            <td><?php echo esc_html($result['athlete_age'] ?? '-'); ?></td>
                            <td><?php echo esc_html($result['division'] ?? '-'); ?></td>
                            <td><?php echo esc_html($result['time_display'] ?? '-'); ?></td>
                            <td><?php echo $result['points'] ? number_format($result['points'], 1) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('init', function() {
    Shortcodes::instance();
});
