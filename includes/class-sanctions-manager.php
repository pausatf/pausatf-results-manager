<?php
/**
 * Sanctions Manager
 *
 * Main orchestration class for the Event Sanctions Management system.
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanctions Manager class
 */
class SanctionsManager {
    /**
     * Singleton instance
     */
    private static ?SanctionsManager $instance = null;

    /**
     * Database table names
     */
    private string $table_sanctions;
    private string $table_coi;
    private string $table_reports;
    private string $table_history;

    /**
     * Get singleton instance
     */
    public static function instance(): SanctionsManager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;

        $this->table_sanctions = $wpdb->prefix . 'pausatf_sanctions';
        $this->table_coi = $wpdb->prefix . 'pausatf_sanction_coi';
        $this->table_reports = $wpdb->prefix . 'pausatf_sanction_reports';
        $this->table_history = $wpdb->prefix . 'pausatf_sanction_history';

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // AJAX handlers
        add_action('wp_ajax_pausatf_save_sanction', [$this, 'ajax_save_sanction']);
        add_action('wp_ajax_pausatf_delete_sanction', [$this, 'ajax_delete_sanction']);
        add_action('wp_ajax_pausatf_submit_sanction', [$this, 'ajax_submit_sanction']);
        add_action('wp_ajax_pausatf_approve_sanction', [$this, 'ajax_approve_sanction']);
        add_action('wp_ajax_pausatf_reject_sanction', [$this, 'ajax_reject_sanction']);
        add_action('wp_ajax_pausatf_save_sanction_report', [$this, 'ajax_save_report']);

        // Shortcodes
        add_shortcode('pausatf_sanction_form', [$this, 'shortcode_sanction_form']);
        add_shortcode('pausatf_my_sanctions', [$this, 'shortcode_my_sanctions']);
        add_shortcode('pausatf_sanctioned_events', [$this, 'shortcode_sanctioned_events']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Capabilities
        add_action('admin_init', [$this, 'add_capabilities']);

        // Cron for reminders
        add_action('pausatf_sanction_reminders', [$this, 'send_post_event_reminders']);
        if (!wp_next_scheduled('pausatf_sanction_reminders')) {
            wp_schedule_event(time(), 'daily', 'pausatf_sanction_reminders');
        }
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Main sanctions table
        $table_sanctions = $wpdb->prefix . 'pausatf_sanctions';
        $sql_sanctions = "CREATE TABLE {$table_sanctions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,

            usatf_sanction_number varchar(50) DEFAULT NULL,
            national_status enum('not_submitted','pending','approved','denied') DEFAULT 'not_submitted',

            event_name varchar(255) NOT NULL,
            event_date date NOT NULL,
            event_end_date date DEFAULT NULL,
            event_type enum('road','track','xc','trail','racewalk','multi') NOT NULL,
            event_distance varchar(100) DEFAULT NULL,
            event_location varchar(255) NOT NULL,
            event_city varchar(100) NOT NULL,
            event_state varchar(2) DEFAULT 'PA',
            event_zip varchar(10) DEFAULT NULL,
            event_venue varchar(255) DEFAULT NULL,
            event_website varchar(500) DEFAULT NULL,
            course_certified tinyint(1) DEFAULT 0,
            course_certification_number varchar(50) DEFAULT NULL,

            organizer_name varchar(255) NOT NULL,
            organizer_email varchar(255) NOT NULL,
            organizer_phone varchar(20) DEFAULT NULL,
            organizer_usatf_number varchar(50) DEFAULT NULL,
            organization_name varchar(255) DEFAULT NULL,
            organization_type enum('club','nonprofit','forprofit','government','school','other') DEFAULT NULL,
            safesport_completed tinyint(1) DEFAULT 0,
            safesport_completion_date date DEFAULT NULL,

            estimated_finishers int unsigned DEFAULT 0,
            estimated_volunteers int unsigned DEFAULT 0,
            has_elite_athletes tinyint(1) DEFAULT 0,
            prize_money_total decimal(10,2) DEFAULT 0,
            has_wheelchair_division tinyint(1) DEFAULT 0,

            event_description text DEFAULT NULL,
            safety_plan text DEFAULT NULL,
            medical_support text DEFAULT NULL,
            course_description text DEFAULT NULL,

            national_fee decimal(10,2) DEFAULT 0,
            local_fee decimal(10,2) DEFAULT 0,
            total_fee decimal(10,2) DEFAULT 0,
            fee_paid tinyint(1) DEFAULT 0,
            payment_date date DEFAULT NULL,
            payment_method varchar(50) DEFAULT NULL,
            payment_reference varchar(100) DEFAULT NULL,

            local_status enum('draft','submitted','under_review','approved','rejected','cancelled') DEFAULT 'draft',
            reviewer_id bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            review_notes text DEFAULT NULL,
            rejection_reason text DEFAULT NULL,

            submitted_at datetime DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            applicant_user_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,

            PRIMARY KEY (id),
            KEY event_date (event_date),
            KEY local_status (local_status),
            KEY organizer_email (organizer_email),
            KEY usatf_sanction_number (usatf_sanction_number),
            KEY applicant_user_id (applicant_user_id)
        ) {$charset_collate};";

