<?php
/**
 * Race Director Portal
 *
 * Self-service upload and management for race directors
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Race Director Portal functionality
 */
class RaceDirectorPortal {
    /**
     * Race director role capability
     */
    private const CAPABILITY = 'pausatf_race_director';

    public function __construct() {
        add_action('init', [$this, 'register_capabilities']);
        add_shortcode('pausatf_rd_portal', [$this, 'render_portal']);

        add_action('wp_ajax_pausatf_rd_upload', [$this, 'ajax_upload_results']);
        add_action('wp_ajax_pausatf_rd_create_event', [$this, 'ajax_create_event']);
        add_action('wp_ajax_pausatf_rd_update_event', [$this, 'ajax_update_event']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    /**
     * Register race director capabilities
     */
    public function register_capabilities(): void {
        // Add capability to administrators
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAPABILITY)) {
            $admin->add_cap(self::CAPABILITY);
        }

        // Create race director role if not exists
        if (!get_role('race_director')) {
            add_role('race_director', 'Race Director', [
                'read' => true,
                'upload_files' => true,
                self::CAPABILITY => true,
            ]);
        }
    }

    /**
     * Check if user is a race director
     */
    public function is_race_director(int $user_id = 0): bool {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        return user_can($user_id, self::CAPABILITY);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        add_submenu_page(
            'pausatf-results',
            'Race Director Portal',
            'RD Portal',
            self::CAPABILITY,
            'pausatf-rd-portal',
            [$this, 'render_admin_portal']
        );
    }

    /**
     * Render the portal shortcode
     */
    public function render_portal(array $atts = []): string {
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        if (!$this->is_race_director()) {
            return $this->render_access_denied();
        }

        wp_enqueue_style('pausatf-rd-portal', PAUSATF_RESULTS_URL . 'assets/css/rd-portal.css', [], PAUSATF_RESULTS_VERSION);
        wp_enqueue_script('pausatf-rd-portal', PAUSATF_RESULTS_URL . 'assets/js/rd-portal.js', ['jquery'], PAUSATF_RESULTS_VERSION, true);

        wp_localize_script('pausatf-rd-portal', 'pausatfRD', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pausatf_rd_nonce'),
        ]);

        $user_id = get_current_user_id();
        $my_events = $this->get_director_events($user_id);
        $pending_uploads = $this->get_pending_uploads($user_id);

        ob_start();
        ?>
        <div class="pausatf-rd-portal">
            <header class="portal-header">
                <h1>Race Director Portal</h1>
                <p>Manage your events and upload results</p>
            </header>

            <nav class="portal-nav">
                <button class="nav-btn active" data-tab="dashboard">Dashboard</button>
                <button class="nav-btn" data-tab="upload">Upload Results</button>
                <button class="nav-btn" data-tab="events">My Events</button>
                <button class="nav-btn" data-tab="new-event">Create Event</button>
            </nav>

            <div class="portal-content">
                <!-- Dashboard Tab -->
                <div class="tab-panel active" id="tab-dashboard">
                    <div class="dashboard-grid">
                        <div class="stat-card">
                            <h3><?php echo count($my_events); ?></h3>
                            <p>Total Events</p>
                        </div>
                        <div class="stat-card">
                            <h3><?php echo $this->get_total_results($user_id); ?></h3>
                            <p>Total Results</p>
                        </div>
                        <div class="stat-card">
                            <h3><?php echo count($pending_uploads); ?></h3>
                            <p>Pending Review</p>
                        </div>
                    </div>

