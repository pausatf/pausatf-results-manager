<?php
/**
 * Sanction Notifications
 *
 * Handles email notifications for sanction status changes.
 *
 * @package PAUSATF\Results\Sanctions
 */

namespace PAUSATF\Results\Sanctions;

use PAUSATF\Results\SanctionsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanction Notifications class
 */
class SanctionNotifications {
    /**
     * Get admin email addresses for notifications
     */
    private static function get_admin_emails(): array {
        $emails = [get_option('admin_email')];

        // Get sanctions notification email if set
        $sanctions_email = get_option('pausatf_sanctions_notification_email');
        if ($sanctions_email && is_email($sanctions_email)) {
            $emails[] = $sanctions_email;
        }

        return array_unique($emails);
    }

    /**
     * Send notification when sanction is submitted
     *
     * @param int $sanction_id Sanction ID
     */
    public static function send_submitted(int $sanction_id): void {
        $sanction = SanctionsManager::instance()->get($sanction_id);
        if (!$sanction) {
            return;
        }

        $site_name = get_bloginfo('name');

        // Email to applicant
        $subject = sprintf(
            __('[%s] Sanction Application Submitted - %s', 'pausatf-results'),
            $site_name,
            $sanction['event_name']
        );

        $message = self::get_template('submitted-applicant', $sanction);
        self::send_email($sanction['organizer_email'], $subject, $message);

        // Email to admins
        $admin_subject = sprintf(
            __('[%s] New Sanction Application: %s', 'pausatf-results'),
            $site_name,
            $sanction['event_name']
        );

        $admin_message = self::get_template('submitted-admin', $sanction);
        foreach (self::get_admin_emails() as $email) {
            self::send_email($email, $admin_subject, $admin_message);
        }
    }

    /**
     * Send notification when sanction is approved
     *
     * @param int $sanction_id Sanction ID
     */
    public static function send_approved(int $sanction_id): void {
        $sanction = SanctionsManager::instance()->get($sanction_id);
        if (!$sanction) {
            return;
        }

        $site_name = get_bloginfo('name');

        $subject = sprintf(
            __('[%s] Sanction Application Approved - %s', 'pausatf-results'),
            $site_name,
            $sanction['event_name']
        );

        $message = self::get_template('approved', $sanction);
        self::send_email($sanction['organizer_email'], $subject, $message);
    }

    /**
     * Send notification when sanction is rejected
     *
     * @param int    $sanction_id Sanction ID
     * @param string $reason      Rejection reason
     */
    public static function send_rejected(int $sanction_id, string $reason): void {
        $sanction = SanctionsManager::instance()->get($sanction_id);
        if (!$sanction) {
            return;
        }

        $sanction['rejection_reason'] = $reason;
        $site_name = get_bloginfo('name');

        $subject = sprintf(
            __('[%s] Sanction Application Requires Attention - %s', 'pausatf-results'),
            $site_name,
            $sanction['event_name']
        );

        $message = self::get_template('rejected', $sanction);
        self::send_email($sanction['organizer_email'], $subject, $message);
    }

    /**
     * Send post-event reminder
     *
     * @param int $sanction_id Sanction ID
     */
    public static function send_post_event_reminder(int $sanction_id): void {
        $sanction = SanctionsManager::instance()->get($sanction_id);
        if (!$sanction) {
            return;
        }

        $site_name = get_bloginfo('name');

        $subject = sprintf(
            __('[%s] Post-Event Report Required - %s', 'pausatf-results'),
            $site_name,
            $sanction['event_name']
        );

        $message = self::get_template('post-event-reminder', $sanction);
        self::send_email($sanction['organizer_email'], $subject, $message);
    }

    /**
     * Send notification when report is submitted
     *
     * @param int    $sanction_id Sanction ID
     * @param string $report_type Report type
     */
    public static function send_report_submitted(int $sanction_id, string $report_type): void {
        $sanction = SanctionsManager::instance()->get($sanction_id);
        if (!$sanction) {
            return;
        }

        $site_name = get_bloginfo('name');
        $type_label = $report_type === 'incident' ? __('Incident Report', 'pausatf-results') : __('Post-Event Report', 'pausatf-results');

        $subject = sprintf(
            __('[%s] %s Submitted - %s', 'pausatf-results'),
            $site_name,
            $type_label,
            $sanction['event_name']
        );

        $sanction['report_type_label'] = $type_label;
        $message = self::get_template('report-submitted', $sanction);

        foreach (self::get_admin_emails() as $email) {
            self::send_email($email, $subject, $message);
        }
    }

