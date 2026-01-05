<?php
/**
 * Athlete Self-Claim Feature
 *
 * Allows registered users to claim athlete profiles and link their results
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles athlete profile claiming and ownership
 */
class AthleteClaim {
    private static ?AthleteClaim $instance = null;

    public static function instance(): AthleteClaim {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_endpoints']);
        add_action('wp_ajax_pausatf_claim_athlete', [$this, 'ajax_claim_athlete']);
        add_action('wp_ajax_pausatf_verify_claim', [$this, 'ajax_verify_claim']);
        add_action('wp_ajax_pausatf_unclaim_athlete', [$this, 'ajax_unclaim_athlete']);
        add_shortcode('pausatf_my_results', [$this, 'render_my_results']);
        add_shortcode('pausatf_claim_form', [$this, 'render_claim_form']);
    }

    /**
     * Register rewrite endpoints
     */
    public function register_endpoints(): void {
        add_rewrite_endpoint('claim', EP_PAGES);
    }

    /**
     * Get athlete ID claimed by a user
     *
     * @param int $user_id WordPress user ID
     * @return int|null Athlete post ID
     */
    public function get_user_athlete(int $user_id): ?int {
        $athlete_id = get_user_meta($user_id, '_pausatf_athlete_id', true);
        return $athlete_id ? (int) $athlete_id : null;
    }

    /**
     * Get user who claimed an athlete
     *
     * @param int $athlete_id Athlete post ID
     * @return int|null User ID
     */
    public function get_athlete_owner(int $athlete_id): ?int {
        $user_id = get_post_meta($athlete_id, '_pausatf_claimed_by', true);
        return $user_id ? (int) $user_id : null;
    }

    /**
     * Check if athlete is claimed
     *
     * @param int $athlete_id Athlete post ID
     * @return bool
     */
    public function is_claimed(int $athlete_id): bool {
        return (bool) get_post_meta($athlete_id, '_pausatf_claimed_by', true);
    }

    /**
     * Check if user can claim athlete
     *
     * @param int $user_id WordPress user ID
     * @param int $athlete_id Athlete post ID
     * @return bool|string True if can claim, error message otherwise
     */
    public function can_claim(int $user_id, int $athlete_id): bool|string {
        // User must be logged in
        if (!$user_id) {
            return __('You must be logged in to claim a profile.', 'pausatf-results');
        }

        // Check if user already has a claimed athlete
        $existing = $this->get_user_athlete($user_id);
        if ($existing && $existing !== $athlete_id) {
            return __('You have already claimed an athlete profile.', 'pausatf-results');
        }

        // Check if athlete is already claimed
        $owner = $this->get_athlete_owner($athlete_id);
        if ($owner && $owner !== $user_id) {
            return __('This athlete profile has already been claimed.', 'pausatf-results');
        }

        // Check if athlete exists
        $athlete = get_post($athlete_id);
        if (!$athlete || $athlete->post_type !== 'pausatf_athlete') {
            return __('Athlete not found.', 'pausatf-results');
        }

        return true;
    }

    /**
     * Submit a claim request
     *
     * @param int    $user_id WordPress user ID
     * @param int    $athlete_id Athlete post ID
     * @param string $verification_method Method to verify (email, usatf, admin)
     * @return array Result
     */
    public function submit_claim(int $user_id, int $athlete_id, string $verification_method = 'admin'): array {
        $can_claim = $this->can_claim($user_id, $athlete_id);
        if ($can_claim !== true) {
            return ['success' => false, 'error' => $can_claim];
        }

        // Create claim request
        $claim_id = wp_insert_post([
            'post_type' => 'pausatf_claim',
            'post_title' => sprintf('Claim: %s by User %d', get_the_title($athlete_id), $user_id),
            'post_status' => 'pending',
            'meta_input' => [
                '_pausatf_user_id' => $user_id,
                '_pausatf_athlete_id' => $athlete_id,
                '_pausatf_verification_method' => $verification_method,
                '_pausatf_submitted_at' => current_time('mysql'),
            ],
        ]);

        if (is_wp_error($claim_id)) {
            return ['success' => false, 'error' => $claim_id->get_error_message()];
        }

        // Handle verification
        switch ($verification_method) {
            case 'email':
                $this->send_verification_email($user_id, $athlete_id, $claim_id);
                return [
                    'success' => true,
                    'claim_id' => $claim_id,
                    'status' => 'pending_email',
                    'message' => __('Verification email sent. Please check your inbox.', 'pausatf-results'),
                ];

            case 'auto':
                // Auto-approve if email matches
                if ($this->verify_email_match($user_id, $athlete_id)) {
                    return $this->approve_claim($claim_id);
                }
                // Fall through to admin review
                // no break

            case 'admin':
            default:
                $this->notify_admin_of_claim($claim_id);
                return [
                    'success' => true,
                    'claim_id' => $claim_id,
                    'status' => 'pending_review',
                    'message' => __('Your claim has been submitted for review.', 'pausatf-results'),
                ];
        }
    }