        // Certificate of Insurance requests
        $table_coi = $wpdb->prefix . 'pausatf_sanction_coi';
        $sql_coi = "CREATE TABLE {$table_coi} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sanction_id bigint(20) unsigned NOT NULL,

            insured_name varchar(255) NOT NULL,
            insured_address text NOT NULL,
            insured_city varchar(100) DEFAULT NULL,
            insured_state varchar(2) DEFAULT NULL,
            insured_zip varchar(10) DEFAULT NULL,
            insured_type enum('venue','sponsor','government','other') DEFAULT 'other',
            relationship varchar(255) DEFAULT NULL,

            status enum('pending','issued','denied') DEFAULT 'pending',
            issued_at datetime DEFAULT NULL,
            certificate_number varchar(100) DEFAULT NULL,
            certificate_file varchar(500) DEFAULT NULL,
            notes text DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY sanction_id (sanction_id),
            KEY status (status)
        ) {$charset_collate};";

        // Reports (post-event and incident)
        $table_reports = $wpdb->prefix . 'pausatf_sanction_reports';
        $sql_reports = "CREATE TABLE {$table_reports} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sanction_id bigint(20) unsigned NOT NULL,
            report_type enum('post_event','incident') NOT NULL,

            actual_finishers int unsigned DEFAULT NULL,
            actual_volunteers int unsigned DEFAULT NULL,
            weather_conditions varchar(255) DEFAULT NULL,
            event_went_as_planned tinyint(1) DEFAULT 1,
            changes_from_plan text DEFAULT NULL,

            incident_date datetime DEFAULT NULL,
            incident_time time DEFAULT NULL,
            incident_location varchar(255) DEFAULT NULL,
            incident_description text DEFAULT NULL,
            injured_party_name varchar(255) DEFAULT NULL,
            injured_party_contact varchar(255) DEFAULT NULL,
            injury_type varchar(255) DEFAULT NULL,
            injury_severity enum('minor','moderate','serious','critical') DEFAULT NULL,
            medical_attention tinyint(1) DEFAULT 0,
            medical_facility varchar(255) DEFAULT NULL,
            witness_names text DEFAULT NULL,
            action_taken text DEFAULT NULL,

            report_notes text DEFAULT NULL,
            attachments text DEFAULT NULL,

            submitted_by bigint(20) unsigned DEFAULT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY sanction_id (sanction_id),
            KEY report_type (report_type)
        ) {$charset_collate};";

        // Audit history
        $table_history = $wpdb->prefix . 'pausatf_sanction_history';
        $sql_history = "CREATE TABLE {$table_history} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sanction_id bigint(20) unsigned NOT NULL,
            action varchar(50) NOT NULL,
            field_name varchar(100) DEFAULT NULL,
            old_value text DEFAULT NULL,
            new_value text DEFAULT NULL,
            changed_by bigint(20) unsigned DEFAULT NULL,
            changed_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(500) DEFAULT NULL,

            PRIMARY KEY (id),
            KEY sanction_id (sanction_id),
            KEY action (action),
            KEY changed_at (changed_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_sanctions);
        dbDelta($sql_coi);
        dbDelta($sql_reports);
        dbDelta($sql_history);
    }

    /**
     * Add capabilities to admin role
     */
    public function add_capabilities(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('manage_sanctions');
            $admin->add_cap('review_sanctions');
            $admin->add_cap('submit_sanctions');
            $admin->add_cap('view_own_sanctions');
        }

        // Allow editors to review
        $editor = get_role('editor');
        if ($editor) {
            $editor->add_cap('review_sanctions');
            $editor->add_cap('submit_sanctions');
            $editor->add_cap('view_own_sanctions');
        }

        // Allow subscribers to submit
        $subscriber = get_role('subscriber');
        if ($subscriber) {
            $subscriber->add_cap('submit_sanctions');
            $subscriber->add_cap('view_own_sanctions');
        }
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'pausatf-results',
            __('Event Sanctions', 'pausatf-results'),
            __('Sanctions', 'pausatf-results'),
            'review_sanctions',
            'pausatf-sanctions',
            [$this, 'render_sanctions_page']
        );
    }

    /**
     * Render sanctions admin page
     */
    public function render_sanctions_page(): void {
        $action = $_GET['action'] ?? 'list';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        switch ($action) {
            case 'new':
            case 'edit':
                include PAUSATF_RESULTS_DIR . 'admin/views/sanctions/sanction-edit.php';
                break;
            case 'view':
                include PAUSATF_RESULTS_DIR . 'admin/views/sanctions/sanction-view.php';
                break;
            case 'reports':
                include PAUSATF_RESULTS_DIR . 'admin/views/sanctions/sanction-reports.php';
                break;
            default:
                include PAUSATF_RESULTS_DIR . 'admin/views/sanctions/sanctions-list.php';
                break;
        }
    }

    /**
     * Get sanction by ID
     */
    public function get(int $id): ?array {
        global $wpdb;

        $sanction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_sanctions} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $sanction ?: null;
    }

    /**
     * Get sanctions with filters
     */
    public function get_all(array $args = []): array {
        global $wpdb;

        $defaults = [
            'status' => '',
            'event_type' => '',
            'year' => '',
            'organizer_email' => '',
            'applicant_user_id' => 0,
            'search' => '',
            'orderby' => 'event_date',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);
        $where = ['1=1'];
        $params = [];

        if ($args['status']) {
            $where[] = 'local_status = %s';
            $params[] = $args['status'];
        }

        if ($args['event_type']) {
            $where[] = 'event_type = %s';
            $params[] = $args['event_type'];
        }

        if ($args['year']) {
            $where[] = 'YEAR(event_date) = %d';
            $params[] = $args['year'];
        }

        if ($args['organizer_email']) {
            $where[] = 'organizer_email = %s';
            $params[] = $args['organizer_email'];
        }

        if ($args['applicant_user_id']) {
            $where[] = 'applicant_user_id = %d';
            $params[] = $args['applicant_user_id'];
        }

        if ($args['search']) {
            $where[] = '(event_name LIKE %s OR organizer_name LIKE %s OR organizer_email LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'event_date DESC';

        $params[] = $args['limit'];
        $params[] = $args['offset'];

        $sql = "SELECT * FROM {$this->table_sanctions} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Count sanctions with filters
     */
    public function count(array $args = []): int {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 'local_status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['applicant_user_id'])) {
            $where[] = 'applicant_user_id = %d';
            $params[] = $args['applicant_user_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$this->table_sanctions} WHERE {$where_clause}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Create a new sanction
     */
    public function create(array $data): int {
        global $wpdb;

        // Calculate fees
        $fees = Sanctions\SanctionFees::calculate($data['estimated_finishers'] ?? 0);
        $data['national_fee'] = $fees['national'];
        $data['local_fee'] = $fees['local'];
        $data['total_fee'] = $fees['total'];

        $data['created_at'] = current_time('mysql');
        $data['created_by'] = get_current_user_id();

        $wpdb->insert($this->table_sanctions, $data);
        $id = $wpdb->insert_id;

        if ($id) {
            $this->log_history($id, 'created', null, null);
        }

        return $id;
    }

    /**
     * Update a sanction
     */
    public function update(int $id, array $data): bool {
        global $wpdb;

        $old = $this->get($id);
        if (!$old) {
            return false;
        }

        // Recalculate fees if finisher estimate changed
        if (isset($data['estimated_finishers']) && $data['estimated_finishers'] != $old['estimated_finishers']) {
            $fees = Sanctions\SanctionFees::calculate($data['estimated_finishers']);
            $data['national_fee'] = $fees['national'];
            $data['local_fee'] = $fees['local'];
            $data['total_fee'] = $fees['total'];
        }

        $data['updated_at'] = current_time('mysql');

        $result = $wpdb->update($this->table_sanctions, $data, ['id' => $id]);

        if ($result !== false) {
            // Log changes
            foreach ($data as $field => $new_value) {
                if (isset($old[$field]) && $old[$field] != $new_value) {
                    $this->log_history($id, 'updated', $field, $old[$field], $new_value);
                }
            }
        }

        return $result !== false;
    }

    /**
     * Delete a sanction
     */
    public function delete(int $id): bool {
        global $wpdb;

        $this->log_history($id, 'deleted', null, null);

        // Delete related records
        $wpdb->delete($this->table_coi, ['sanction_id' => $id]);
        $wpdb->delete($this->table_reports, ['sanction_id' => $id]);

        return $wpdb->delete($this->table_sanctions, ['id' => $id]) !== false;
    }

    /**
     * Submit sanction for review
     */
    public function submit(int $id): bool {
        $sanction = $this->get($id);
        if (!$sanction || $sanction['local_status'] !== 'draft') {
            return false;
        }

        $result = $this->update($id, [
            'local_status' => 'submitted',
            'submitted_at' => current_time('mysql'),
        ]);

        if ($result) {
            $this->log_history($id, 'submitted', 'local_status', 'draft', 'submitted');
            Sanctions\SanctionNotifications::send_submitted($id);
        }

        return $result;
    }

    /**
     * Approve sanction
     */
    public function approve(int $id, string $notes = '', string $usatf_number = ''): bool {
        $sanction = $this->get($id);
        if (!$sanction || !in_array($sanction['local_status'], ['submitted', 'under_review'])) {
            return false;
        }

        $data = [
            'local_status' => 'approved',
            'approved_at' => current_time('mysql'),
            'reviewer_id' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'review_notes' => $notes,
        ];

        if ($usatf_number) {
            $data['usatf_sanction_number'] = $usatf_number;
            $data['national_status'] = 'approved';
        }

        $result = $this->update($id, $data);

        if ($result) {
            $this->log_history($id, 'approved', 'local_status', $sanction['local_status'], 'approved');
            Sanctions\SanctionNotifications::send_approved($id);
        }

        return $result;
    }

    /**
     * Reject sanction
     */
    public function reject(int $id, string $reason): bool {
        $sanction = $this->get($id);
        if (!$sanction || !in_array($sanction['local_status'], ['submitted', 'under_review'])) {
            return false;
        }

        $result = $this->update($id, [
            'local_status' => 'rejected',
            'reviewer_id' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'rejection_reason' => $reason,
        ]);

        if ($result) {
            $this->log_history($id, 'rejected', 'local_status', $sanction['local_status'], 'rejected');
            Sanctions\SanctionNotifications::send_rejected($id, $reason);
        }

        return $result;
    }

    /**
     * Log history entry
     */
    private function log_history(int $sanction_id, string $action, ?string $field, $old_value = null, $new_value = null): void {
        global $wpdb;

        $wpdb->insert($this->table_history, [
            'sanction_id' => $sanction_id,
            'action' => $action,
            'field_name' => $field,
            'old_value' => is_array($old_value) ? json_encode($old_value) : $old_value,
            'new_value' => is_array($new_value) ? json_encode($new_value) : $new_value,
            'changed_by' => get_current_user_id(),
            'changed_at' => current_time('mysql'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    /**
     * Get history for a sanction
     */
    public function get_history(int $sanction_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
             FROM {$this->table_history} h
             LEFT JOIN {$wpdb->users} u ON h.changed_by = u.ID
             WHERE h.sanction_id = %d
             ORDER BY h.changed_at DESC",
            $sanction_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Get COI requests for a sanction
     */
    public function get_coi_requests(int $sanction_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_coi} WHERE sanction_id = %d ORDER BY created_at DESC",
            $sanction_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Add COI request
     */
    public function add_coi_request(int $sanction_id, array $data): int {
        global $wpdb;

        $data['sanction_id'] = $sanction_id;
        $data['created_at'] = current_time('mysql');

        $wpdb->insert($this->table_coi, $data);

        return $wpdb->insert_id;
    }

    /**
     * Get reports for a sanction
     */
    public function get_reports(int $sanction_id, string $type = ''): array {
        global $wpdb;

        $where = 'sanction_id = %d';
        $params = [$sanction_id];

        if ($type) {
            $where .= ' AND report_type = %s';
            $params[] = $type;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_reports} WHERE {$where} ORDER BY submitted_at DESC",
            ...$params
        ), ARRAY_A) ?: [];
    }

    /**
     * Add report
     */
    public function add_report(int $sanction_id, string $type, array $data): int {
        global $wpdb;

        $data['sanction_id'] = $sanction_id;
        $data['report_type'] = $type;
        $data['submitted_by'] = get_current_user_id();
        $data['submitted_at'] = current_time('mysql');

        $wpdb->insert($this->table_reports, $data);
        $id = $wpdb->insert_id;

        if ($id) {
            $this->log_history($sanction_id, 'report_submitted', 'report_type', null, $type);
            Sanctions\SanctionNotifications::send_report_submitted($sanction_id, $type);
        }

        return $id;
    }

    /**
     * Send post-event reminder emails
     */
    public function send_post_event_reminders(): void {
        global $wpdb;

        // Find approved events that ended 3 days ago without a post-event report
        $date = date('Y-m-d', strtotime('-3 days'));

        $sanctions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$this->table_sanctions} s
             LEFT JOIN {$this->table_reports} r ON s.id = r.sanction_id AND r.report_type = 'post_event'
             WHERE s.local_status = 'approved'
             AND COALESCE(s.event_end_date, s.event_date) <= %s
             AND r.id IS NULL",
            $date
        ), ARRAY_A);

        foreach ($sanctions as $sanction) {
            Sanctions\SanctionNotifications::send_post_event_reminder($sanction['id']);
        }
    }

    /**
     * AJAX: Save sanction
     */
    public function ajax_save_sanction(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        if (!current_user_can('submit_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $data = $this->sanitize_sanction_data($_POST);

        if ($id) {
            // Check ownership or admin
            $sanction = $this->get($id);
            if (!$sanction) {
                wp_send_json_error(__('Sanction not found.', 'pausatf-results'));
            }

            if ($sanction['applicant_user_id'] != get_current_user_id() && !current_user_can('manage_sanctions')) {
                wp_send_json_error(__('Permission denied.', 'pausatf-results'));
            }

            if ($this->update($id, $data)) {
                wp_send_json_success([
                    'message' => __('Sanction updated successfully.', 'pausatf-results'),
                    'id' => $id,
                ]);
            }
        } else {
            $data['applicant_user_id'] = get_current_user_id();
            $id = $this->create($data);

            if ($id) {
                wp_send_json_success([
                    'message' => __('Sanction created successfully.', 'pausatf-results'),
                    'id' => $id,
                ]);
            }
        }

        wp_send_json_error(__('Failed to save sanction.', 'pausatf-results'));
    }

    /**
     * AJAX: Delete sanction
     */
    public function ajax_delete_sanction(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        if (!current_user_can('manage_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(__('Invalid sanction ID.', 'pausatf-results'));
        }

        if ($this->delete($id)) {
            wp_send_json_success(__('Sanction deleted.', 'pausatf-results'));
        }

        wp_send_json_error(__('Failed to delete sanction.', 'pausatf-results'));
    }

    /**
     * AJAX: Submit sanction
     */
    public function ajax_submit_sanction(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        $id = absint($_POST['id'] ?? 0);
        $sanction = $this->get($id);

        if (!$sanction) {
            wp_send_json_error(__('Sanction not found.', 'pausatf-results'));
        }

        if ($sanction['applicant_user_id'] != get_current_user_id() && !current_user_can('manage_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        if ($this->submit($id)) {
            wp_send_json_success(__('Sanction submitted for review.', 'pausatf-results'));
        }

        wp_send_json_error(__('Failed to submit sanction.', 'pausatf-results'));
    }

    /**
     * AJAX: Approve sanction
     */
    public function ajax_approve_sanction(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        if (!current_user_can('review_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        $id = absint($_POST['id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $usatf_number = sanitize_text_field($_POST['usatf_sanction_number'] ?? '');

        if ($this->approve($id, $notes, $usatf_number)) {
            wp_send_json_success(__('Sanction approved.', 'pausatf-results'));
        }

        wp_send_json_error(__('Failed to approve sanction.', 'pausatf-results'));
    }

    /**
     * AJAX: Reject sanction
     */
    public function ajax_reject_sanction(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        if (!current_user_can('review_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        $id = absint($_POST['id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$reason) {
            wp_send_json_error(__('Rejection reason is required.', 'pausatf-results'));
        }

        if ($this->reject($id, $reason)) {
            wp_send_json_success(__('Sanction rejected.', 'pausatf-results'));
        }

        wp_send_json_error(__('Failed to reject sanction.', 'pausatf-results'));
    }

    /**
     * AJAX: Save report
     */
    public function ajax_save_report(): void {
        check_ajax_referer('pausatf_sanctions', '_wpnonce');

        $sanction_id = absint($_POST['sanction_id'] ?? 0);
        $sanction = $this->get($sanction_id);

        if (!$sanction) {
            wp_send_json_error(__('Sanction not found.', 'pausatf-results'));
        }

        if ($sanction['applicant_user_id'] != get_current_user_id() && !current_user_can('manage_sanctions')) {
            wp_send_json_error(__('Permission denied.', 'pausatf-results'));
        }

        $type = sanitize_key($_POST['report_type'] ?? 'post_event');
        $data = $this->sanitize_report_data($_POST);

        $id = $this->add_report($sanction_id, $type, $data);

        if ($id) {
            wp_send_json_success([
                'message' => __('Report submitted successfully.', 'pausatf-results'),
                'id' => $id,
            ]);
        }

        wp_send_json_error(__('Failed to submit report.', 'pausatf-results'));
    }

    /**
     * Sanitize sanction data
     */
    private function sanitize_sanction_data(array $data): array {
        return [
            'event_name' => sanitize_text_field($data['event_name'] ?? ''),
            'event_date' => sanitize_text_field($data['event_date'] ?? ''),
            'event_end_date' => sanitize_text_field($data['event_end_date'] ?? '') ?: null,
            'event_type' => sanitize_key($data['event_type'] ?? 'road'),
            'event_distance' => sanitize_text_field($data['event_distance'] ?? ''),
            'event_location' => sanitize_text_field($data['event_location'] ?? ''),
            'event_city' => sanitize_text_field($data['event_city'] ?? ''),
            'event_state' => sanitize_text_field($data['event_state'] ?? 'PA'),
            'event_zip' => sanitize_text_field($data['event_zip'] ?? ''),
            'event_venue' => sanitize_text_field($data['event_venue'] ?? ''),
            'event_website' => esc_url_raw($data['event_website'] ?? ''),
            'course_certified' => !empty($data['course_certified']) ? 1 : 0,
            'course_certification_number' => sanitize_text_field($data['course_certification_number'] ?? ''),
            'organizer_name' => sanitize_text_field($data['organizer_name'] ?? ''),
            'organizer_email' => sanitize_email($data['organizer_email'] ?? ''),
            'organizer_phone' => sanitize_text_field($data['organizer_phone'] ?? ''),
            'organizer_usatf_number' => sanitize_text_field($data['organizer_usatf_number'] ?? ''),
            'organization_name' => sanitize_text_field($data['organization_name'] ?? ''),
            'organization_type' => sanitize_key($data['organization_type'] ?? ''),
            'safesport_completed' => !empty($data['safesport_completed']) ? 1 : 0,
            'safesport_completion_date' => sanitize_text_field($data['safesport_completion_date'] ?? '') ?: null,
            'estimated_finishers' => absint($data['estimated_finishers'] ?? 0),
            'estimated_volunteers' => absint($data['estimated_volunteers'] ?? 0),
            'has_elite_athletes' => !empty($data['has_elite_athletes']) ? 1 : 0,
            'prize_money_total' => floatval($data['prize_money_total'] ?? 0),
            'has_wheelchair_division' => !empty($data['has_wheelchair_division']) ? 1 : 0,
            'event_description' => sanitize_textarea_field($data['event_description'] ?? ''),
            'safety_plan' => sanitize_textarea_field($data['safety_plan'] ?? ''),
            'medical_support' => sanitize_textarea_field($data['medical_support'] ?? ''),
            'course_description' => sanitize_textarea_field($data['course_description'] ?? ''),
        ];
    }

    /**
     * Sanitize report data
     */
    private function sanitize_report_data(array $data): array {
        $sanitized = [
            'report_notes' => sanitize_textarea_field($data['report_notes'] ?? ''),
        ];

        if (isset($data['actual_finishers'])) {
            $sanitized['actual_finishers'] = absint($data['actual_finishers']);
        }
        if (isset($data['actual_volunteers'])) {
            $sanitized['actual_volunteers'] = absint($data['actual_volunteers']);
        }
        if (isset($data['weather_conditions'])) {
            $sanitized['weather_conditions'] = sanitize_text_field($data['weather_conditions']);
        }
        if (isset($data['event_went_as_planned'])) {
            $sanitized['event_went_as_planned'] = !empty($data['event_went_as_planned']) ? 1 : 0;
        }
        if (isset($data['changes_from_plan'])) {
            $sanitized['changes_from_plan'] = sanitize_textarea_field($data['changes_from_plan']);
        }

        // Incident fields
        if (isset($data['incident_date'])) {
            $sanitized['incident_date'] = sanitize_text_field($data['incident_date']);
        }
        if (isset($data['incident_description'])) {
            $sanitized['incident_description'] = sanitize_textarea_field($data['incident_description']);
        }
        if (isset($data['injured_party_name'])) {
            $sanitized['injured_party_name'] = sanitize_text_field($data['injured_party_name']);
        }
        if (isset($data['injury_type'])) {
            $sanitized['injury_type'] = sanitize_text_field($data['injury_type']);
        }
        if (isset($data['injury_severity'])) {
            $sanitized['injury_severity'] = sanitize_key($data['injury_severity']);
        }
        if (isset($data['medical_attention'])) {
            $sanitized['medical_attention'] = !empty($data['medical_attention']) ? 1 : 0;
        }
        if (isset($data['action_taken'])) {
            $sanitized['action_taken'] = sanitize_textarea_field($data['action_taken']);
        }

        return $sanitized;
    }

    /**
     * Register REST routes
     */
    public function register_rest_routes(): void {
        register_rest_route('pausatf/v1', '/sanctions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_sanctions'],
                'permission_callback' => [$this, 'rest_can_view'],
                'args' => [
                    'status' => ['type' => 'string'],
                    'event_type' => ['type' => 'string'],
                    'year' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer', 'default' => 20],
                    'page' => ['type' => 'integer', 'default' => 1],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_create_sanction'],
                'permission_callback' => [$this, 'rest_can_create'],
            ],
        ]);

        register_rest_route('pausatf/v1', '/sanctions/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_get_sanction'],
                'permission_callback' => [$this, 'rest_can_view'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'rest_update_sanction'],
                'permission_callback' => [$this, 'rest_can_edit'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'rest_delete_sanction'],
                'permission_callback' => [$this, 'rest_can_delete'],
            ],
        ]);

        register_rest_route('pausatf/v1', '/sanctions/(?P<id>\d+)/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_submit_sanction'],
            'permission_callback' => [$this, 'rest_can_edit'],
        ]);

        register_rest_route('pausatf/v1', '/sanctions/(?P<id>\d+)/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_approve_sanction'],
            'permission_callback' => [$this, 'rest_can_review'],
        ]);

        register_rest_route('pausatf/v1', '/sanctions/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_reject_sanction'],
            'permission_callback' => [$this, 'rest_can_review'],
        ]);

        register_rest_route('pausatf/v1', '/sanctions/fees', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_fees'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * REST permission callbacks
     */
    public function rest_can_view(): bool {
        return is_user_logged_in();
    }

    public function rest_can_create(): bool {
        return current_user_can('submit_sanctions');
    }

    public function rest_can_edit(\WP_REST_Request $request): bool {
        $id = $request->get_param('id');
        $sanction = $this->get($id);

        if (!$sanction) {
            return false;
        }

        return $sanction['applicant_user_id'] == get_current_user_id() || current_user_can('manage_sanctions');
    }

    public function rest_can_review(): bool {
        return current_user_can('review_sanctions');
    }

    public function rest_can_delete(): bool {
        return current_user_can('manage_sanctions');
    }

    /**
     * REST: Get sanctions
     */
    public function rest_get_sanctions(\WP_REST_Request $request): \WP_REST_Response {
        $per_page = min($request->get_param('per_page'), 100);
        $page = $request->get_param('page');

        $args = [
            'status' => $request->get_param('status'),
            'event_type' => $request->get_param('event_type'),
            'year' => $request->get_param('year'),
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ];

        // Non-admins only see their own
        if (!current_user_can('review_sanctions')) {
            $args['applicant_user_id'] = get_current_user_id();
        }

        $sanctions = $this->get_all($args);
        $total = $this->count($args);

        $response = new \WP_REST_Response($sanctions);
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));

        return $response;
    }

    /**
     * REST: Get single sanction
     */
    public function rest_get_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $sanction = $this->get($id);

        if (!$sanction) {
            return new \WP_REST_Response(['error' => 'Not found'], 404);
        }

        // Non-admins only see their own
        if (!current_user_can('review_sanctions') && $sanction['applicant_user_id'] != get_current_user_id()) {
            return new \WP_REST_Response(['error' => 'Forbidden'], 403);
        }

        $sanction['coi_requests'] = $this->get_coi_requests($id);
        $sanction['reports'] = $this->get_reports($id);
        $sanction['history'] = $this->get_history($id);

        return new \WP_REST_Response($sanction);
    }

    /**
     * REST: Create sanction
     */
    public function rest_create_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $data = $this->sanitize_sanction_data($request->get_params());
        $data['applicant_user_id'] = get_current_user_id();

        $id = $this->create($data);

        if ($id) {
            return new \WP_REST_Response($this->get($id), 201);
        }

        return new \WP_REST_Response(['error' => 'Failed to create'], 500);
    }

    /**
     * REST: Update sanction
     */
    public function rest_update_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $data = $this->sanitize_sanction_data($request->get_params());

        if ($this->update($id, $data)) {
            return new \WP_REST_Response($this->get($id));
        }

        return new \WP_REST_Response(['error' => 'Failed to update'], 500);
    }

    /**
     * REST: Delete sanction
     */
    public function rest_delete_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');

        if ($this->delete($id)) {
            return new \WP_REST_Response(null, 204);
        }

        return new \WP_REST_Response(['error' => 'Failed to delete'], 500);
    }

    /**
     * REST: Submit sanction
     */
    public function rest_submit_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');

        if ($this->submit($id)) {
            return new \WP_REST_Response($this->get($id));
        }

        return new \WP_REST_Response(['error' => 'Failed to submit'], 400);
    }

    /**
     * REST: Approve sanction
     */
    public function rest_approve_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $notes = sanitize_textarea_field($request->get_param('notes') ?? '');
        $usatf_number = sanitize_text_field($request->get_param('usatf_sanction_number') ?? '');

        if ($this->approve($id, $notes, $usatf_number)) {
            return new \WP_REST_Response($this->get($id));
        }

        return new \WP_REST_Response(['error' => 'Failed to approve'], 400);
    }

    /**
     * REST: Reject sanction
     */
    public function rest_reject_sanction(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $reason = sanitize_textarea_field($request->get_param('reason') ?? '');

        if (!$reason) {
            return new \WP_REST_Response(['error' => 'Reason required'], 400);
        }

        if ($this->reject($id, $reason)) {
            return new \WP_REST_Response($this->get($id));
        }

        return new \WP_REST_Response(['error' => 'Failed to reject'], 400);
    }

    /**
     * REST: Get fee schedule
     */
    public function rest_get_fees(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Sanctions\SanctionFees::get_fee_schedule());
    }

    /**
     * Shortcode: Sanction application form
     */
    public function shortcode_sanction_form(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(
                __('Please %s to submit a sanction application.', 'pausatf-results'),
                '<a href="' . wp_login_url(get_permalink() ?: '') . '">' . __('log in', 'pausatf-results') . '</a>'
            ) . '</p>';
        }

        ob_start();
        include PAUSATF_RESULTS_DIR . 'public/views/sanction-form.php';
        return ob_get_clean() ?: '';
    }

    /**
     * Shortcode: My sanctions
     */
    public function shortcode_my_sanctions(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(
                __('Please %s to view your sanctions.', 'pausatf-results'),
                '<a href="' . wp_login_url(get_permalink() ?: '') . '">' . __('log in', 'pausatf-results') . '</a>'
            ) . '</p>';
        }

        $sanctions = $this->get_all([
            'applicant_user_id' => get_current_user_id(),
            'orderby' => 'created_at',
            'order' => 'DESC',
        ]);

        ob_start();
        include PAUSATF_RESULTS_DIR . 'public/views/my-sanctions.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: Sanctioned events list
     */
    public function shortcode_sanctioned_events(array $atts = []): string {
        $atts = shortcode_atts([
            'year' => date('Y'),
            'type' => '',
            'limit' => 50,
        ], $atts);

        $sanctions = $this->get_all([
            'status' => 'approved',
            'year' => $atts['year'],
            'event_type' => $atts['type'],
            'orderby' => 'event_date',
            'order' => 'ASC',
            'limit' => $atts['limit'],
        ]);

        ob_start();
        include PAUSATF_RESULTS_DIR . 'public/views/sanctioned-events.php';
        return ob_get_clean();
    }
}

// Initialize when feature is enabled
add_action('init', function() {
    if (class_exists('PAUSATF\\Results\\FeatureManager') && FeatureManager::is_enabled('sanctions_manager')) {
        SanctionsManager::instance();
    }
}, 20);
