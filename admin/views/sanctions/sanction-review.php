<?php
/**
 * Sanction Review Admin View
 *
 * Admin interface for reviewing and approving/rejecting sanction applications.
 *
 * @package PAUSATF\Results\Sanctions
 */

if (!defined('ABSPATH')) {
    exit;
}

use PAUSATF\Results\Sanctions\SanctionFees;

// Check permissions
if (!current_user_can('review_sanctions')) {
    wp_die(__('You do not have permission to review sanctions.', 'pausatf-results'));
}

global $wpdb;

// Get sanction data
$sanction_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
if (!$sanction_id) {
    wp_die(__('Invalid sanction ID.', 'pausatf-results'));
}

$table = $wpdb->prefix . 'pausatf_sanctions';
$sanction = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $sanction_id), ARRAY_A);

if (!$sanction) {
    wp_die(__('Sanction not found.', 'pausatf-results'));
}

// Get history
$history_table = $wpdb->prefix . 'pausatf_sanction_history';
$history = $wpdb->get_results($wpdb->prepare(
    "SELECT h.*, u.display_name as user_name
     FROM {$history_table} h
     LEFT JOIN {$wpdb->users} u ON h.changed_by = u.ID
     WHERE h.sanction_id = %d
     ORDER BY h.changed_at DESC
     LIMIT 20",
    $sanction_id
), ARRAY_A);