                    <?php if (!empty($pending_uploads)): ?>
                        <div class="pending-section">
                            <h2>Pending Uploads</h2>
                            <ul class="pending-list">
                                <?php foreach ($pending_uploads as $upload): ?>
                                    <li>
                                        <span class="event-name"><?php echo esc_html($upload['event_name']); ?></span>
                                        <span class="status <?php echo esc_attr($upload['status']); ?>">
                                            <?php echo esc_html(ucfirst($upload['status'])); ?>
                                        </span>
                                        <span class="date"><?php echo esc_html($upload['created_at']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="recent-events">
                        <h2>Recent Events</h2>
                        <?php if (empty($my_events)): ?>
                            <p>You haven't created any events yet.</p>
                        <?php else: ?>
                            <table class="events-table">
                                <thead>
                                    <tr>
                                        <th>Event Name</th>
                                        <th>Date</th>
                                        <th>Results</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($my_events, 0, 5) as $event): ?>
                                        <tr>
                                            <td><?php echo esc_html($event['title']); ?></td>
                                            <td><?php echo esc_html($event['event_date']); ?></td>
                                            <td><?php echo esc_html($event['result_count']); ?></td>
                                            <td><?php echo esc_html($event['status']); ?></td>
                                            <td>
                                                <a href="#" class="edit-event" data-id="<?php echo esc_attr($event['id']); ?>">Edit</a>
                                                <a href="#" class="upload-results" data-id="<?php echo esc_attr($event['id']); ?>">Upload</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upload Tab -->
                <div class="tab-panel" id="tab-upload">
                    <h2>Upload Results</h2>

                    <form id="results-upload-form" class="upload-form" enctype="multipart/form-data">
                        <?php wp_nonce_field('pausatf_rd_upload', 'rd_nonce'); ?>

