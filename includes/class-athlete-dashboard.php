<?php
/**
 * Athlete Dashboard
 *
 * Personal portal showing results, PRs, rankings, and upcoming events
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Athlete dashboard functionality
 */
class AthleteDashboard {
    /**
     * Initialize dashboard
     */
    public function __construct() {
        add_shortcode('pausatf_athlete_dashboard', [$this, 'render_dashboard']);
        add_action('wp_ajax_pausatf_dashboard_data', [$this, 'ajax_get_dashboard_data']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue dashboard assets
     */
    public function enqueue_assets(): void {
        if (is_page() && has_shortcode(get_post()->post_content ?? '', 'pausatf_athlete_dashboard')) {
            wp_enqueue_style(
                'pausatf-dashboard',
                PAUSATF_RESULTS_URL . 'assets/css/dashboard.css',
                [],
                PAUSATF_RESULTS_VERSION
            );

            wp_enqueue_script(
                'pausatf-dashboard',
                PAUSATF_RESULTS_URL . 'assets/js/dashboard.js',
                ['jquery'],
                PAUSATF_RESULTS_VERSION,
                true
            );

            wp_localize_script('pausatf-dashboard', 'pausatfDashboard', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pausatf_dashboard'),
            ]);
        }
    }

    /**
     * Render dashboard shortcode
     */
    public function render_dashboard(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<div class="pausatf-login-prompt">
                <p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view your athlete dashboard.</p>
            </div>';
        }

        $user_id = get_current_user_id();
        $athlete_id = $this->get_linked_athlete($user_id);

        if (!$athlete_id) {
            return $this->render_claim_prompt();
        }

        $data = $this->get_dashboard_data($athlete_id);

        ob_start();
        ?>
        <div class="pausatf-athlete-dashboard" data-athlete-id="<?php echo esc_attr($athlete_id); ?>">
            <!-- Profile Header -->
            <div class="dashboard-header">
                <div class="athlete-info">
                    <?php if (has_post_thumbnail($athlete_id)): ?>
                        <div class="athlete-photo">
                            <?php echo get_the_post_thumbnail($athlete_id, 'thumbnail'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="athlete-details">
                        <h1><?php echo esc_html(get_the_title($athlete_id)); ?></h1>
                        <?php if ($data['club']): ?>
                            <p class="club"><?php echo esc_html($data['club']); ?></p>
                        <?php endif; ?>
                        <?php if ($data['usatf_member']): ?>
                            <span class="usatf-badge">USATF Member</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="quick-stats">
                    <div class="stat">
                        <span class="stat-value"><?php echo esc_html($data['total_races']); ?></span>
                        <span class="stat-label">Races</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo esc_html($data['total_prs']); ?></span>
                        <span class="stat-label">PRs</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo esc_html($data['records_held']); ?></span>
                        <span class="stat-label">Records</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Navigation -->
            <nav class="dashboard-nav">
                <button class="nav-tab active" data-tab="overview">Overview</button>
                <button class="nav-tab" data-tab="results">Results</button>
                <button class="nav-tab" data-tab="prs">Personal Records</button>
                <button class="nav-tab" data-tab="rankings">Rankings</button>
                <button class="nav-tab" data-tab="records">Records</button>
                <button class="nav-tab" data-tab="settings">Settings</button>
            </nav>

            <!-- Tab Content -->
            <div class="dashboard-content">
                <!-- Overview Tab -->
                <div class="tab-panel active" id="tab-overview">
                    <div class="dashboard-grid">
                        <!-- Recent Results -->
                        <div class="dashboard-card">
                            <h3>Recent Results</h3>
                            <?php if (!empty($data['recent_results'])): ?>
                                <ul class="results-list">
                                    <?php foreach (array_slice($data['recent_results'], 0, 5) as $result): ?>
                                        <li>
                                            <span class="event-name"><?php echo esc_html($result['event_name']); ?></span>
                                            <span class="result-time"><?php echo esc_html($result['time_display']); ?></span>
                                            <span class="result-place">#<?php echo esc_html($result['place']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="no-data">No recent results</p>
                            <?php endif; ?>
                        </div>

                        <!-- PR Highlights -->
                        <div class="dashboard-card">
                            <h3>Personal Records</h3>
                            <?php if (!empty($data['prs'])): ?>
                                <ul class="pr-list">
                                    <?php foreach (array_slice($data['prs'], 0, 5) as $event => $pr): ?>
                                        <li>
                                            <span class="pr-event"><?php echo esc_html($event); ?></span>
                                            <span class="pr-time"><?php echo esc_html($pr['time_display']); ?></span>
                                            <?php if ($pr['is_recent']): ?>
                                                <span class="pr-badge new">NEW</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="no-data">No personal records yet</p>
                            <?php endif; ?>
                        </div>

                        <!-- Rankings -->
                        <div class="dashboard-card">
                            <h3>Current Rankings</h3>
                            <?php if (!empty($data['rankings'])): ?>
                                <ul class="rankings-list">
                                    <?php foreach (array_slice($data['rankings'], 0, 5) as $ranking): ?>
                                        <li>
                                            <span class="rank-event"><?php echo esc_html($ranking['event']); ?></span>
                                            <span class="rank-position">#<?php echo esc_html($ranking['rank_position']); ?></span>
                                            <span class="rank-division"><?php echo esc_html($ranking['division_code']); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="no-data">No rankings available</p>
                            <?php endif; ?>
                        </div>

                        <!-- Activity Chart -->
                        <div class="dashboard-card full-width">
                            <h3>Race Activity</h3>
                            <canvas id="activity-chart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Results Tab -->
                <div class="tab-panel" id="tab-results">
                    <div class="results-filters">
                        <select id="results-year">
                            <option value="">All Years</option>
                            <?php for ($y = date('Y'); $y >= 2000; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="results-event">
                            <option value="">All Events</option>
                            <option value="5K">5K</option>
                            <option value="10K">10K</option>
                            <option value="Half Marathon">Half Marathon</option>
                            <option value="Marathon">Marathon</option>
                        </select>
                    </div>
                    <div id="results-table-container">
                        <!-- Loaded via AJAX -->
                    </div>
                </div>

                <!-- PRs Tab -->
                <div class="tab-panel" id="tab-prs">
                    <div class="pr-cards">
                        <?php foreach ($data['prs'] ?? [] as $event => $pr): ?>
                            <div class="pr-card">
                                <h4><?php echo esc_html($event); ?></h4>
                                <div class="pr-time"><?php echo esc_html($pr['time_display']); ?></div>
                                <div class="pr-details">
                                    <span class="pr-date"><?php echo esc_html($pr['date']); ?></span>
                                    <span class="pr-location"><?php echo esc_html($pr['event_name']); ?></span>
                                </div>
                                <?php if ($pr['age_graded']): ?>
                                    <div class="age-graded">
                                        Age-graded: <?php echo esc_html($pr['age_graded']); ?>%
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Rankings Tab -->
                <div class="tab-panel" id="tab-rankings">
                    <div class="rankings-section">
                        <h3>Division Rankings</h3>
                        <table class="rankings-table">
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Division</th>
                                    <th>Rank</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['rankings'] ?? [] as $ranking): ?>
                                    <tr>
                                        <td><?php echo esc_html($ranking['event']); ?></td>
                                        <td><?php echo esc_html($ranking['division_code']); ?></td>
                                        <td>#<?php echo esc_html($ranking['rank_position']); ?></td>
                                        <td><?php echo esc_html($ranking['best_performance_display']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Records Tab -->
                <div class="tab-panel" id="tab-records">
                    <?php if (!empty($data['records'])): ?>
                        <h3>Association Records Held</h3>
                        <div class="records-grid">
                            <?php foreach ($data['records'] as $record): ?>
                                <div class="record-card">
                                    <div class="record-badge">RECORD</div>
                                    <h4><?php echo esc_html($record['event']); ?></h4>
                                    <div class="record-division"><?php echo esc_html($record['division_code']); ?></div>
                                    <div class="record-performance"><?php echo esc_html($record['performance_display']); ?></div>
                                    <div class="record-date"><?php echo esc_html($record['record_date']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-data">You don't hold any association records yet. Keep racing!</p>
                    <?php endif; ?>
                </div>

                <!-- Settings Tab -->
                <div class="tab-panel" id="tab-settings">
                    <form id="athlete-settings-form">
                        <h3>Profile Settings</h3>

                        <div class="form-group">
                            <label>Display Name</label>
                            <input type="text" name="display_name" value="<?php echo esc_attr($data['display_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Club/Team</label>
                            <input type="text" name="club" value="<?php echo esc_attr($data['club']); ?>">
                        </div>

                        <div class="form-group">
                            <label>USATF Number</label>
                            <input type="text" name="usatf_number" value="<?php echo esc_attr($data['usatf_number']); ?>">
                            <?php if ($data['usatf_verified']): ?>
                                <span class="verified-badge">Verified</span>
                            <?php endif; ?>
                        </div>

                        <h3>Connected Accounts</h3>

                        <div class="connected-accounts">
                            <div class="account-row">
                                <span class="account-name">Strava</span>
                                <?php if ($data['strava_connected']): ?>
                                    <span class="connected">Connected</span>
                                    <button type="button" class="disconnect-btn" data-service="strava">Disconnect</button>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($data['strava_connect_url']); ?>" class="connect-btn">Connect</a>
                                <?php endif; ?>
                            </div>

                            <div class="account-row">
                                <span class="account-name">Athlinks</span>
                                <?php if ($data['athlinks_connected']): ?>
                                    <span class="connected">Connected</span>
                                    <button type="button" class="sync-btn" data-service="athlinks">Sync Results</button>
                                <?php else: ?>
                                    <button type="button" class="connect-btn" data-service="athlinks">Link Account</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h3>Notification Preferences</h3>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="notify_new_results" <?php checked($data['notify_new_results']); ?>>
                                Email me when new results are posted
                            </label>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="notify_rankings" <?php checked($data['notify_rankings']); ?>>
                                Email me about ranking changes
                            </label>
                        </div>

                        <button type="submit" class="save-btn">Save Settings</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render claim prompt for unlinked users
     */
    private function render_claim_prompt(): string {
        ob_start();
        ?>
        <div class="pausatf-claim-prompt">
            <h2>Link Your Athlete Profile</h2>
            <p>To access your personal dashboard, you need to link your account to an athlete profile.</p>

            <form id="athlete-search-form" class="claim-search">
                <input type="text" name="athlete_name" placeholder="Search for your name..." required>
                <button type="submit">Search</button>
            </form>

            <div id="athlete-search-results"></div>

            <p class="help-text">
                Can't find yourself? Your results may be listed under a different name.
                <a href="<?php echo esc_url(home_url('/contact')); ?>">Contact us</a> for help.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get linked athlete ID for user
     */
    private function get_linked_athlete(int $user_id): ?int {
        $athlete_id = get_user_meta($user_id, '_pausatf_athlete_id', true);
        return $athlete_id ? (int) $athlete_id : null;
    }

    /**
     * Get dashboard data for athlete
     */
    public function get_dashboard_data(int $athlete_id): array {
        global $wpdb;

        $results_table = $wpdb->prefix . 'pausatf_results';
        $records_table = $wpdb->prefix . 'pausatf_records';
        $rankings_table = $wpdb->prefix . 'pausatf_rankings';

        $athlete = get_post($athlete_id);
        $user_id = get_current_user_id();

        // Get recent results
        $recent_results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM {$results_table} r
             INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.athlete_id = %d
             ORDER BY r.created_at DESC
             LIMIT 20",
            $athlete_id
        ), ARRAY_A);

        // Get PRs
        $prs = $this->get_athlete_prs($athlete_id);

        // Get rankings
        $rankings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$rankings_table}
             WHERE athlete_id = %d
             ORDER BY rank_position ASC",
            $athlete_id
        ), ARRAY_A);

        // Get records held
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$records_table}
             WHERE athlete_id = %d AND verified = 1",
            $athlete_id
        ), ARRAY_A);

        // Get integrations status
        $strava = new \PAUSATF\Results\Integrations\StravaSync();

        return [
            'athlete_id' => $athlete_id,
            'display_name' => $athlete ? $athlete->post_title : '',
            'club' => get_post_meta($athlete_id, '_pausatf_club', true),
            'usatf_member' => (bool) get_post_meta($athlete_id, '_pausatf_usatf_verified', true),
            'usatf_number' => get_post_meta($athlete_id, '_pausatf_usatf_number', true),
            'usatf_verified' => (bool) get_post_meta($athlete_id, '_pausatf_usatf_verified', true),
            'total_races' => count($recent_results),
            'total_prs' => count($prs),
            'records_held' => count($records),
            'recent_results' => $recent_results,
            'prs' => $prs,
            'rankings' => $rankings,
            'records' => $records,
            'strava_connected' => $strava->is_connected($user_id),
            'strava_connect_url' => $strava->get_auth_url($user_id),
            'athlinks_connected' => (bool) get_post_meta($athlete_id, '_pausatf_athlinks_id', true),
            'notify_new_results' => (bool) get_user_meta($user_id, '_pausatf_notify_results', true),
            'notify_rankings' => (bool) get_user_meta($user_id, '_pausatf_notify_rankings', true),
        ];
    }

    /**
     * Get athlete's personal records by event
     */
    private function get_athlete_prs(int $athlete_id): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'pausatf_results';

        // Get best time per event type
        $sql = "SELECT
                    r.*,
                    p.post_title as event_name,
                    pm.meta_value as event_date
                FROM {$results_table} r
                INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_pausatf_event_date'
                WHERE r.athlete_id = %d
                  AND r.time_seconds IS NOT NULL
                ORDER BY r.time_seconds ASC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $athlete_id), ARRAY_A);

        // Group by event distance and keep best
        $prs = [];
        $event_patterns = [
            '5K' => '/5\s*k/i',
            '10K' => '/10\s*k/i',
            'Half Marathon' => '/half|13\.1/i',
            'Marathon' => '/marathon|26\.2/i',
            '10 Miles' => '/10\s*mile/i',
        ];

        foreach ($results as $result) {
            foreach ($event_patterns as $event => $pattern) {
                if (preg_match($pattern, $result['event_name'])) {
                    if (!isset($prs[$event]) || $result['time_seconds'] < $prs[$event]['time_seconds']) {
                        $prs[$event] = [
                            'time_seconds' => $result['time_seconds'],
                            'time_display' => $result['time_display'],
                            'event_name' => $result['event_name'],
                            'date' => $result['event_date'],
                            'is_recent' => strtotime($result['event_date']) > strtotime('-30 days'),
                            'age_graded' => null, // Would calculate with age-grading
                        ];
                    }
                    break;
                }
            }
        }

        return $prs;
    }

    /**
     * AJAX handler for dashboard data
     */
    public function ajax_get_dashboard_data(): void {
        check_ajax_referer('pausatf_dashboard', 'nonce');

        $athlete_id = (int) ($_POST['athlete_id'] ?? 0);

        if (!$athlete_id) {
            wp_send_json_error('Invalid athlete ID');
        }

        $data = $this->get_dashboard_data($athlete_id);
        wp_send_json_success($data);
    }
}

// Initialize
new AthleteDashboard();