// Get applicant info
$applicant = null;
if ($sanction['applicant_user_id']) {
    $applicant = get_userdata($sanction['applicant_user_id']);
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

// Status labels
$status_labels = [
    'draft' => __('Draft', 'pausatf-results'),
    'submitted' => __('Submitted', 'pausatf-results'),
    'under_review' => __('Under Review', 'pausatf-results'),
    'approved' => __('Approved', 'pausatf-results'),
    'rejected' => __('Rejected', 'pausatf-results'),
    'cancelled' => __('Cancelled', 'pausatf-results'),
];

// Calculate fees
$fee_estimate = SanctionFees::get_estimate(
    (int) $sanction['estimated_finishers'],
    $sanction['event_date'],
    (bool) ($sanction['prize_money_total'] > 500)
);

// Check if late submission
$is_late = SanctionFees::is_late_submission($sanction['event_date']);
$days_until = floor((strtotime($sanction['event_date']) - time()) / DAY_IN_SECONDS);
?>

<div class="wrap">
    <h1>
        <?php esc_html_e('Review Sanction Application', 'pausatf-results'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions')); ?>" class="page-title-action">
            <?php esc_html_e('Back to List', 'pausatf-results'); ?>
        </a>
    </h1>

    <div class="review-header">
        <div class="review-status">
            <span class="sanction-status sanction-status-<?php echo esc_attr($sanction['local_status']); ?>">
                <?php echo esc_html($status_labels[$sanction['local_status']] ?? $sanction['local_status']); ?>
            </span>
            <?php if ($is_late) : ?>
                <span class="late-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Late Submission', 'pausatf-results'); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="review-timing">
            <?php if ($days_until > 0) : ?>
                <?php printf(esc_html__('%d days until event', 'pausatf-results'), $days_until); ?>
            <?php elseif ((int) $days_until === 0) : ?>
                <?php esc_html_e('Event is today', 'pausatf-results'); ?>
            <?php else : ?>
                <span class="past-event"><?php esc_html_e('Event has passed', 'pausatf-results'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="review-columns">
        <!-- Main Content -->
        <div class="review-main">

            <!-- Event Summary Card -->
            <div class="review-card">
                <h2><?php echo esc_html($sanction['event_name']); ?></h2>
                <div class="event-summary-grid">
                    <div class="summary-item">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <div>
                            <strong><?php esc_html_e('Date', 'pausatf-results'); ?></strong>
                            <?php
                            echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date'])));
                            if ($sanction['event_end_date'] && $sanction['event_end_date'] !== $sanction['event_date']) {
                                echo ' - ' . esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_end_date'])));
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="dashicons dashicons-flag"></span>
                        <div>
                            <strong><?php esc_html_e('Type', 'pausatf-results'); ?></strong>
                            <?php echo esc_html($event_types[$sanction['event_type']] ?? $sanction['event_type']); ?>
                            <?php if ($sanction['event_distance']) : ?>
                                <br><?php echo esc_html($sanction['event_distance']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="dashicons dashicons-location"></span>
                        <div>
                            <strong><?php esc_html_e('Location', 'pausatf-results'); ?></strong>
                            <?php if ($sanction['event_venue']) : ?>
                                <?php echo esc_html($sanction['event_venue']); ?><br>
                            <?php endif; ?>
                            <?php echo esc_html($sanction['event_location']); ?><br>
                            <?php echo esc_html($sanction['event_city'] . ', ' . $sanction['event_state'] . ' ' . $sanction['event_zip']); ?>
                        </div>
                    </div>
                    <div class="summary-item">
                        <span class="dashicons dashicons-groups"></span>
                        <div>
                            <strong><?php esc_html_e('Estimated', 'pausatf-results'); ?></strong>
                            <?php printf(esc_html__('%s finishers', 'pausatf-results'), number_format($sanction['estimated_finishers'])); ?><br>
                            <?php printf(esc_html__('%s volunteers', 'pausatf-results'), number_format($sanction['estimated_volunteers'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Organizer Information -->
            <div class="review-card">
                <h3><?php esc_html_e('Organizer Information', 'pausatf-results'); ?></h3>
                <table class="review-table">
                    <tr>
                        <th><?php esc_html_e('Contact Name', 'pausatf-results'); ?></th>
                        <td><?php echo esc_html($sanction['organizer_name']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email', 'pausatf-results'); ?></th>
                        <td><a href="mailto:<?php echo esc_attr($sanction['organizer_email']); ?>"><?php echo esc_html($sanction['organizer_email']); ?></a></td>
                    </tr>
                    <?php if ($sanction['organizer_phone']) : ?>
                    <tr>
                        <th><?php esc_html_e('Phone', 'pausatf-results'); ?></th>
                        <td><?php echo esc_html($sanction['organizer_phone']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($sanction['organizer_usatf_number']) : ?>
                    <tr>
                        <th><?php esc_html_e('USATF Member #', 'pausatf-results'); ?></th>
                        <td><code><?php echo esc_html($sanction['organizer_usatf_number']); ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($sanction['organization_name']) : ?>
                    <tr>
                        <th><?php esc_html_e('Organization', 'pausatf-results'); ?></th>
                        <td>
                            <?php echo esc_html($sanction['organization_name']); ?>
                            <?php if ($sanction['organization_type']) : ?>
                                <br><small><?php echo esc_html(ucfirst($sanction['organization_type'])); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($applicant) : ?>
                    <tr>
                        <th><?php esc_html_e('Submitted By', 'pausatf-results'); ?></th>
                        <td>
                            <?php echo esc_html($applicant->display_name); ?>
                            (<a href="mailto:<?php echo esc_attr($applicant->user_email); ?>"><?php echo esc_html($applicant->user_email); ?></a>)
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Compliance Checklist -->
            <div class="review-card">
                <h3><?php esc_html_e('Compliance Checklist', 'pausatf-results'); ?></h3>
                <ul class="compliance-checklist">
                    <li class="<?php echo $sanction['safesport_completed'] ? 'check-pass' : 'check-fail'; ?>">
                        <span class="dashicons <?php echo $sanction['safesport_completed'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                        <?php esc_html_e('SafeSport Training', 'pausatf-results'); ?>
                        <?php if ($sanction['safesport_completed'] && $sanction['safesport_completion_date']) : ?>
                            <small>(<?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['safesport_completion_date']))); ?>)</small>
                        <?php endif; ?>
                    </li>
                    <li class="<?php echo $sanction['organizer_usatf_number'] ? 'check-pass' : 'check-warn'; ?>">
                        <span class="dashicons <?php echo $sanction['organizer_usatf_number'] ? 'dashicons-yes-alt' : 'dashicons-info'; ?>"></span>
                        <?php esc_html_e('USATF Membership', 'pausatf-results'); ?>
                    </li>
                    <li class="<?php echo !$is_late ? 'check-pass' : 'check-warn'; ?>">
                        <span class="dashicons <?php echo !$is_late ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                        <?php esc_html_e('Submitted 30+ days before event', 'pausatf-results'); ?>
                    </li>
                    <li class="<?php echo $sanction['course_certified'] ? 'check-pass' : 'check-info'; ?>">
                        <span class="dashicons <?php echo $sanction['course_certified'] ? 'dashicons-yes-alt' : 'dashicons-info'; ?>"></span>
                        <?php esc_html_e('Course Certification', 'pausatf-results'); ?>
                        <?php if ($sanction['course_certified'] && $sanction['course_certification_number']) : ?>
                            <small>(<?php echo esc_html($sanction['course_certification_number']); ?>)</small>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <!-- Prize Money / Elite Athletes -->
            <?php if ($sanction['has_elite_athletes'] || $sanction['prize_money_total'] > 0) : ?>
            <div class="review-card">
                <h3><?php esc_html_e('Elite Competition', 'pausatf-results'); ?></h3>
                <table class="review-table">
                    <?php if ($sanction['has_elite_athletes']) : ?>
                    <tr>
                        <th><?php esc_html_e('Elite Athletes', 'pausatf-results'); ?></th>
                        <td>
                            <span class="dashicons dashicons-yes"></span>
                            <?php esc_html_e('Yes, event includes elite athletes', 'pausatf-results'); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($sanction['prize_money_total'] > 0) : ?>
                    <tr>
                        <th><?php esc_html_e('Prize Money', 'pausatf-results'); ?></th>
                        <td>
                            $<?php echo number_format($sanction['prize_money_total'], 2); ?>
                            <?php if ($sanction['prize_money_total'] > 500) : ?>
                                <span class="elite-fee-note"><?php esc_html_e('(Elite event fee applies)', 'pausatf-results'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- History -->
            <?php if (!empty($history)) : ?>
            <div class="review-card">
                <h3><?php esc_html_e('Application History', 'pausatf-results'); ?></h3>
                <ul class="history-timeline">
                    <?php foreach ($history as $entry) : ?>
                        <li>
                            <span class="history-action"><?php echo esc_html(ucfirst(str_replace('_', ' ', $entry['action']))); ?></span>
                            <?php if ($entry['user_name']) : ?>
                                <span class="history-user"><?php esc_html_e('by', 'pausatf-results'); ?> <?php echo esc_html($entry['user_name']); ?></span>
                            <?php endif; ?>
                            <span class="history-date"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['changed_at']))); ?></span>
                            <?php if ($entry['new_value'] && $entry['action'] === 'status_change') : ?>
                                <span class="history-detail">&rarr; <?php echo esc_html(ucfirst($entry['new_value'])); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

        </div>

        <!-- Sidebar -->
        <div class="review-sidebar">

            <!-- Fee Summary -->
            <div class="review-card">
                <h3><?php esc_html_e('Fee Summary', 'pausatf-results'); ?></h3>
                <div class="fee-summary">
                    <div class="fee-row">
                        <span><?php esc_html_e('National Fee:', 'pausatf-results'); ?></span>
                        <span>$<?php echo esc_html($fee_estimate['national_fee']); ?></span>
                    </div>
                    <div class="fee-row">
                        <span><?php esc_html_e('Local (PA) Fee:', 'pausatf-results'); ?></span>
                        <span>$<?php echo esc_html($fee_estimate['local_fee']); ?></span>
                    </div>
                    <?php if ($fee_estimate['is_late']) : ?>
                    <div class="fee-row fee-late">
                        <span><?php esc_html_e('Late Fee:', 'pausatf-results'); ?></span>
                        <span>$<?php echo esc_html($fee_estimate['late_fee']); ?></span>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="fee-row fee-total">
                        <span><?php esc_html_e('Total:', 'pausatf-results'); ?></span>
                        <span>$<?php echo esc_html($fee_estimate['total']); ?></span>
                    </div>
                    <p class="fee-tier"><?php echo esc_html($fee_estimate['tier_label']); ?></p>
                </div>

                <?php if ($sanction['fee_paid']) : ?>
                    <p class="payment-status payment-complete">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Fee Paid', 'pausatf-results'); ?>
                        <?php if ($sanction['payment_date']) : ?>
                            <br><small><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['payment_date']))); ?></small>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="payment-status payment-pending">
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e('Fee Not Paid', 'pausatf-results'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Review Actions -->
            <?php if (in_array($sanction['local_status'], ['submitted', 'under_review'])) : ?>
            <div class="review-card review-actions">
                <h3><?php esc_html_e('Review Decision', 'pausatf-results'); ?></h3>

                <form method="post" action="" id="review-form">
                    <?php wp_nonce_field('pausatf_review_sanction', 'review_nonce'); ?>
                    <input type="hidden" name="sanction_id" value="<?php echo esc_attr((string) $sanction_id); ?>">

                    <div class="review-notes-field">
                        <label for="review_notes"><?php esc_html_e('Review Notes', 'pausatf-results'); ?></label>
                        <textarea name="review_notes" id="review_notes" rows="4"
                                  placeholder="<?php esc_attr_e('Optional notes about this application...', 'pausatf-results'); ?>"><?php echo esc_textarea($sanction['review_notes']); ?></textarea>
                    </div>

                    <?php if ($sanction['local_status'] === 'submitted') : ?>
                        <p>
                            <button type="submit" name="action_mark_review" class="button" style="width: 100%;">
                                <?php esc_html_e('Mark as Under Review', 'pausatf-results'); ?>
                            </button>
                        </p>
                    <?php endif; ?>

                    <div class="approve-section">
                        <h4><?php esc_html_e('Approve Application', 'pausatf-results'); ?></h4>
                        <div class="sanction-number-field">
                            <label for="usatf_sanction_number"><?php esc_html_e('USATF Sanction Number', 'pausatf-results'); ?></label>
                            <input type="text" name="usatf_sanction_number" id="usatf_sanction_number"
                                   value="<?php echo esc_attr($sanction['usatf_sanction_number']); ?>"
                                   placeholder="<?php esc_attr_e('e.g., 24-1234-PA', 'pausatf-results'); ?>">
                            <p class="description"><?php esc_html_e('Enter sanction number from national approval (optional).', 'pausatf-results'); ?></p>
                        </div>
                        <p>
                            <button type="submit" name="action_approve" class="button approve-btn" style="width: 100%;">
                                <?php esc_html_e('Approve Application', 'pausatf-results'); ?>
                            </button>
                        </p>
                    </div>

                    <div class="reject-section">
                        <h4><?php esc_html_e('Reject Application', 'pausatf-results'); ?></h4>
                        <div class="rejection-reason-field">
                            <label for="rejection_reason"><?php esc_html_e('Rejection Reason', 'pausatf-results'); ?> <span class="required">*</span></label>
                            <textarea name="rejection_reason" id="rejection_reason" rows="3"
                                      placeholder="<?php esc_attr_e('Required: Explain why the application is being rejected...', 'pausatf-results'); ?>"><?php echo esc_textarea($sanction['rejection_reason']); ?></textarea>
                        </div>
                        <p>
                            <button type="submit" name="action_reject" class="button reject-btn" style="width: 100%;">
                                <?php esc_html_e('Reject Application', 'pausatf-results'); ?>
                            </button>
                        </p>
                    </div>
                </form>
            </div>
            <?php else : ?>
            <div class="review-card">
                <h3><?php esc_html_e('Application Status', 'pausatf-results'); ?></h3>
                <p class="final-status final-status-<?php echo esc_attr($sanction['local_status']); ?>">
                    <?php echo esc_html($status_labels[$sanction['local_status']] ?? $sanction['local_status']); ?>
                </p>
                <?php if ($sanction['local_status'] === 'approved' && $sanction['usatf_sanction_number']) : ?>
                    <p class="sanction-number">
                        <?php esc_html_e('Sanction #:', 'pausatf-results'); ?>
                        <code><?php echo esc_html($sanction['usatf_sanction_number']); ?></code>
                    </p>
                <?php endif; ?>
                <?php if ($sanction['local_status'] === 'rejected' && $sanction['rejection_reason']) : ?>
                    <div class="rejection-reason">
                        <strong><?php esc_html_e('Rejection Reason:', 'pausatf-results'); ?></strong>
                        <p><?php echo esc_html($sanction['rejection_reason']); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($sanction['reviewer_id']) : ?>
                    <?php $reviewer = get_userdata($sanction['reviewer_id']); ?>
                    <?php if ($reviewer) : ?>
                        <p class="reviewer-info">
                            <?php esc_html_e('Reviewed by:', 'pausatf-results'); ?>
                            <?php echo esc_html($reviewer->display_name); ?>
                            <?php if ($sanction['reviewed_at']) : ?>
                                <br><small><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['reviewed_at']))); ?></small>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="review-card">
                <h3><?php esc_html_e('Quick Links', 'pausatf-results'); ?></h3>
                <ul class="quick-links">
                    <li>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pausatf-sanctions&action=edit&id=' . $sanction_id)); ?>">
                            <?php esc_html_e('Edit Application', 'pausatf-results'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="mailto:<?php echo esc_attr($sanction['organizer_email']); ?>">
                            <?php esc_html_e('Email Organizer', 'pausatf-results'); ?>
                        </a>
                    </li>
                    <?php if ($sanction['course_certification_number']) : ?>
                    <li>
                        <a href="https://certifiedroadraces.com/certificate/?id=<?php echo esc_attr($sanction['course_certification_number']); ?>" target="_blank">
                            <?php esc_html_e('Verify Course Certification', 'pausatf-results'); ?>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>
    </div>
</div>

<style>
.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 15px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}
.review-status {
    display: flex;
    align-items: center;
    gap: 15px;
}
.sanction-status {
    display: inline-block;
    padding: 6px 14px;
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

.late-warning {
    color: #d63638;
    display: flex;
    align-items: center;
    gap: 5px;
}
.past-event { color: #d63638; }

.review-columns {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 20px;
}
.review-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px 20px;
    margin-bottom: 15px;
}
.review-card h2 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #c3c4c7;
}
.review-card h3 {
    margin: 0 0 15px;
    font-size: 14px;
}

