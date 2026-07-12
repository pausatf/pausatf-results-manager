<?php
/**
 * Sanction Edit/Create Admin View
 *
 * @package PAUSATF\Results\Sanctions
 */

if (!defined('ABSPATH')) {
    exit;
}

use PAUSATF\Results\Sanctions\SanctionFees;

global $wpdb;

// Get sanction data if editing
$sanction_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
$sanction = null;
$is_new = true;

if ($sanction_id) {
    $table = $wpdb->prefix . 'pausatf_sanctions';
    $sanction = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $sanction_id), ARRAY_A);
    if ($sanction) {
        $is_new = false;
    }
}

// Default values for new sanction
$defaults = [
    'event_name' => '',
    'event_date' => '',
    'event_end_date' => '',
    'event_type' => 'road',
    'event_distance' => '',
    'event_location' => '',
    'event_city' => '',
    'event_state' => 'PA',
    'event_zip' => '',
    'event_venue' => '',
    'course_certified' => 0,
    'course_certification_number' => '',
    'organizer_name' => '',
    'organizer_email' => '',
    'organizer_phone' => '',
    'organizer_usatf_number' => '',
    'organization_name' => '',
    'organization_type' => '',
    'safesport_completed' => 0,
    'safesport_completion_date' => '',
    'estimated_finishers' => 100,
    'estimated_volunteers' => 10,
    'has_elite_athletes' => 0,
    'prize_money_total' => 0,
    'usatf_sanction_number' => '',
    'national_status' => 'not_submitted',
    'local_status' => 'draft',
    'review_notes' => '',
];

$data = $sanction ? array_merge($defaults, $sanction) : $defaults;

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

// US States
$states = [
    'PA' => 'Pennsylvania', 'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona',
    'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
    'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
    'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
    'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
    'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
    'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
    'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
    'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
    'OK' => 'Oklahoma', 'OR' => 'Oregon', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
    'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
    'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
    'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
];

// Status labels
$status_labels = [
    'draft' => __('Draft', 'pausatf-results'),
    'submitted' => __('Submitted', 'pausatf-results'),
    'under_review' => __('Under Review', 'pausatf-results'),
    'approved' => __('Approved', 'pausatf-results'),
    'rejected' => __('Rejected', 'pausatf-results'),
    'cancelled' => __('Cancelled', 'pausatf-results'),
];

// Calculate estimated fees
$fee_estimate = SanctionFees::get_estimate(
    (int) $data['estimated_finishers'],
    $data['event_date'],
    (bool) ($data['prize_money_total'] > 500)
);
?>

