<?php
/**
 * Public Sanctions Functionality
 *
 * Handles public-facing sanction forms and displays.
 *
 * @package PAUSATF\Results\Sanctions
 */

namespace PAUSATF\Results;

use PAUSATF\Results\Sanctions\SanctionFees;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanctions Public class
 */
class SanctionsPublic {
    private static ?SanctionsPublic $instance = null;

    public static function instance(): SanctionsPublic {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('pausatf_sanction_form', [$this, 'render_sanction_form']);
        add_shortcode('pausatf_my_sanctions', [$this, 'render_my_sanctions']);
        add_shortcode('pausatf_sanctioned_events', [$this, 'render_sanctioned_events']);

        // Handle form submissions
        add_action('init', [$this, 'handle_form_submission']);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue public styles and scripts
     */
    public function enqueue_assets(): void {
        // Only load on pages with our shortcodes
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'pausatf_sanction_form') &&
                      !has_shortcode($post->post_content, 'pausatf_my_sanctions') &&
                      !has_shortcode($post->post_content, 'pausatf_sanctioned_events')) {
            return;
        }

        wp_enqueue_style(
            'pausatf-sanctions-public',
            PAUSATF_RESULTS_URL . 'assets/css/sanctions-public.css',
            [],
            PAUSATF_RESULTS_VERSION
        );
    }

    /**
     * Render sanction application form
     *
     * [pausatf_sanction_form]
     */
    public function render_sanction_form(array $atts): string {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return $this->render_login_required(__('You must be logged in to submit a sanction application.', 'pausatf-results'));
        }

        // Check for success/error messages
        $message = '';
        if (isset($_GET['sanction_submitted'])) {
            $message = '<div class="pausatf-notice pausatf-success">' .
                esc_html__('Your sanction application has been submitted successfully. You will receive an email confirmation shortly.', 'pausatf-results') .
                '</div>';
        }
        if (isset($_GET['sanction_error'])) {
            $message = '<div class="pausatf-notice pausatf-error">' .
                esc_html(urldecode($_GET['sanction_error'])) .
                '</div>';
        }

        // Event types
        $event_types = [
            'road' => __('Road Race', 'pausatf-results'),
            'track' => __('Track & Field', 'pausatf-results'),
            'xc' => __('Cross Country', 'pausatf-results'),
            'trail' => __('Trail Running', 'pausatf-results'),
            'racewalk' => __('Race Walk', 'pausatf-results'),
            'multi' => __('Multi-Day Event', 'pausatf-results'),
        ];

        // Organization types
        $org_types = [
            '' => __('Select...', 'pausatf-results'),
            'club' => __('Running Club', 'pausatf-results'),
            'nonprofit' => __('Non-Profit Organization', 'pausatf-results'),
            'forprofit' => __('For-Profit Company', 'pausatf-results'),
            'government' => __('Government/Municipality', 'pausatf-results'),
            'school' => __('School/University', 'pausatf-results'),
            'other' => __('Other', 'pausatf-results'),
        ];

        // Pre-fill with user data
        $current_user = wp_get_current_user();

        ob_start();
        ?>
        <div class="pausatf-sanction-form-wrap">
            <?php echo $message; ?>

            <h2><?php esc_html_e('Event Sanction Application', 'pausatf-results'); ?></h2>

            <p class="form-intro">
                <?php esc_html_e('Complete this form to apply for PA USATF event sanction. Applications should be submitted at least 30 days before your event.', 'pausatf-results'); ?>
            </p>

            <form method="post" action="" class="pausatf-sanction-form" id="sanction-application-form">
                <?php wp_nonce_field('pausatf_public_sanction_form', 'sanction_form_nonce'); ?>

                <!-- Event Information -->
                <fieldset>
                    <legend><?php esc_html_e('Event Information', 'pausatf-results'); ?></legend>

                    <div class="form-row">
                        <label for="event_name"><?php esc_html_e('Event Name', 'pausatf-results'); ?> <span class="required">*</span></label>
                        <input type="text" name="event_name" id="event_name" required
                               placeholder="<?php esc_attr_e('e.g., Philadelphia Marathon', 'pausatf-results'); ?>">
                    </div>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="event_date"><?php esc_html_e('Event Date', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <input type="date" name="event_date" id="event_date" required
                                   min="<?php echo esc_attr(date('Y-m-d')); ?>">
                        </div>
                        <div>
                            <label for="event_end_date"><?php esc_html_e('End Date (if multi-day)', 'pausatf-results'); ?></label>
                            <input type="date" name="event_end_date" id="event_end_date">
                        </div>
                    </div>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="event_type"><?php esc_html_e('Event Type', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <select name="event_type" id="event_type" required>
                                <?php foreach ($event_types as $type => $label) : ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="event_distance"><?php esc_html_e('Distance(s)', 'pausatf-results'); ?></label>
                            <input type="text" name="event_distance" id="event_distance"
                                   placeholder="<?php esc_attr_e('e.g., Marathon, Half, 5K', 'pausatf-results'); ?>">
                        </div>
                    </div>
                </fieldset>

                <!-- Location -->
                <fieldset>
                    <legend><?php esc_html_e('Event Location', 'pausatf-results'); ?></legend>

                    <div class="form-row">
                        <label for="event_venue"><?php esc_html_e('Venue Name', 'pausatf-results'); ?></label>
                        <input type="text" name="event_venue" id="event_venue"
                               placeholder="<?php esc_attr_e('e.g., Franklin Field, City Park', 'pausatf-results'); ?>">
                    </div>

                    <div class="form-row">
                        <label for="event_location"><?php esc_html_e('Street Address', 'pausatf-results'); ?> <span class="required">*</span></label>
                        <input type="text" name="event_location" id="event_location" required>
                    </div>

                    <div class="form-row form-row-triple">
                        <div>
                            <label for="event_city"><?php esc_html_e('City', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <input type="text" name="event_city" id="event_city" required>
                        </div>
                        <div>
                            <label for="event_state"><?php esc_html_e('State', 'pausatf-results'); ?></label>
                            <input type="text" name="event_state" id="event_state" value="PA" readonly>
                        </div>
                        <div>
                            <label for="event_zip"><?php esc_html_e('ZIP Code', 'pausatf-results'); ?></label>
                            <input type="text" name="event_zip" id="event_zip" maxlength="10">
                        </div>
                    </div>

                    <div class="form-row checkbox-row">
                        <label>
                            <input type="checkbox" name="course_certified" id="course_certified" value="1">
                            <?php esc_html_e('Course is USATF certified', 'pausatf-results'); ?>
                        </label>
                    </div>

                    <div class="form-row cert-number-field" style="display: none;">
                        <label for="course_certification_number"><?php esc_html_e('Certification Number', 'pausatf-results'); ?></label>
                        <input type="text" name="course_certification_number" id="course_certification_number"
                               placeholder="<?php esc_attr_e('e.g., PA12345XY', 'pausatf-results'); ?>">
                    </div>
                </fieldset>

                <!-- Organizer Information -->
                <fieldset>
                    <legend><?php esc_html_e('Organizer Information', 'pausatf-results'); ?></legend>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="organizer_name"><?php esc_html_e('Contact Name', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <input type="text" name="organizer_name" id="organizer_name" required
                                   value="<?php echo esc_attr($current_user->display_name); ?>">
                        </div>
                        <div>
                            <label for="organizer_email"><?php esc_html_e('Email Address', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <input type="email" name="organizer_email" id="organizer_email" required
                                   value="<?php echo esc_attr($current_user->user_email); ?>">
                        </div>
                    </div>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="organizer_phone"><?php esc_html_e('Phone Number', 'pausatf-results'); ?></label>
                            <input type="tel" name="organizer_phone" id="organizer_phone">
                        </div>
                        <div>
                            <label for="organizer_usatf_number"><?php esc_html_e('USATF Member #', 'pausatf-results'); ?></label>
                            <input type="text" name="organizer_usatf_number" id="organizer_usatf_number">
                        </div>
                    </div>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="organization_name"><?php esc_html_e('Organization Name', 'pausatf-results'); ?></label>
                            <input type="text" name="organization_name" id="organization_name">
                        </div>
                        <div>
                            <label for="organization_type"><?php esc_html_e('Organization Type', 'pausatf-results'); ?></label>
                            <select name="organization_type" id="organization_type">
                                <?php foreach ($org_types as $type => $label) : ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row checkbox-row">
                        <label>
                            <input type="checkbox" name="safesport_completed" id="safesport_completed" value="1">
                            <?php esc_html_e('I have completed SafeSport training', 'pausatf-results'); ?>
                        </label>
                        <p class="field-description">
                            <?php printf(
                                esc_html__('SafeSport training is required. %s', 'pausatf-results'),
                                '<a href="https://safesport.org/" target="_blank">' . esc_html__('Learn more', 'pausatf-results') . '</a>'
                            ); ?>
                        </p>
                    </div>
                </fieldset>

                <!-- Participation -->
                <fieldset>
                    <legend><?php esc_html_e('Participation Estimates', 'pausatf-results'); ?></legend>

                    <div class="form-row form-row-double">
                        <div>
                            <label for="estimated_finishers"><?php esc_html_e('Estimated Finishers', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <input type="number" name="estimated_finishers" id="estimated_finishers" required min="1" value="100">
                            <p class="field-description"><?php esc_html_e('Fees are based on this estimate.', 'pausatf-results'); ?></p>
                        </div>
                        <div>
                            <label for="estimated_volunteers"><?php esc_html_e('Estimated Volunteers', 'pausatf-results'); ?></label>
                            <input type="number" name="estimated_volunteers" id="estimated_volunteers" min="0" value="10">
                        </div>
                    </div>

                    <div class="form-row checkbox-row">
                        <label>
                            <input type="checkbox" name="has_elite_athletes" id="has_elite_athletes" value="1">
                            <?php esc_html_e('Event will include elite athletes', 'pausatf-results'); ?>
                        </label>
                    </div>

                    <div class="form-row">
                        <label for="prize_money_total"><?php esc_html_e('Total Prize Money', 'pausatf-results'); ?></label>
                        <div class="input-with-prefix">
                            <span class="input-prefix">$</span>
                            <input type="number" name="prize_money_total" id="prize_money_total" min="0" step="0.01" value="0">
                        </div>
                        <p class="field-description"><?php esc_html_e('Events with prize money over $500 per individual incur additional fees.', 'pausatf-results'); ?></p>
                    </div>
                </fieldset>

                <!-- Fee Estimate -->
                <div class="fee-estimate-section">
                    <h3><?php esc_html_e('Estimated Fee', 'pausatf-results'); ?></h3>
                    <div class="fee-estimate">
                        <div class="fee-row">
                            <span><?php esc_html_e('National Fee:', 'pausatf-results'); ?></span>
                            <span id="fee-national">$50.00</span>
                        </div>
                        <div class="fee-row">
                            <span><?php esc_html_e('Local (PA) Fee:', 'pausatf-results'); ?></span>
                            <span id="fee-local">$25.00</span>
                        </div>
                        <div class="fee-row fee-late" id="late-fee-row" style="display: none;">
                            <span><?php esc_html_e('Late Fee:', 'pausatf-results'); ?></span>
                            <span id="fee-late">$50.00</span>
                        </div>
                        <div class="fee-row fee-total">
                            <span><?php esc_html_e('Total:', 'pausatf-results'); ?></span>
                            <span id="fee-total">$75.00</span>
                        </div>
                        <p class="fee-tier" id="fee-tier">1-100 finishers</p>
                    </div>
                    <p class="fee-note">
                        <?php esc_html_e('Final fees may be adjusted based on actual participation.', 'pausatf-results'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=fees')); ?>" target="_blank">
                            <?php esc_html_e('View full fee schedule', 'pausatf-results'); ?>
                        </a>
                    </p>
                </div>

                <!-- Agreement -->
                <div class="agreement-section">
                    <label class="checkbox-row">
                        <input type="checkbox" name="agree_terms" id="agree_terms" required value="1">
                        <?php printf(
                            esc_html__('I agree to the %s and understand that this application is subject to review.', 'pausatf-results'),
                            '<a href="https://www.usatf.org/programs/sanctions" target="_blank">' . esc_html__('USATF sanction terms and conditions', 'pausatf-results') . '</a>'
                        ); ?>
                    </label>
                </div>

                <!-- Submit -->
                <div class="form-actions">
                    <button type="submit" name="submit_sanction" class="button button-primary">
                        <?php esc_html_e('Submit Application', 'pausatf-results'); ?>
                    </button>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle certification number field
            $('#course_certified').on('change', function() {
                $('.cert-number-field').toggle(this.checked);
            });

            // Calculate fee estimate
            function updateFeeEstimate() {
                var finishers = parseInt($('#estimated_finishers').val()) || 100;
                var eventDate = $('#event_date').val();
                var isElite = parseFloat($('#prize_money_total').val()) > 500 ? 1 : 0;

                $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    action: 'pausatf_calculate_sanction_fee',
                    nonce: '<?php echo wp_create_nonce('pausatf_sanctions_nonce'); ?>',
                    finishers: finishers,
                    event_date: eventDate,
                    is_elite: isElite
                }, function(response) {
                    if (response.success) {
                        $('#fee-national').text('$' + response.data.national_fee);
                        $('#fee-local').text('$' + response.data.local_fee);
                        $('#fee-total').text('$' + response.data.total);
                        $('#fee-tier').text(response.data.tier_label);

                        if (response.data.is_late) {
                            $('#late-fee-row').show();
                            $('#fee-late').text('$' + response.data.late_fee);
                        } else {
                            $('#late-fee-row').hide();
                        }
                    }
                });
            }

            $('#estimated_finishers, #event_date, #prize_money_total').on('change', function() {
                updateFeeEstimate();
            });
        });
        </script>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Render user's sanctions dashboard
     *
     * [pausatf_my_sanctions]
     */
    public function render_my_sanctions(array $atts): string {
        if (!is_user_logged_in()) {
            return $this->render_login_required(__('You must be logged in to view your sanctions.', 'pausatf-results'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_sanctions';
        $current_user_id = get_current_user_id();

        // Get user's sanctions
        $sanctions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE applicant_user_id = %d ORDER BY created_at DESC",
            $current_user_id
        ), ARRAY_A);

        // Status labels
        $status_labels = [
            'draft' => __('Draft', 'pausatf-results'),
            'submitted' => __('Submitted', 'pausatf-results'),
            'under_review' => __('Under Review', 'pausatf-results'),
            'approved' => __('Approved', 'pausatf-results'),
            'rejected' => __('Rejected', 'pausatf-results'),
            'cancelled' => __('Cancelled', 'pausatf-results'),
        ];

        ob_start();
        ?>
        <div class="pausatf-my-sanctions">
            <h2><?php esc_html_e('My Sanction Applications', 'pausatf-results'); ?></h2>

            <?php if (empty($sanctions)) : ?>
                <p><?php esc_html_e('You have not submitted any sanction applications yet.', 'pausatf-results'); ?></p>
                <p>
                    <?php
                        $app_page = get_page_by_path('sanction-application');
                        $app_url = $app_page ? get_permalink($app_page) : '';
                    ?>
                    <a href="<?php echo esc_url($app_url ?: ''); ?>" class="button">
                        <?php esc_html_e('Submit New Application', 'pausatf-results'); ?>
                    </a>
                </p>
            <?php else : ?>
                <table class="pausatf-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Event', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Date', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Status', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Sanction #', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Actions', 'pausatf-results'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sanctions as $sanction) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($sanction['event_name']); ?></strong>
                                    <br><small><?php echo esc_html($sanction['event_city'] . ', ' . $sanction['event_state']); ?></small>
                                </td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></td>
                                <td>
                                    <span class="sanction-status sanction-status-<?php echo esc_attr($sanction['local_status']); ?>">
                                        <?php echo esc_html($status_labels[$sanction['local_status']] ?? $sanction['local_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($sanction['usatf_sanction_number']) : ?>
                                        <code><?php echo esc_html($sanction['usatf_sanction_number']); ?></code>
                                    <?php else : ?>
                                        <span class="na">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?view_sanction=<?php echo esc_attr($sanction['id']); ?>">
                                        <?php esc_html_e('View', 'pausatf-results'); ?>
                                    </a>
                                    <?php if ($sanction['local_status'] === 'approved') : ?>
                                        <?php
                                        $report_table = $wpdb->prefix . 'pausatf_sanction_reports';
                                        $has_report = $wpdb->get_var($wpdb->prepare(
                                            "SELECT COUNT(*) FROM {$report_table} WHERE sanction_id = %d AND report_type = 'post_event'",
                                            $sanction['id']
                                        ));
                                        ?>
                                        <?php if (!$has_report && strtotime($sanction['event_date']) < time()) : ?>
                                            | <a href="?submit_report=<?php echo esc_attr($sanction['id']); ?>" class="report-needed">
                                                <?php esc_html_e('Submit Report', 'pausatf-results'); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Render list of sanctioned events
     *
     * [pausatf_sanctioned_events year="2024" limit="20"]
     */
    public function render_sanctioned_events(array $atts): string {
        $atts = shortcode_atts([
            'year' => date('Y'),
            'limit' => 50,
            'upcoming' => 'true',
        ], $atts);

        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_sanctions';

        $where = ["local_status = 'approved'"];
        $params = [];

        if ($atts['year']) {
            $where[] = 'YEAR(event_date) = %d';
            $params[] = (int) $atts['year'];
        }

        if ($atts['upcoming'] === 'true') {
            $where[] = 'event_date >= %s';
            $params[] = date('Y-m-d');
        }

        $where_sql = implode(' AND ', $where);
        $params[] = (int) $atts['limit'];

        $query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY event_date ASC LIMIT %d";
        $events = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

        // Event types
        $event_types = [
            'road' => __('Road', 'pausatf-results'),
            'track' => __('Track', 'pausatf-results'),
            'xc' => __('XC', 'pausatf-results'),
            'trail' => __('Trail', 'pausatf-results'),
            'racewalk' => __('Racewalk', 'pausatf-results'),
            'multi' => __('Multi', 'pausatf-results'),
        ];

        ob_start();
        ?>
        <div class="pausatf-sanctioned-events">
            <h2>
                <?php
                if ($atts['upcoming'] === 'true') {
                    esc_html_e('Upcoming Sanctioned Events', 'pausatf-results');
                } else {
                    printf(esc_html__('%d Sanctioned Events', 'pausatf-results'), $atts['year']);
                }
                ?>
            </h2>

            <?php if (empty($events)) : ?>
                <p><?php esc_html_e('No sanctioned events found.', 'pausatf-results'); ?></p>
            <?php else : ?>
                <table class="pausatf-table pausatf-events-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Event', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Location', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Type', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Sanction #', 'pausatf-results'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event) : ?>
                            <tr>
                                <td class="event-date">
                                    <span class="month"><?php echo esc_html(date_i18n('M', strtotime($event['event_date']))); ?></span>
                                    <span class="day"><?php echo esc_html(date_i18n('j', strtotime($event['event_date']))); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($event['event_name']); ?></strong>
                                    <?php if ($event['event_distance']) : ?>
                                        <br><small><?php echo esc_html($event['event_distance']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($event['event_city'] . ', ' . $event['event_state']); ?>
                                    <?php if ($event['event_venue']) : ?>
                                        <br><small><?php echo esc_html($event['event_venue']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="event-type event-type-<?php echo esc_attr($event['event_type']); ?>">
                                        <?php echo esc_html($event_types[$event['event_type']] ?? $event['event_type']); ?>
                                    </span>
                                    <?php if ($event['course_certified']) : ?>
                                        <span class="certified-badge" title="<?php esc_attr_e('USATF Certified Course', 'pausatf-results'); ?>">
                                            <span class="dashicons dashicons-yes"></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($event['usatf_sanction_number']) : ?>
                                        <code><?php echo esc_html($event['usatf_sanction_number']); ?></code>
                                    <?php else : ?>
                                        <span class="na">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Handle form submission
     */
    public function handle_form_submission(): void {
        if (!isset($_POST['submit_sanction']) || !isset($_POST['sanction_form_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['sanction_form_nonce'], 'pausatf_public_sanction_form')) {
            wp_die(__('Security check failed.', 'pausatf-results'));
        }

        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in.', 'pausatf-results'));
        }

        // Validate required fields
        $required = ['event_name', 'event_date', 'event_type', 'event_location', 'event_city', 'organizer_name', 'organizer_email', 'estimated_finishers'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_safe_redirect(add_query_arg('sanction_error', urlencode(__('Please fill in all required fields.', 'pausatf-results')), wp_get_referer()));
                exit;
            }
        }

        // Validate email
        if (!is_email($_POST['organizer_email'])) {
            wp_safe_redirect(add_query_arg('sanction_error', urlencode(__('Please enter a valid email address.', 'pausatf-results')), wp_get_referer()));
            exit;
        }

        // Validate agreement
        if (empty($_POST['agree_terms'])) {
            wp_safe_redirect(add_query_arg('sanction_error', urlencode(__('You must agree to the terms and conditions.', 'pausatf-results')), wp_get_referer()));
            exit;
        }

        // Calculate fees
        $estimated_finishers = absint($_POST['estimated_finishers']);
        $event_date = sanitize_text_field($_POST['event_date']);
        $is_elite = !empty($_POST['prize_money_total']) && floatval($_POST['prize_money_total']) > 500;
        $is_late = SanctionFees::is_late_submission($event_date);
        $fees = SanctionFees::calculate($estimated_finishers, $is_elite, $is_late);

        // Prepare data
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_sanctions';

        $data = [
            'event_name' => sanitize_text_field($_POST['event_name']),
            'event_date' => $event_date,
            'event_end_date' => !empty($_POST['event_end_date']) ? sanitize_text_field($_POST['event_end_date']) : null,
            'event_type' => sanitize_text_field($_POST['event_type']),
            'event_distance' => sanitize_text_field($_POST['event_distance'] ?? ''),
            'event_location' => sanitize_text_field($_POST['event_location']),
            'event_city' => sanitize_text_field($_POST['event_city']),
            'event_state' => 'PA',
            'event_zip' => sanitize_text_field($_POST['event_zip'] ?? ''),
            'event_venue' => sanitize_text_field($_POST['event_venue'] ?? ''),
            'course_certified' => !empty($_POST['course_certified']) ? 1 : 0,
            'course_certification_number' => sanitize_text_field($_POST['course_certification_number'] ?? ''),
            'organizer_name' => sanitize_text_field($_POST['organizer_name']),
            'organizer_email' => sanitize_email($_POST['organizer_email']),
            'organizer_phone' => sanitize_text_field($_POST['organizer_phone'] ?? ''),
            'organizer_usatf_number' => sanitize_text_field($_POST['organizer_usatf_number'] ?? ''),
            'organization_name' => sanitize_text_field($_POST['organization_name'] ?? ''),
            'organization_type' => sanitize_text_field($_POST['organization_type'] ?? ''),
            'safesport_completed' => !empty($_POST['safesport_completed']) ? 1 : 0,
            'estimated_finishers' => $estimated_finishers,
            'estimated_volunteers' => absint($_POST['estimated_volunteers'] ?? 0),
            'has_elite_athletes' => !empty($_POST['has_elite_athletes']) ? 1 : 0,
            'prize_money_total' => floatval($_POST['prize_money_total'] ?? 0),
            'national_fee' => $fees['national'],
            'local_fee' => $fees['local'],
            'total_fee' => $fees['total'],
            'local_status' => 'submitted',
            'submitted_at' => current_time('mysql'),
            'applicant_user_id' => get_current_user_id(),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            wp_safe_redirect(add_query_arg('sanction_error', urlencode(__('Error submitting application. Please try again.', 'pausatf-results')), wp_get_referer()));
            exit;
        }

        $sanction_id = $wpdb->insert_id;

        // Log history
        $history_table = $wpdb->prefix . 'pausatf_sanction_history';
        $wpdb->insert($history_table, [
            'sanction_id' => $sanction_id,
            'action' => 'created',
            'new_value' => 'submitted',
            'changed_by' => get_current_user_id(),
            'changed_at' => current_time('mysql'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Send notification emails
        if (class_exists('\PAUSATF\Results\Sanctions\SanctionNotifications')) {
            $sanction = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $sanction_id), ARRAY_A);
            \PAUSATF\Results\Sanctions\SanctionNotifications::send_submitted($sanction);
        }

        wp_safe_redirect(add_query_arg('sanction_submitted', '1', wp_get_referer()));
        exit;
    }

    /**
     * Render login required message
     */
    private function render_login_required(string $message): string {
        ob_start();
        ?>
        <div class="pausatf-login-required">
            <p><?php echo esc_html($message); ?></p>
            <p>
                <a href="<?php echo esc_url(wp_login_url(get_permalink() ?: '')); ?>" class="button">
                    <?php esc_html_e('Log In', 'pausatf-results'); ?>
                </a>
                <?php if (get_option('users_can_register')) : ?>
                    <a href="<?php echo esc_url(wp_registration_url()); ?>" class="button">
                        <?php esc_html_e('Register', 'pausatf-results'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }
}

// Initialize when sanctions feature is enabled
add_action('init', function() {
    if (class_exists('\PAUSATF\Results\FeatureManager')) {
        if (\PAUSATF\Results\FeatureManager::is_enabled('sanctions_manager')) {
            SanctionsPublic::instance();
        }
    }
}, 15);