    /**
     * Approve a claim
     *
     * @param int $claim_id Claim post ID
     * @return array Result
     */
    public function approve_claim(int $claim_id): array {
        $user_id = (int) get_post_meta($claim_id, '_pausatf_user_id', true);
        $athlete_id = (int) get_post_meta($claim_id, '_pausatf_athlete_id', true);

        if (!$user_id || !$athlete_id) {
            return ['success' => false, 'error' => 'Invalid claim'];
        }

        // Link athlete to user
        update_user_meta($user_id, '_pausatf_athlete_id', $athlete_id);
        update_post_meta($athlete_id, '_pausatf_claimed_by', $user_id);
        update_post_meta($athlete_id, '_pausatf_claimed_at', current_time('mysql'));

        // Update claim status
        wp_update_post([
            'ID' => $claim_id,
            'post_status' => 'publish',
        ]);
        update_post_meta($claim_id, '_pausatf_approved_at', current_time('mysql'));

        // Link all results with this athlete name to the athlete ID
        $this->link_results_to_athlete($athlete_id);

        // Notify user
        $this->notify_user_approved($user_id, $athlete_id);

        return [
            'success' => true,
            'message' => __('Profile claimed successfully!', 'pausatf-results'),
        ];
    }

    /**
     * Deny a claim
     *
     * @param int    $claim_id Claim post ID
     * @param string $reason Denial reason
     * @return array Result
     */
    public function deny_claim(int $claim_id, string $reason = ''): array {
        $user_id = (int) get_post_meta($claim_id, '_pausatf_user_id', true);

        wp_update_post([
            'ID' => $claim_id,
            'post_status' => 'trash',
        ]);
        update_post_meta($claim_id, '_pausatf_denied_at', current_time('mysql'));
        update_post_meta($claim_id, '_pausatf_denial_reason', $reason);

        if ($user_id) {
            $this->notify_user_denied($user_id, $reason);
        }

        return ['success' => true, 'message' => __('Claim denied.', 'pausatf-results')];
    }

    /**
     * Unclaim an athlete profile
     *
     * @param int $user_id WordPress user ID
     * @return array Result
     */
    public function unclaim(int $user_id): array {
        $athlete_id = $this->get_user_athlete($user_id);

        if (!$athlete_id) {
            return ['success' => false, 'error' => 'No claimed profile found'];
        }

        // Verify ownership
        $owner = $this->get_athlete_owner($athlete_id);
        if ($owner !== $user_id) {
            return ['success' => false, 'error' => 'You do not own this profile'];
        }

        // Remove links
        delete_user_meta($user_id, '_pausatf_athlete_id');
        delete_post_meta($athlete_id, '_pausatf_claimed_by');
        delete_post_meta($athlete_id, '_pausatf_claimed_at');

        return ['success' => true, 'message' => __('Profile unclaimed.', 'pausatf-results')];
    }

    /**
     * Link results to athlete post
     */
    private function link_results_to_athlete(int $athlete_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $athlete_name = get_the_title($athlete_id);

        $wpdb->update(
            $table,
            ['athlete_id' => $athlete_id],
            ['athlete_name' => $athlete_name],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Check if user email matches any known athlete contact
     */
    private function verify_email_match(int $user_id, int $athlete_id): bool {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        $athlete_email = get_post_meta($athlete_id, '_pausatf_email', true);
        return $athlete_email && strtolower($user->user_email) === strtolower($athlete_email);
    }

    /**
     * Send verification email
     */
    private function send_verification_email(int $user_id, int $athlete_id, int $claim_id): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return;
        }

        $token = wp_generate_password(32, false);
        update_post_meta($claim_id, '_pausatf_verify_token', $token);
        update_post_meta($claim_id, '_pausatf_token_expires', time() + DAY_IN_SECONDS);

        $verify_url = add_query_arg([
            'pausatf_verify' => '1',
            'claim' => $claim_id,
            'token' => $token,
        ], home_url());

        $athlete_name = get_the_title($athlete_id);

        wp_mail(
            $user->user_email,
            sprintf(__('Verify your PAUSATF athlete profile claim: %s', 'pausatf-results'), $athlete_name),
            sprintf(
                __("You requested to claim the athlete profile for %s.\n\nClick here to verify: %s\n\nThis link expires in 24 hours.", 'pausatf-results'),
                $athlete_name,
                $verify_url
            )
        );
    }