                        <div class="form-group">
                            <label for="upload-event">Select Event *</label>
                            <select id="upload-event" name="event_id" required>
                                <option value="">-- Select Event --</option>
                                <?php foreach ($my_events as $event): ?>
                                    <option value="<?php echo esc_attr($event['id']); ?>">
                                        <?php echo esc_html($event['title']); ?>
                                        (<?php echo esc_html($event['event_date']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="upload-file">Results File *</label>
                            <input type="file" id="upload-file" name="results_file"
                                   accept=".csv,.xlsx,.xls,.html,.htm,.hy3,.cl2,.zip" required>
                            <p class="help-text">Supported formats: CSV, Excel, HTML, Hy-Tek (HY3, CL2), ZIP</p>
                        </div>

                        <div class="form-group">
                            <label for="upload-format">File Format</label>
                            <select id="upload-format" name="format">
                                <option value="auto">Auto-detect</option>
                                <option value="csv">CSV</option>
                                <option value="excel">Excel (XLSX/XLS)</option>
                                <option value="html">HTML</option>
                                <option value="hytek">Hy-Tek</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="replace_existing" value="1">
                                Replace existing results for this event
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="upload-notes">Notes (optional)</label>
                            <textarea id="upload-notes" name="notes" rows="3"
                                      placeholder="Any notes for the review team..."></textarea>
                        </div>

                        <button type="submit" class="btn-primary">Upload Results</button>
                    </form>

                    <div id="upload-progress" class="upload-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <p class="progress-text">Uploading...</p>
                    </div>

                    <div id="upload-result" class="upload-result" style="display: none;"></div>
                </div>

                <!-- Events Tab -->
                <div class="tab-panel" id="tab-events">
                    <h2>My Events</h2>

                    <div class="events-filters">
                        <select id="events-year">
                            <option value="">All Years</option>
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <select id="events-status">
                            <option value="">All Statuses</option>
                            <option value="publish">Published</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending Review</option>
                        </select>
                    </div>

                    <table class="events-table full">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Date</th>
                                <th>Location</th>
                                <th>Type</th>
                                <th>Results</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_events as $event): ?>
                                <tr data-id="<?php echo esc_attr($event['id']); ?>">
                                    <td><?php echo esc_html($event['title']); ?></td>
                                    <td><?php echo esc_html($event['event_date']); ?></td>
                                    <td><?php echo esc_html($event['location']); ?></td>
                                    <td><?php echo esc_html($event['event_type']); ?></td>
                                    <td><?php echo esc_html($event['result_count']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo esc_attr($event['status']); ?>">
                                            <?php echo esc_html(ucfirst($event['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <button class="btn-small edit-event" data-id="<?php echo esc_attr($event['id']); ?>">
                                            Edit
                                        </button>
                                        <button class="btn-small upload-results" data-id="<?php echo esc_attr($event['id']); ?>">
                                            Upload
                                        </button>
                                        <a href="<?php echo get_permalink($event['id']); ?>" class="btn-small" target="_blank">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Create Event Tab -->
                <div class="tab-panel" id="tab-new-event">
                    <h2>Create New Event</h2>

                    <form id="create-event-form" class="event-form">
                        <?php wp_nonce_field('pausatf_rd_create', 'create_nonce'); ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="event-name">Event Name *</label>
                                <input type="text" id="event-name" name="event_name" required
                                       placeholder="e.g., Golden Gate 10K">
                            </div>

                            <div class="form-group">
                                <label for="event-date">Event Date *</label>
                                <input type="date" id="event-date" name="event_date" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="event-location">Location</label>
                                <input type="text" id="event-location" name="event_location"
                                       placeholder="City, State">
                            </div>

                            <div class="form-group">
                                <label for="event-type">Event Type</label>
                                <select id="event-type" name="event_type">
                                    <option value="road">Road Race</option>
                                    <option value="track">Track & Field</option>
                                    <option value="xc">Cross Country</option>
                                    <option value="trail">Trail/Ultra</option>
                                    <option value="racewalk">Race Walk</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="event-distance">Distance</label>
                                <input type="text" id="event-distance" name="event_distance"
                                       placeholder="e.g., 10K, Half Marathon">
                            </div>

                            <div class="form-group">
                                <label for="event-sanctioned">USATF Sanctioned?</label>
                                <select id="event-sanctioned" name="usatf_sanctioned">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="event-description">Description</label>
                            <textarea id="event-description" name="event_description" rows="4"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="event-website">Event Website</label>
                            <input type="url" id="event-website" name="event_website"
                                   placeholder="https://...">
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Create Event</button>
                            <button type="button" class="btn-secondary" id="save-draft">Save as Draft</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render login prompt
     */
    private function render_login_prompt(): string {
        return '<div class="pausatf-login-required">
            <h2>Race Director Portal</h2>
            <p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to access the Race Director Portal.</p>
        </div>';
    }

    /**
     * Render access denied
     */
    private function render_access_denied(): string {
        return '<div class="pausatf-access-denied">
            <h2>Access Denied</h2>
            <p>You must be a registered Race Director to access this portal.</p>
            <p>If you are a race director and need access, please <a href="' . home_url('/contact') . '">contact us</a>.</p>
        </div>';
    }

    /**
     * Get events for a race director
     */
    public function get_director_events(int $user_id): array {
        $events = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'author' => $user_id,
            'post_status' => ['publish', 'draft', 'pending'],
            'orderby' => 'meta_value',
            'meta_key' => '_pausatf_event_date',
            'order' => 'DESC',
        ]);

        return array_map(function ($event) {
            $types = wp_get_object_terms($event->ID, 'pausatf_event_type', ['fields' => 'names']);

            return [
                'id' => $event->ID,
                'title' => $event->post_title,
                'status' => $event->post_status,
                'event_date' => get_post_meta($event->ID, '_pausatf_event_date', true),
                'location' => get_post_meta($event->ID, '_pausatf_event_location', true),
                'event_type' => !empty($types) ? $types[0] : '',
                'result_count' => (int) get_post_meta($event->ID, '_pausatf_result_count', true),
            ];
        }, $events);
    }

    /**
     * Get total results for director's events
     */
    private function get_total_results(int $user_id): int {
        global $wpdb;

        $event_ids = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'author' => $user_id,
            'fields' => 'ids',
        ]);

        if (empty($event_ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
        $table = $wpdb->prefix . 'pausatf_results';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id IN ({$placeholders})",
            ...$event_ids
        ));
    }

    /**
     * Get pending uploads for director
     */
    private function get_pending_uploads(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_imports';

        $event_ids = get_posts([
            'post_type' => 'pausatf_event',
            'posts_per_page' => -1,
            'author' => $user_id,
            'fields' => 'ids',
        ]);

        if (empty($event_ids)) {
            return [];
        }

        // Get imports for director's events that are pending
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.post_title as event_name
             FROM {$table} i
             INNER JOIN {$wpdb->posts} p ON p.ID = (
                 SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_pausatf_import_id' AND meta_value = i.id LIMIT 1
             )
             WHERE i.status = 'pending'
             ORDER BY i.created_at DESC"
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * AJAX: Upload results
     */
    public function ajax_upload_results(): void {
        check_ajax_referer('pausatf_rd_upload', 'rd_nonce');

        if (!$this->is_race_director()) {
            wp_send_json_error('Access denied');
        }

        $event_id = (int) ($_POST['event_id'] ?? 0);

        // Verify user owns this event
        $event = get_post($event_id);
        if (!$event || $event->post_author != get_current_user_id()) {
            wp_send_json_error('Invalid event');
        }

        // Handle file upload
        if (empty($_FILES['results_file'])) {
            wp_send_json_error('No file uploaded');
        }

        $file = $_FILES['results_file'];

        // Validate file
        $allowed_types = ['text/csv', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/html', 'application/zip'];

        if (!in_array($file['type'], $allowed_types)) {
            // Check by extension as fallback
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['csv', 'xlsx', 'xls', 'html', 'htm', 'hy3', 'cl2', 'zip'];

            if (!in_array($ext, $allowed_ext)) {
                wp_send_json_error('Invalid file type');
            }
        }

        // Move to uploads
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/pausatf-uploads/' . date('Y/m/');
        wp_mkdir_p($target_dir);

        $filename = uniqid('results_') . '_' . sanitize_file_name($file['name']);
        $target_path = $target_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            wp_send_json_error('Upload failed');
        }

        // Process the file
        $format = sanitize_text_field($_POST['format'] ?? 'auto');
        $replace = !empty($_POST['replace_existing']);

        // Import results
        $importer = new ResultsImporter();
        $result = $importer->import_file($target_path, [
            'event_id' => $event_id,
            'format' => $format,
            'replace' => $replace,
        ]);

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf('Successfully imported %d results', $result['imported']),
                'imported' => $result['imported'],
                'event_id' => $event_id,
            ]);
        } else {
            wp_send_json_error($result['error'] ?? 'Import failed');
        }
    }

    /**
     * AJAX: Create event
     */
    public function ajax_create_event(): void {
        check_ajax_referer('pausatf_rd_create', 'create_nonce');

        if (!$this->is_race_director()) {
            wp_send_json_error('Access denied');
        }

        $event_name = sanitize_text_field($_POST['event_name'] ?? '');
        $event_date = sanitize_text_field($_POST['event_date'] ?? '');

        if (empty($event_name) || empty($event_date)) {
            wp_send_json_error('Event name and date are required');
        }

        $status = isset($_POST['save_draft']) ? 'draft' : 'pending';

        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $event_name,
            'post_content' => sanitize_textarea_field($_POST['event_description'] ?? ''),
            'post_status' => $status,
            'meta_input' => [
                '_pausatf_event_date' => $event_date,
                '_pausatf_event_location' => sanitize_text_field($_POST['event_location'] ?? ''),
                '_pausatf_event_distance' => sanitize_text_field($_POST['event_distance'] ?? ''),
                '_pausatf_event_website' => esc_url_raw($_POST['event_website'] ?? ''),
                '_pausatf_usatf_sanctioned' => (int) ($_POST['usatf_sanctioned'] ?? 0),
            ],
        ]);

        if (is_wp_error($event_id)) {
            wp_send_json_error($event_id->get_error_message());
        }

        // Set event type taxonomy
        $event_type = sanitize_text_field($_POST['event_type'] ?? 'road');
        $type_map = [
            'road' => 'Road Race',
            'track' => 'Track & Field',
            'xc' => 'Cross Country',
            'trail' => 'Mountain/Ultra/Trail',
            'racewalk' => 'Race Walk',
        ];

        if (isset($type_map[$event_type])) {
            wp_set_object_terms($event_id, $type_map[$event_type], 'pausatf_event_type');
        }

        // Set season
        $year = date('Y', strtotime($event_date));
        wp_set_object_terms($event_id, $year, 'pausatf_season');

        wp_send_json_success([
            'message' => 'Event created successfully',
            'event_id' => $event_id,
            'status' => $status,
        ]);
    }

    /**
     * Render admin portal
     */
    public function render_admin_portal(): void {
        echo '<div class="wrap">';
        echo '<h1>Race Director Portal</h1>';
        echo do_shortcode('[pausatf_rd_portal]');
        echo '</div>';
    }
}

// Initialize
new RaceDirectorPortal();
