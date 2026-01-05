<?php
/**
 * Frontend Display - Enhanced results display with filtering and widgets
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles frontend display, filtering, and widgets
 */
class FrontendDisplay {
    private static ?FrontendDisplay $instance = null;

    public static function instance(): FrontendDisplay {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('pausatf_results_table', [$this, 'render_interactive_table']);
        add_shortcode('pausatf_upcoming', [$this, 'render_upcoming_events']);
        add_shortcode('pausatf_recent_results', [$this, 'render_recent_results']);
        add_shortcode('pausatf_club_profile', [$this, 'render_club_profile']);
        add_shortcode('pausatf_embed', [$this, 'render_embeddable_widget']);

        // AJAX handlers
        add_action('wp_ajax_pausatf_filter_results', [$this, 'ajax_filter_results']);
        add_action('wp_ajax_nopriv_pausatf_filter_results', [$this, 'ajax_filter_results']);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets(): void {
        wp_register_style(
            'pausatf-frontend',
            PAUSATF_RESULTS_URL . 'assets/css/frontend.css',
            [],
            PAUSATF_RESULTS_VERSION
        );

        wp_register_script(
            'pausatf-frontend',
            PAUSATF_RESULTS_URL . 'assets/js/frontend.js',
            ['jquery'],
            PAUSATF_RESULTS_VERSION,
            true
        );

        wp_localize_script('pausatf-frontend', 'pausatfFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pausatf_frontend'),
        ]);
    }

    /**
     * Render interactive results table with filtering
     *
     * [pausatf_results_table event_id="123" filterable="true" sortable="true"]
     */
    public function render_interactive_table(array $atts): string {
        wp_enqueue_style('pausatf-frontend');
        wp_enqueue_script('pausatf-frontend');

        $atts = shortcode_atts([
            'event_id' => 0,
            'year' => '',
            'type' => '',
            'division' => '',
            'filterable' => 'true',
            'sortable' => 'true',
            'exportable' => 'true',
            'per_page' => 50,
        ], $atts);

        $event_id = absint($atts['event_id']);

        if (!$event_id) {
            return '<p>Please specify an event_id</p>';
        }

        $results = $this->get_event_results($event_id);
        $event = get_post($event_id);
        $divisions = array_unique(array_filter(array_column($results, 'division')));

        ob_start();
        ?>
        <div class="pausatf-interactive-results" data-event-id="<?php echo $event_id; ?>">
            <?php if ($atts['filterable'] === 'true') : ?>
                <div class="pausatf-filters">
                    <div class="pausatf-filter-group">
                        <label for="pausatf-division-filter">Division:</label>
                        <select id="pausatf-division-filter" class="pausatf-filter" data-filter="division">
                            <option value="">All Divisions</option>
                            <?php foreach ($divisions as $div) : ?>
                                <option value="<?php echo esc_attr($div); ?>"><?php echo esc_html($div); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pausatf-filter-group">
                        <label for="pausatf-search-filter">Search:</label>
                        <input type="text" id="pausatf-search-filter" class="pausatf-filter"
                               placeholder="Search by name or club..." data-filter="search">
                    </div>

                    <?php if ($atts['exportable'] === 'true') : ?>
                        <div class="pausatf-export-buttons">
                            <a href="<?php echo esc_url(DataExporter::get_export_url('csv', $event_id)); ?>"
                               class="pausatf-btn pausatf-btn-sm">Export CSV</a>
                            <a href="<?php echo esc_url(DataExporter::get_export_url('pdf', $event_id)); ?>"
                               class="pausatf-btn pausatf-btn-sm" target="_blank">Print/PDF</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="pausatf-results-count">
                Showing <span class="pausatf-visible-count"><?php echo count($results); ?></span>
                of <?php echo count($results); ?> results
            </div>

            <table class="pausatf-table <?php echo $atts['sortable'] === 'true' ? 'pausatf-sortable' : ''; ?>" id="pausatf-results-table">
                <thead>
                    <tr>
                        <th data-sort="place" class="sortable">Place</th>
                        <th data-sort="name" class="sortable">Name</th>
                        <th data-sort="age">Age</th>
                        <th data-sort="division">Division</th>
                        <th data-sort="time" class="sortable">Time</th>
                        <th data-sort="points" class="sortable">Points</th>
                        <th data-sort="club">Club</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result) : ?>
                        <tr data-division="<?php echo esc_attr($result['division'] ?? ''); ?>"
                            data-name="<?php echo esc_attr(strtolower($result['athlete_name'])); ?>"
                            data-club="<?php echo esc_attr(strtolower($result['club'] ?? '')); ?>">
                            <td><?php echo esc_html($result['place'] ?? '-'); ?></td>
                            <td>
                                <strong><?php echo esc_html($result['athlete_name']); ?></strong>
                                <?php if ($this->is_pr($result)) : ?>
                                    <span class="pausatf-badge pausatf-badge-pr" title="Personal Record">PR</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($result['athlete_age'] ?? '-'); ?></td>
                            <td><?php echo esc_html($result['division'] ?? '-'); ?></td>
                            <td><?php echo esc_html($result['time_display'] ?? '-'); ?></td>
                            <td><?php echo $result['points'] ? number_format($result['points'], 1) : '-'; ?></td>
                            <td><?php echo esc_html($result['club'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($results) > $atts['per_page']) : ?>
                <div class="pausatf-pagination">
                    <button class="pausatf-btn" data-action="show-more">Show More</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render upcoming events widget
     *
     * [pausatf_upcoming limit="5"]
     */
    public function render_upcoming_events(array $atts): string {
        $atts = shortcode_atts([
            'limit' => 5,
            'type' => '',
        ], $atts);

        // This would pull from a calendar/events source
        // For now, show a placeholder
        ob_start();
        ?>
        <div class="pausatf-upcoming-events">
            <h3>Upcoming Events</h3>
            <p class="pausatf-notice">
                Visit <a href="https://www.pausatf.org" target="_blank">pausatf.org</a> for the event calendar.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render recent results widget
     *
     * [pausatf_recent_results limit="5"]
     */
    public function render_recent_results(array $atts): string {
        wp_enqueue_style('pausatf-frontend');

        $atts = shortcode_atts([
            'limit' => 5,
            'type' => '',
        ], $atts);

        $args = [
            'post_type' => 'pausatf_event',
            'posts_per_page' => $atts['limit'],
            'meta_key' => '_pausatf_event_date',
            'orderby' => 'meta_value',
            'order' => 'DESC',
        ];

        if ($atts['type']) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'pausatf_event_type',
                    'field' => 'slug',
                    'terms' => $atts['type'],
                ],
            ];
        }

        $events = get_posts($args);

        ob_start();
        ?>
        <div class="pausatf-recent-results">
            <ul class="pausatf-event-list">
                <?php foreach ($events as $event) :
                    $date = get_post_meta($event->ID, '_pausatf_event_date', true);
                    $count = get_post_meta($event->ID, '_pausatf_result_count', true);
                    ?>
                    <li class="pausatf-event-item">
                        <a href="<?php echo get_permalink($event->ID); ?>">
                            <strong><?php echo esc_html($event->post_title); ?></strong>
                        </a>
                        <span class="pausatf-event-meta">
                            <?php echo $date ? date('M j, Y', strtotime($date)) : ''; ?>
                            <?php if ($count) : ?>
                                &bull; <?php echo number_format($count); ?> results
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <a href="<?php echo get_post_type_archive_link('pausatf_event'); ?>" class="pausatf-view-all">
                View All Results &rarr;
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render club profile widget
     *
     * [pausatf_club_profile id="123"]
     * [pausatf_club_profile name="West Valley Track Club"]
     */
    public function render_club_profile(array $atts): string {
        wp_enqueue_style('pausatf-frontend');

        $atts = shortcode_atts([
            'id' => 0,
            'name' => '',
        ], $atts);

        $club_manager = ClubManager::instance();

        if ($atts['id']) {
            $club_id = absint($atts['id']);
        } elseif ($atts['name']) {
            $club = get_page_by_title($atts['name'], OBJECT, 'pausatf_club');
            $club_id = $club ? $club->ID : 0;
        } else {
            return '<p>Please specify club id or name</p>';
        }

        if (!$club_id) {
            return '<p>Club not found</p>';
        }

        $club = get_post($club_id);
        $stats = $club_manager->get_club_stats($club_id);
        $members = $club_manager->get_club_members($club_id);

        ob_start();
        ?>
        <div class="pausatf-club-profile">
            <h3><?php echo esc_html($club->post_title); ?></h3>

            <div class="pausatf-club-stats">
                <div class="pausatf-stat">
                    <strong><?php echo number_format($stats['total_athletes'] ?? 0); ?></strong>
                    <span>Athletes</span>
                </div>
                <div class="pausatf-stat">
                    <strong><?php echo number_format($stats['events_participated'] ?? 0); ?></strong>
                    <span>Events</span>
                </div>
                <div class="pausatf-stat">
                    <strong><?php echo number_format($stats['total_wins'] ?? 0); ?></strong>
                    <span>Wins</span>
                </div>
                <div class="pausatf-stat">
                    <strong><?php echo number_format($stats['total_points'] ?? 0, 0); ?></strong>
                    <span>Points</span>
                </div>
            </div>

            <?php if (!empty($members)) : ?>
                <h4>Top Athletes</h4>
                <ul class="pausatf-member-list">
                    <?php foreach (array_slice($members, 0, 10) as $member) : ?>
                        <li>
                            <?php echo esc_html($member['athlete_name']); ?>
                            <span class="pausatf-meta"><?php echo $member['event_count']; ?> events</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render embeddable widget (for external sites)
     *
     * [pausatf_embed event_id="123" width="600" height="400"]
     */
    public function render_embeddable_widget(array $atts): string {
        $atts = shortcode_atts([
            'event_id' => 0,
            'type' => 'results', // results, leaderboard, athlete
            'width' => '100%',
            'height' => '400',
            'theme' => 'light',
        ], $atts);

        $embed_url = add_query_arg([
            'pausatf_embed' => '1',
            'event_id' => $atts['event_id'],
            'type' => $atts['type'],
            'theme' => $atts['theme'],
        ], home_url());

        return sprintf(
            '<iframe src="%s" width="%s" height="%s" frameborder="0" style="border: 1px solid #ddd; border-radius: 4px;"></iframe>',
            esc_url($embed_url),
            esc_attr($atts['width']),
            esc_attr($atts['height'])
        );
    }

    /**
     * AJAX handler for filtering results
     */
    public function ajax_filter_results(): void {
        check_ajax_referer('pausatf_frontend', 'nonce');

        $event_id = absint($_POST['event_id'] ?? 0);
        $division = sanitize_text_field($_POST['division'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $results = $this->get_event_results($event_id, $division, $search);

        wp_send_json_success([
            'count' => count($results),
            'html' => $this->render_results_rows($results),
        ]);
    }

    /**
     * Get event results with optional filtering
     */
    private function get_event_results(int $event_id, string $division = '', string $search = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $where = ['event_id = %d'];
        $params = [$event_id];

        if ($division) {
            $where[] = 'division = %s';
            $params[] = $division;
        }

        if ($search) {
            $where[] = '(athlete_name LIKE %s OR club LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY place ASC LIMIT 500",
            ...$params
        ), ARRAY_A);
    }

    /**
     * Render results table rows HTML
     */
    private function render_results_rows(array $results): string {
        ob_start();
        foreach ($results as $result) {
            ?>
            <tr data-division="<?php echo esc_attr($result['division'] ?? ''); ?>">
                <td><?php echo esc_html($result['place'] ?? '-'); ?></td>
                <td><strong><?php echo esc_html($result['athlete_name']); ?></strong></td>
                <td><?php echo esc_html($result['athlete_age'] ?? '-'); ?></td>
                <td><?php echo esc_html($result['division'] ?? '-'); ?></td>
                <td><?php echo esc_html($result['time_display'] ?? '-'); ?></td>
                <td><?php echo $result['points'] ? number_format($result['points'], 1) : '-'; ?></td>
                <td><?php echo esc_html($result['club'] ?? '-'); ?></td>
            </tr>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Check if result is a PR (placeholder)
     */
    private function is_pr(array $result): bool {
        // TODO: Implement PR checking via PerformanceTracker
        return false;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    FrontendDisplay::instance();
});