    /**
     * Notify admin of new claim
     */
    private function notify_admin_of_claim(int $claim_id): void {
        $admin_email = get_option('admin_email');
        $claim = get_post($claim_id);

        wp_mail(
            $admin_email,
            __('New athlete profile claim request', 'pausatf-results'),
            sprintf(
                __("A new athlete profile claim has been submitted.\n\nReview it here: %s", 'pausatf-results'),
                admin_url('post.php?post=' . $claim_id . '&action=edit')
            )
        );
    }

    /**
     * Notify user of approval
     */
    private function notify_user_approved(int $user_id, int $athlete_id): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return;
        }

        wp_mail(
            $user->user_email,
            __('Your PAUSATF athlete profile claim has been approved', 'pausatf-results'),
            sprintf(
                __("Your claim for the athlete profile \"%s\" has been approved.\n\nView your profile: %s", 'pausatf-results'),
                get_the_title($athlete_id),
                get_permalink($athlete_id)
            )
        );
    }

    /**
     * Notify user of denial
     */
    private function notify_user_denied(int $user_id, string $reason): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return;
        }

        $message = __("Your athlete profile claim has been denied.", 'pausatf-results');
        if ($reason) {
            $message .= "\n\n" . __('Reason:', 'pausatf-results') . ' ' . $reason;
        }

        wp_mail(
            $user->user_email,
            __('Your PAUSATF athlete profile claim status', 'pausatf-results'),
            $message
        );
    }

    /**
     * AJAX handler for claiming
     */
    public function ajax_claim_athlete(): void {
        check_ajax_referer('pausatf_claim', 'nonce');

        $user_id = get_current_user_id();
        $athlete_id = absint($_POST['athlete_id'] ?? 0);

        if (!$user_id || !$athlete_id) {
            wp_send_json_error('Invalid request');
        }

        $result = $this->submit_claim($user_id, $athlete_id, 'auto');
        wp_send_json($result);
    }

    /**
     * AJAX handler for unclaiming
     */
    public function ajax_unclaim_athlete(): void {
        check_ajax_referer('pausatf_claim', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $result = $this->unclaim($user_id);
        wp_send_json($result);
    }

    /**
     * AJAX handler for email verification
     */
    public function ajax_verify_claim(): void {
        $claim_id = absint($_GET['claim'] ?? 0);
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$claim_id || !$token) {
            wp_die('Invalid verification link');
        }

        $stored_token = get_post_meta($claim_id, '_pausatf_verify_token', true);
        $expires = (int) get_post_meta($claim_id, '_pausatf_token_expires', true);

        if ($token !== $stored_token) {
            wp_die('Invalid token');
        }

        if (time() > $expires) {
            wp_die('Verification link has expired');
        }

        $result = $this->approve_claim($claim_id);

        if ($result['success']) {
            wp_redirect(home_url('/my-results/?verified=1'));
        } else {
            wp_die($result['error']);
        }
        exit;
    }

    /**
     * Render "My Results" shortcode
     */
    public function render_my_results(array $atts): string {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your results.', 'pausatf-results') . '</p>';
        }

        $user_id = get_current_user_id();
        $athlete_id = $this->get_user_athlete($user_id);

        if (!$athlete_id) {
            return $this->render_claim_prompt();
        }

        $athlete_db = new AthleteDatabase();
        $athlete_name = get_the_title($athlete_id);
        $results = $athlete_db->get_athlete_results($athlete_name, $athlete_id);
        $stats = $athlete_db->get_athlete_stats($athlete_name);

        ob_start();
        ?>
        <div class="pausatf-my-results">
            <h3><?php echo esc_html($athlete_name); ?></h3>

            <div class="pausatf-my-stats">
                <div class="stat">
                    <strong><?php echo number_format($stats['total_events'] ?? 0); ?></strong>
                    <span><?php esc_html_e('Events', 'pausatf-results'); ?></span>
                </div>
                <div class="stat">
                    <strong><?php echo number_format($stats['wins'] ?? 0); ?></strong>
                    <span><?php esc_html_e('Wins', 'pausatf-results'); ?></span>
                </div>
                <div class="stat">
                    <strong><?php echo number_format($stats['total_points'] ?? 0, 1); ?></strong>
                    <span><?php esc_html_e('Points', 'pausatf-results'); ?></span>
                </div>
            </div>

            <h4><?php esc_html_e('Your Results', 'pausatf-results'); ?></h4>
            <!-- Results table would go here -->

            <p>
                <button class="pausatf-btn pausatf-btn-secondary" id="pausatf-unclaim-btn">
                    <?php esc_html_e('Unclaim This Profile', 'pausatf-results'); ?>
                </button>
            </p>
        </div>

        <script>
        jQuery('#pausatf-unclaim-btn').on('click', function() {
            if (confirm('Are you sure you want to unclaim this profile?')) {
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'pausatf_unclaim_athlete',
                    nonce: '<?php echo wp_create_nonce('pausatf_claim'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.error || 'Error unclaiming profile');
                    }
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render claim prompt for users without a claimed profile
     */
    private function render_claim_prompt(): string {
        ob_start();
        ?>
        <div class="pausatf-claim-prompt">
            <h3><?php esc_html_e('Claim Your Athlete Profile', 'pausatf-results'); ?></h3>
            <p><?php esc_html_e('Search for your name to find and claim your athlete profile.', 'pausatf-results'); ?></p>
            <?php echo do_shortcode('[pausatf_claim_form]'); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render claim form shortcode
     */
    public function render_claim_form(array $atts): string {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to claim a profile.', 'pausatf-results') . '</p>';
        }

        $user_id = get_current_user_id();
        $existing = $this->get_user_athlete($user_id);

        if ($existing) {
            return '<p>' . sprintf(
                __('You have already claimed a profile: %s', 'pausatf-results'),
                '<a href="' . get_permalink($existing) . '">' . get_the_title($existing) . '</a>'
            ) . '</p>';
        }

        ob_start();
        ?>
        <div class="pausatf-claim-form">
            <form id="pausatf-search-claim">
                <input type="text" id="pausatf-claim-search" placeholder="<?php esc_attr_e('Search for your name...', 'pausatf-results'); ?>">
                <button type="submit" class="pausatf-btn"><?php esc_html_e('Search', 'pausatf-results'); ?></button>
            </form>
            <div id="pausatf-claim-results"></div>
        </div>

        <script>
        jQuery('#pausatf-search-claim').on('submit', function(e) {
            e.preventDefault();
            var query = jQuery('#pausatf-claim-search').val();
            if (query.length < 2) return;

            jQuery.get('<?php echo rest_url('pausatf/v1/athletes/search'); ?>', {q: query}, function(response) {
                var html = '';
                if (response.athletes && response.athletes.length) {
                    html = '<ul class="pausatf-claim-list">';
                    response.athletes.forEach(function(athlete) {
                        html += '<li>';
                        html += '<strong>' + athlete.athlete_name + '</strong>';
                        html += ' <span>(' + athlete.event_count + ' events)</span>';
                        html += ' <button class="pausatf-btn pausatf-btn-sm pausatf-claim-btn" data-name="' + athlete.athlete_name + '">Claim</button>';
                        html += '</li>';
                    });
                    html += '</ul>';
                } else {
                    html = '<p>No athletes found.</p>';
                }
                jQuery('#pausatf-claim-results').html(html);
            });
        });

        jQuery(document).on('click', '.pausatf-claim-btn', function() {
            var name = jQuery(this).data('name');
            // This would need athlete ID - simplified for demo
            alert('Claim feature requires athlete ID lookup. Contact admin to claim: ' + name);
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('plugins_loaded', function() {
    AthleteClaim::instance();
});

// Register claim post type
add_action('init', function() {
    register_post_type('pausatf_claim', [
        'labels' => [
            'name' => __('Profile Claims', 'pausatf-results'),
            'singular_name' => __('Profile Claim', 'pausatf-results'),
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'pausatf-results',
        'supports' => ['title'],
    ]);
});