<div class="wrap">
    <h1>
        <?php echo $is_new
            ? esc_html__('New Sanction Application', 'pausatf-results')
            : esc_html__('Edit Sanction Application', 'pausatf-results');
        ?>
    </h1>

    <?php if (!$is_new) : ?>
        <p class="sanction-meta">
            <span class="sanction-status sanction-status-<?php echo esc_attr($data['local_status']); ?>">
                <?php echo esc_html($status_labels[$data['local_status']] ?? $data['local_status']); ?>
            </span>
            <?php if ($data['usatf_sanction_number']) : ?>
                <span class="sanction-number">
                    <?php esc_html_e('Sanction #:', 'pausatf-results'); ?>
                    <code><?php echo esc_html($data['usatf_sanction_number']); ?></code>
                </span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="" id="sanction-form" class="sanction-form">
        <?php wp_nonce_field('pausatf_save_sanction', 'sanction_nonce'); ?>
        <input type="hidden" name="sanction_id" value="<?php echo esc_attr((string) $sanction_id); ?>">
        <input type="hidden" name="action" value="pausatf_save_sanction">

        <div class="sanction-form-columns">
            <!-- Main Content -->
            <div class="sanction-form-main">

                <!-- Event Information -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Event Information', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="event_name"><?php esc_html_e('Event Name', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="event_name" id="event_name"
                                           class="large-text" required
                                           value="<?php echo esc_attr($data['event_name']); ?>"
                                           placeholder="<?php esc_attr_e('e.g., Philadelphia Marathon', 'pausatf-results'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_date"><?php esc_html_e('Event Date', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="date" name="event_date" id="event_date" required
                                           value="<?php echo esc_attr($data['event_date']); ?>">
                                    <label for="event_end_date" class="inline-label">
                                        <?php esc_html_e('to', 'pausatf-results'); ?>
                                    </label>
                                    <input type="date" name="event_end_date" id="event_end_date"
                                           value="<?php echo esc_attr($data['event_end_date']); ?>">
                                    <p class="description"><?php esc_html_e('End date is optional for multi-day events.', 'pausatf-results'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_type"><?php esc_html_e('Event Type', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select name="event_type" id="event_type" required>
                                        <?php foreach ($event_types as $type => $label) : ?>
                                            <option value="<?php echo esc_attr($type); ?>" <?php selected($data['event_type'], $type); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_distance"><?php esc_html_e('Distance(s)', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="event_distance" id="event_distance" class="regular-text"
                                           value="<?php echo esc_attr($data['event_distance']); ?>"
                                           placeholder="<?php esc_attr_e('e.g., Marathon, Half Marathon, 5K', 'pausatf-results'); ?>">
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Location -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Event Location', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="event_venue"><?php esc_html_e('Venue Name', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="event_venue" id="event_venue" class="large-text"
                                           value="<?php echo esc_attr($data['event_venue']); ?>"
                                           placeholder="<?php esc_attr_e('e.g., Franklin Field, Eakins Oval', 'pausatf-results'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_location"><?php esc_html_e('Street Address', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="event_location" id="event_location" class="large-text" required
                                           value="<?php echo esc_attr($data['event_location']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_city"><?php esc_html_e('City', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="event_city" id="event_city" class="regular-text" required
                                           value="<?php echo esc_attr($data['event_city']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_state"><?php esc_html_e('State', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select name="event_state" id="event_state" required>
                                        <?php foreach ($states as $abbr => $name) : ?>
                                            <option value="<?php echo esc_attr($abbr); ?>" <?php selected($data['event_state'], $abbr); ?>>
                                                <?php echo esc_html($name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="event_zip"><?php esc_html_e('ZIP Code', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="event_zip" id="event_zip" class="small-text"
                                           value="<?php echo esc_attr($data['event_zip']); ?>"
                                           pattern="[0-9]{5}(-[0-9]{4})?" maxlength="10">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Course Certification', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="course_certified" value="1"
                                               <?php checked($data['course_certified'], 1); ?>
                                               id="course_certified">
                                        <?php esc_html_e('Course is USATF certified', 'pausatf-results'); ?>
                                    </label>
                                    <div class="cert-number-field" style="margin-top: 10px; <?php echo $data['course_certified'] ? '' : 'display: none;'; ?>">
                                        <label for="course_certification_number"><?php esc_html_e('Certification Number:', 'pausatf-results'); ?></label>
                                        <input type="text" name="course_certification_number" id="course_certification_number"
                                               class="regular-text"
                                               value="<?php echo esc_attr($data['course_certification_number']); ?>"
                                               placeholder="<?php esc_attr_e('e.g., PA12345XY', 'pausatf-results'); ?>">
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Organizer Information -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Organizer Information', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="organizer_name"><?php esc_html_e('Contact Name', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" name="organizer_name" id="organizer_name" class="regular-text" required
                                           value="<?php echo esc_attr($data['organizer_name']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="organizer_email"><?php esc_html_e('Email Address', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="email" name="organizer_email" id="organizer_email" class="regular-text" required
                                           value="<?php echo esc_attr($data['organizer_email']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="organizer_phone"><?php esc_html_e('Phone Number', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="tel" name="organizer_phone" id="organizer_phone" class="regular-text"
                                           value="<?php echo esc_attr($data['organizer_phone']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="organizer_usatf_number"><?php esc_html_e('USATF Member #', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="organizer_usatf_number" id="organizer_usatf_number" class="regular-text"
                                           value="<?php echo esc_attr($data['organizer_usatf_number']); ?>">
                                    <p class="description"><?php esc_html_e('USATF membership is required for event organizers.', 'pausatf-results'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="organization_name"><?php esc_html_e('Organization', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="organization_name" id="organization_name" class="large-text"
                                           value="<?php echo esc_attr($data['organization_name']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="organization_type"><?php esc_html_e('Organization Type', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <select name="organization_type" id="organization_type">
                                        <?php foreach ($org_types as $type => $label) : ?>
                                            <option value="<?php echo esc_attr($type); ?>" <?php selected($data['organization_type'], $type); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('SafeSport Training', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="safesport_completed" value="1"
                                               <?php checked($data['safesport_completed'], 1); ?>
                                               id="safesport_completed">
                                        <?php esc_html_e('SafeSport training completed', 'pausatf-results'); ?>
                                    </label>
                                    <div class="safesport-date-field" style="margin-top: 10px; <?php echo $data['safesport_completed'] ? '' : 'display: none;'; ?>">
                                        <label for="safesport_completion_date"><?php esc_html_e('Completion Date:', 'pausatf-results'); ?></label>
                                        <input type="date" name="safesport_completion_date" id="safesport_completion_date"
                                               value="<?php echo esc_attr($data['safesport_completion_date']); ?>">
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('SafeSport training is required for all event organizers.', 'pausatf-results'); ?>
                                        <a href="https://safesport.org/" target="_blank"><?php esc_html_e('Learn more', 'pausatf-results'); ?></a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Participation & Prize Money -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Participation Estimates', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="estimated_finishers"><?php esc_html_e('Estimated Finishers', 'pausatf-results'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="number" name="estimated_finishers" id="estimated_finishers"
                                           class="small-text" required min="1"
                                           value="<?php echo esc_attr($data['estimated_finishers']); ?>">
                                    <p class="description"><?php esc_html_e('Fees are based on estimated finisher count.', 'pausatf-results'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="estimated_volunteers"><?php esc_html_e('Estimated Volunteers', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="estimated_volunteers" id="estimated_volunteers"
                                           class="small-text" min="0"
                                           value="<?php echo esc_attr($data['estimated_volunteers']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Elite Athletes', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="has_elite_athletes" value="1"
                                               <?php checked($data['has_elite_athletes'], 1); ?>
                                               id="has_elite_athletes">
                                        <?php esc_html_e('Event will include elite athletes', 'pausatf-results'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="prize_money_total"><?php esc_html_e('Total Prize Money', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <span class="currency-prefix">$</span>
                                    <input type="number" name="prize_money_total" id="prize_money_total"
                                           class="small-text" min="0" step="0.01"
                                           value="<?php echo esc_attr($data['prize_money_total']); ?>">
                                    <p class="description"><?php esc_html_e('Events with prize money over $500 per individual incur additional fees.', 'pausatf-results'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php if (current_user_can('manage_sanctions')) : ?>
                <!-- Admin Only: National Tracking -->
                <div class="postbox admin-only">
                    <h2 class="hndle"><?php esc_html_e('National USATF Tracking', 'pausatf-results'); ?> <span class="admin-badge"><?php esc_html_e('Admin Only', 'pausatf-results'); ?></span></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="usatf_sanction_number"><?php esc_html_e('USATF Sanction Number', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="usatf_sanction_number" id="usatf_sanction_number" class="regular-text"
                                           value="<?php echo esc_attr($data['usatf_sanction_number']); ?>"
                                           placeholder="<?php esc_attr_e('e.g., 24-1234-PA', 'pausatf-results'); ?>">
                                    <p class="description"><?php esc_html_e('Enter the sanction number after national approval.', 'pausatf-results'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="national_status"><?php esc_html_e('National Status', 'pausatf-results'); ?></label>
                                </th>
                                <td>
                                    <select name="national_status" id="national_status">
                                        <option value="not_submitted" <?php selected($data['national_status'], 'not_submitted'); ?>>
                                            <?php esc_html_e('Not Submitted', 'pausatf-results'); ?>
                                        </option>
                                        <option value="pending" <?php selected($data['national_status'], 'pending'); ?>>
                                            <?php esc_html_e('Pending', 'pausatf-results'); ?>
                                        </option>
                                        <option value="approved" <?php selected($data['national_status'], 'approved'); ?>>
                                            <?php esc_html_e('Approved', 'pausatf-results'); ?>
                                        </option>
                                        <option value="denied" <?php selected($data['national_status'], 'denied'); ?>>
                                            <?php esc_html_e('Denied', 'pausatf-results'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Sidebar -->
            <div class="sanction-form-sidebar">

                <!-- Fee Estimate -->
                <div class="postbox" id="fee-estimate-box">
                    <h2 class="hndle"><?php esc_html_e('Fee Estimate', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <div class="fee-estimate">
                            <div class="fee-row">
                                <span class="fee-label"><?php esc_html_e('National Fee:', 'pausatf-results'); ?></span>
                                <span class="fee-value" id="fee-national">$<?php echo esc_html($fee_estimate['national_fee']); ?></span>
                            </div>
                            <div class="fee-row">
                                <span class="fee-label"><?php esc_html_e('Local (PA) Fee:', 'pausatf-results'); ?></span>
                                <span class="fee-value" id="fee-local">$<?php echo esc_html($fee_estimate['local_fee']); ?></span>
                            </div>
                            <?php if ($fee_estimate['is_late']) : ?>
                            <div class="fee-row fee-late">
                                <span class="fee-label"><?php esc_html_e('Late Fee:', 'pausatf-results'); ?></span>
                                <span class="fee-value" id="fee-late">$<?php echo esc_html($fee_estimate['late_fee']); ?></span>
                            </div>
                            <?php endif; ?>
                            <hr>
                            <div class="fee-row fee-total">
                                <span class="fee-label"><?php esc_html_e('Total:', 'pausatf-results'); ?></span>
                                <span class="fee-value" id="fee-total">$<?php echo esc_html($fee_estimate['total']); ?></span>
                            </div>
                            <p class="fee-tier" id="fee-tier"><?php echo esc_html($fee_estimate['tier_label']); ?></p>
                        </div>
                        <p class="description">
                            <?php esc_html_e('Fees are calculated based on estimated finishers. Final fees may be adjusted based on actual participation.', 'pausatf-results'); ?>
                        </p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=fees')); ?>" target="_blank">
                                <?php esc_html_e('View full fee schedule', 'pausatf-results'); ?>
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Actions', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <?php if ($is_new || $data['local_status'] === 'draft') : ?>
                            <p>
                                <button type="submit" name="save_draft" class="button button-secondary button-large" style="width: 100%;">
                                    <?php esc_html_e('Save Draft', 'pausatf-results'); ?>
                                </button>
                            </p>
                            <p>
                                <button type="submit" name="submit_for_review" class="button button-primary button-large" style="width: 100%;">
                                    <?php esc_html_e('Submit for Review', 'pausatf-results'); ?>
                                </button>
                            </p>
                        <?php elseif ($data['local_status'] === 'submitted' || $data['local_status'] === 'under_review') : ?>
                            <p>
                                <button type="submit" name="save_changes" class="button button-primary button-large" style="width: 100%;">
                                    <?php esc_html_e('Save Changes', 'pausatf-results'); ?>
                                </button>
                            </p>
                            <?php if (current_user_can('review_sanctions')) : ?>
                                <hr>
                                <p>
                                    <button type="submit" name="approve" class="button button-large approve-btn" style="width: 100%;">
                                        <?php esc_html_e('Approve', 'pausatf-results'); ?>
                                    </button>
                                </p>
                                <p>
                                    <button type="submit" name="reject" class="button button-large reject-btn" style="width: 100%;">
                                        <?php esc_html_e('Reject', 'pausatf-results'); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        <?php else : ?>
                            <p>
                                <button type="submit" name="save_changes" class="button button-primary button-large" style="width: 100%;">
                                    <?php esc_html_e('Save Changes', 'pausatf-results'); ?>
                                </button>
                            </p>
                        <?php endif; ?>

                        <?php if (!$is_new && $data['local_status'] !== 'cancelled') : ?>
                            <hr>
                            <p>
                                <a href="#" class="cancel-sanction" data-id="<?php echo esc_attr((string) $sanction_id); ?>">
                                    <?php esc_html_e('Cancel Application', 'pausatf-results'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$is_new && $data['local_status'] === 'approved') : ?>
                <!-- Post-Event Report -->
                <div class="postbox">
                    <h2 class="hndle"><?php esc_html_e('Post-Event Report', 'pausatf-results'); ?></h2>
                    <div class="inside">
                        <?php
                        $report_table = $wpdb->prefix . 'pausatf_sanction_reports';
                        $has_report = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$report_table} WHERE sanction_id = %d AND report_type = 'post_event'",
                            $sanction_id
                        ));
                        ?>
                        <?php if ($has_report) : ?>
                            <p class="report-status report-submitted">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Report Submitted', 'pausatf-results'); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=view-report&id=' . $sanction_id)); ?>">
                                    <?php esc_html_e('View Report', 'pausatf-results'); ?>
                                </a>
                            </p>
                        <?php else : ?>
                            <p class="report-status report-pending">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e('Report Required', 'pausatf-results'); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=report&id=' . $sanction_id)); ?>"
                                   class="button">
                                    <?php esc_html_e('Submit Report', 'pausatf-results'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </form>
</div>

<style>
.sanction-meta {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}
.sanction-status {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 3px;
    font-size: 13px;
    font-weight: 500;
}
.sanction-status-draft { background: #e2e3e5; color: #41464b; }
.sanction-status-submitted { background: #cfe2ff; color: #084298; }
.sanction-status-under_review { background: #fff3cd; color: #664d03; }
.sanction-status-approved { background: #d1e7dd; color: #0a3622; }
.sanction-status-rejected { background: #f8d7da; color: #58151c; }
.sanction-status-cancelled { background: #d3d3d4; color: #41464b; }

.sanction-form-columns {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
    margin-top: 20px;
}
.sanction-form-main .postbox { margin-bottom: 20px; }
.sanction-form-main .hndle { padding: 12px; margin: 0; }
.sanction-form-main .inside { padding: 0 12px 12px; }

.sanction-form-sidebar .postbox {
    margin-bottom: 15px;
}

.required { color: #d63638; }
.inline-label { margin: 0 10px; }

.admin-only {
    border-color: #2271b1;
}
.admin-badge {
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: normal;
    margin-left: 10px;
}

.fee-estimate { margin-bottom: 15px; }
.fee-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
}
.fee-total { font-weight: bold; font-size: 16px; }
.fee-late { color: #d63638; }
.fee-tier {
    text-align: center;
    color: #646970;
    font-size: 12px;
    margin-top: 10px;
}

.approve-btn {
    background: #00a32a !important;
    border-color: #00a32a !important;
    color: #fff !important;
}
.approve-btn:hover {
    background: #008a20 !important;
}
.reject-btn {
    background: #d63638 !important;
    border-color: #d63638 !important;
    color: #fff !important;
}
.reject-btn:hover {
    background: #b32d2e !important;
}

.cancel-sanction {
    color: #d63638;
}

.report-status {
    display: flex;
    align-items: center;
    gap: 8px;
}
.report-submitted { color: #00a32a; }
.report-pending { color: #dba617; }

.currency-prefix {
    font-size: 14px;
    margin-right: 2px;
}

@media screen and (max-width: 960px) {
    .sanction-form-columns {
        grid-template-columns: 1fr;
    }
    .sanction-form-sidebar {
        order: -1;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle certification number field
    $('#course_certified').on('change', function() {
        $('.cert-number-field').toggle(this.checked);
    });

    // Toggle SafeSport date field
    $('#safesport_completed').on('change', function() {
        $('.safesport-date-field').toggle(this.checked);
    });

    // Calculate fee estimate on change
    var feeTimeout;
    $('#estimated_finishers, #event_date, #prize_money_total').on('change input', function() {
        clearTimeout(feeTimeout);
        feeTimeout = setTimeout(updateFeeEstimate, 500);
    });

    function updateFeeEstimate() {
        var data = {
            action: 'pausatf_calculate_sanction_fee',
            nonce: '<?php echo wp_create_nonce('pausatf_sanctions_nonce'); ?>',
            finishers: $('#estimated_finishers').val(),
            event_date: $('#event_date').val(),
            is_elite: $('#prize_money_total').val() > 500 ? 1 : 0
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                $('#fee-national').text('$' + response.data.national_fee);
                $('#fee-local').text('$' + response.data.local_fee);
                $('#fee-total').text('$' + response.data.total);
                $('#fee-tier').text(response.data.tier_label);
            }
        });
    }

    // Cancel sanction confirmation
    $('.cancel-sanction').on('click', function(e) {
        e.preventDefault();
        if (confirm('<?php echo esc_js(__('Are you sure you want to cancel this sanction application? This action cannot be undone.', 'pausatf-results')); ?>')) {
            var sanctionId = $(this).data('id');
            $.post(ajaxurl, {
                action: 'pausatf_cancel_sanction',
                nonce: '<?php echo wp_create_nonce('pausatf_sanctions_nonce'); ?>',
                id: sanctionId
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error cancelling sanction.', 'pausatf-results')); ?>');
                }
            });
        }
    });
});
</script>