.event-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
.summary-item {
    display: flex;
    gap: 12px;
}
.summary-item .dashicons {
    color: #2271b1;
    font-size: 24px;
    width: 24px;
    height: 24px;
}
.summary-item strong {
    display: block;
    margin-bottom: 3px;
}

.review-table {
    width: 100%;
    border-collapse: collapse;
}
.review-table th,
.review-table td {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f1;
    text-align: left;
    vertical-align: top;
}
.review-table th {
    width: 35%;
    color: #646970;
    font-weight: normal;
}

.compliance-checklist {
    list-style: none;
    padding: 0;
    margin: 0;
}
.compliance-checklist li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f1;
}
.compliance-checklist li:last-child { border-bottom: none; }
.check-pass .dashicons { color: #00a32a; }
.check-warn .dashicons { color: #dba617; }
.check-fail .dashicons { color: #d63638; }
.check-info .dashicons { color: #72aee6; }

.elite-fee-note {
    color: #d63638;
    font-size: 12px;
}

.history-timeline {
    list-style: none;
    padding: 0;
    margin: 0;
}
.history-timeline li {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
}
.history-timeline li:last-child { border-bottom: none; }
.history-action { font-weight: 500; }
.history-user { color: #646970; }
.history-date { color: #a7aaad; display: block; margin-top: 3px; }
.history-detail { color: #2271b1; }

.fee-summary .fee-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
}
.fee-total { font-weight: bold; font-size: 16px; }
.fee-late { color: #d63638; }
.fee-tier {
    text-align: center;
    color: #646970;
    font-size: 12px;
    margin-top: 10px;
}

.payment-status {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f1;
}
.payment-complete { color: #00a32a; }
.payment-pending { color: #dba617; }

.review-actions h4 {
    margin: 20px 0 10px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f1;
}
.review-notes-field label,
.sanction-number-field label,
.rejection-reason-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}
.review-notes-field textarea,
.rejection-reason-field textarea,
.sanction-number-field input {
    width: 100%;
}
.sanction-number-field,
.rejection-reason-field {
    margin-bottom: 15px;
}
.sanction-number-field .description {
    font-size: 11px;
    margin-top: 5px;
}

.approve-btn {
    background: #00a32a !important;
    border-color: #00a32a !important;
    color: #fff !important;
}
.approve-btn:hover { background: #008a20 !important; }
.reject-btn {
    background: #d63638 !important;
    border-color: #d63638 !important;
    color: #fff !important;
}
.reject-btn:hover { background: #b32d2e !important; }

.final-status {
    font-size: 18px;
    font-weight: 500;
    text-align: center;
    padding: 15px;
    border-radius: 4px;
}
.final-status-approved { background: #d1e7dd; color: #0a3622; }
.final-status-rejected { background: #f8d7da; color: #58151c; }

.rejection-reason {
    margin-top: 15px;
    padding: 15px;
    background: #f8d7da;
    border-radius: 4px;
}
.rejection-reason p { margin: 5px 0 0; }

.reviewer-info {
    color: #646970;
    margin-top: 15px;
}

.quick-links {
    list-style: none;
    padding: 0;
    margin: 0;
}
.quick-links li {
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f1;
}
.quick-links li:last-child { border-bottom: none; }

.required { color: #d63638; }

@media screen and (max-width: 960px) {
    .review-columns { grid-template-columns: 1fr; }
    .event-summary-grid { grid-template-columns: 1fr; }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Validate rejection reason before rejecting
    $('button[name="action_reject"]').on('click', function(e) {
        var reason = $('#rejection_reason').val().trim();
        if (!reason) {
            e.preventDefault();
            alert('<?php echo esc_js(__('Please provide a rejection reason.', 'pausatf-results')); ?>');
            $('#rejection_reason').focus();
        }
    });
});
</script>