    /**
     * Send email using WordPress mail
     *
     * @param string $to      Recipient email
     * @param string $subject Email subject
     * @param string $message Email body
     * @return bool Whether email was sent
     */
    private static function send_email(string $to, string $subject, string $message): bool {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Get email template
     *
     * @param string $template Template name
     * @param array  $sanction Sanction data
     * @return string Rendered template
     */
    private static function get_template(string $template, array $sanction): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $admin_url = admin_url('admin.php?page=pausatf-sanctions&action=view&id=' . $sanction['id']);
        $my_sanctions_url = home_url('/my-sanctions/'); // Assumes page with shortcode

        ob_start();

        switch ($template) {
            case 'submitted-applicant':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #003366; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #003366; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #003366; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php echo esc_html($site_name); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php esc_html_e('Sanction Application Submitted', 'pausatf-results'); ?></h2>
                            <p><?php esc_html_e('Thank you for submitting your sanction application. It is now under review.', 'pausatf-results'); ?></p>

                            <div class="details">
                                <h3><?php echo esc_html($sanction['event_name']); ?></h3>
                                <p><strong><?php esc_html_e('Event Date:', 'pausatf-results'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></p>
                                <p><strong><?php esc_html_e('Event Type:', 'pausatf-results'); ?></strong> <?php echo esc_html(ucfirst($sanction['event_type'])); ?></p>
                                <p><strong><?php esc_html_e('Location:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['event_city'] . ', ' . $sanction['event_state']); ?></p>
                                <p><strong><?php esc_html_e('Estimated Finishers:', 'pausatf-results'); ?></strong> <?php echo esc_html(number_format($sanction['estimated_finishers'])); ?></p>
                                <p><strong><?php esc_html_e('Total Fee:', 'pausatf-results'); ?></strong> $<?php echo esc_html(number_format($sanction['total_fee'], 2)); ?></p>
                            </div>

                            <p><?php esc_html_e('You will receive an email notification when your application has been reviewed.', 'pausatf-results'); ?></p>

                            <h3><?php esc_html_e('Next Steps:', 'pausatf-results'); ?></h3>
                            <ol>
                                <li><?php esc_html_e('Wait for approval notification', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Complete payment once approved', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Submit sanction to USATF national system', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('File post-event report after your event', 'pausatf-results'); ?></li>
                            </ol>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?> - <?php echo esc_url($site_url); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;

            case 'submitted-admin':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #003366; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #003366; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #003366; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php echo esc_html($site_name); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php esc_html_e('New Sanction Application', 'pausatf-results'); ?></h2>
                            <p><?php esc_html_e('A new sanction application has been submitted and requires review.', 'pausatf-results'); ?></p>

                            <div class="details">
                                <h3><?php echo esc_html($sanction['event_name']); ?></h3>
                                <p><strong><?php esc_html_e('Event Date:', 'pausatf-results'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></p>
                                <p><strong><?php esc_html_e('Event Type:', 'pausatf-results'); ?></strong> <?php echo esc_html(ucfirst($sanction['event_type'])); ?></p>
                                <p><strong><?php esc_html_e('Location:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['event_location']); ?></p>
                                <p><strong><?php esc_html_e('Organizer:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['organizer_name']); ?></p>
                                <p><strong><?php esc_html_e('Email:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['organizer_email']); ?></p>
                                <p><strong><?php esc_html_e('Phone:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['organizer_phone']); ?></p>
                                <p><strong><?php esc_html_e('Estimated Finishers:', 'pausatf-results'); ?></strong> <?php echo esc_html(number_format($sanction['estimated_finishers'])); ?></p>
                                <p><strong><?php esc_html_e('Total Fee:', 'pausatf-results'); ?></strong> $<?php echo esc_html(number_format($sanction['total_fee'], 2)); ?></p>
                            </div>

                            <p style="text-align: center;">
                                <a href="<?php echo esc_url($admin_url); ?>" class="button"><?php esc_html_e('Review Application', 'pausatf-results'); ?></a>
                            </p>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;

            case 'approved':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #28a745; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php esc_html_e('Application Approved!', 'pausatf-results'); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php echo esc_html($sanction['event_name']); ?></h2>
                            <p><?php esc_html_e('Congratulations! Your sanction application has been approved by PA USATF.', 'pausatf-results'); ?></p>

                            <?php if (!empty($sanction['usatf_sanction_number'])): ?>
                            <div class="details">
                                <h3><?php esc_html_e('USATF Sanction Number', 'pausatf-results'); ?></h3>
                                <p style="font-size: 24px; font-weight: bold; color: #003366;"><?php echo esc_html($sanction['usatf_sanction_number']); ?></p>
                            </div>
                            <?php endif; ?>

                            <div class="details">
                                <p><strong><?php esc_html_e('Event Date:', 'pausatf-results'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></p>
                                <p><strong><?php esc_html_e('Total Fee:', 'pausatf-results'); ?></strong> $<?php echo esc_html(number_format($sanction['total_fee'], 2)); ?></p>
                            </div>

                            <?php if (!empty($sanction['review_notes'])): ?>
                            <div class="details">
                                <h4><?php esc_html_e('Notes from Reviewer:', 'pausatf-results'); ?></h4>
                                <p><?php echo nl2br(esc_html($sanction['review_notes'])); ?></p>
                            </div>
                            <?php endif; ?>

                            <h3><?php esc_html_e('Next Steps:', 'pausatf-results'); ?></h3>
                            <ol>
                                <li><?php esc_html_e('Submit your application to the national USATF system at usatf.org', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Complete fee payment', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Promote your sanctioned event', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('File a post-event report within 7 days of your event', 'pausatf-results'); ?></li>
                            </ol>

                            <h3><?php esc_html_e('Important Reminders:', 'pausatf-results'); ?></h3>
                            <ul>
                                <li><?php esc_html_e('Maintain liability waivers for all participants (keep for 5+ years)', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Report any incidents that occur during the event', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Ensure all volunteers have completed SafeSport training', 'pausatf-results'); ?></li>
                            </ul>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?> - <?php echo esc_url($site_url); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;

            case 'rejected':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #dc3545; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #003366; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php esc_html_e('Application Requires Attention', 'pausatf-results'); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php echo esc_html($sanction['event_name']); ?></h2>
                            <p><?php esc_html_e('Your sanction application requires changes before it can be approved.', 'pausatf-results'); ?></p>

                            <div class="details">
                                <h3><?php esc_html_e('Reason:', 'pausatf-results'); ?></h3>
                                <p><?php echo nl2br(esc_html($sanction['rejection_reason'])); ?></p>
                            </div>

                            <p><?php esc_html_e('Please review the feedback and resubmit your application with the necessary corrections.', 'pausatf-results'); ?></p>

                            <p><?php esc_html_e('If you have questions, please contact us.', 'pausatf-results'); ?></p>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?> - <?php echo esc_url($site_url); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;

            case 'post-event-reminder':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #ffc107; color: #333; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #ffc107; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #003366; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php esc_html_e('Post-Event Report Required', 'pausatf-results'); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php echo esc_html($sanction['event_name']); ?></h2>
                            <p><?php esc_html_e('Your event has concluded. Please submit your post-event report.', 'pausatf-results'); ?></p>

                            <div class="details">
                                <p><strong><?php esc_html_e('Event Date:', 'pausatf-results'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></p>
                                <p><strong><?php esc_html_e('Estimated Finishers:', 'pausatf-results'); ?></strong> <?php echo esc_html(number_format($sanction['estimated_finishers'])); ?></p>
                            </div>

                            <p><?php esc_html_e('The post-event report is required to:', 'pausatf-results'); ?></p>
                            <ul>
                                <li><?php esc_html_e('Report actual finisher counts (for fee adjustments if needed)', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Document any incidents that occurred', 'pausatf-results'); ?></li>
                                <li><?php esc_html_e('Maintain good standing for future sanction applications', 'pausatf-results'); ?></li>
                            </ul>

                            <p><strong><?php esc_html_e('Note:', 'pausatf-results'); ?></strong> <?php esc_html_e('Failure to submit a post-event report may affect approval of future sanction applications.', 'pausatf-results'); ?></p>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?> - <?php echo esc_url($site_url); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;

            case 'report-submitted':
                ?>
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #003366; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #003366; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                        .button { display: inline-block; padding: 10px 20px; background: #003366; color: white; text-decoration: none; border-radius: 4px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1><?php echo esc_html($sanction['report_type_label']); ?> <?php esc_html_e('Submitted', 'pausatf-results'); ?></h1>
                        </div>
                        <div class="content">
                            <h2><?php echo esc_html($sanction['event_name']); ?></h2>
                            <p><?php printf(esc_html__('A %s has been submitted for this event.', 'pausatf-results'), strtolower($sanction['report_type_label'])); ?></p>

                            <div class="details">
                                <p><strong><?php esc_html_e('Event Date:', 'pausatf-results'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($sanction['event_date']))); ?></p>
                                <p><strong><?php esc_html_e('Organizer:', 'pausatf-results'); ?></strong> <?php echo esc_html($sanction['organizer_name']); ?></p>
                            </div>

                            <p style="text-align: center;">
                                <a href="<?php echo esc_url($admin_url); ?>" class="button"><?php esc_html_e('View Details', 'pausatf-results'); ?></a>
                            </p>
                        </div>
                        <div class="footer">
                            <p><?php echo esc_html($site_name); ?></p>
                        </div>
                    </div>
                </body>
                </html>
                <?php
                break;
        }

        return ob_get_clean();
    }
}
