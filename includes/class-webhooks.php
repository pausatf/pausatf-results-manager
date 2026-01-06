<?php
/**
 * Webhook System
 *
 * Notifies external systems about events
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Webhook management and delivery
 */
class Webhooks {
    /**
     * Webhook events
     */
    public const EVENTS = [
        'result.created' => 'When new results are imported',
        'result.updated' => 'When results are modified',
        'event.created' => 'When a new event is created',
        'event.updated' => 'When an event is modified',
        'record.created' => 'When a new record is set',
        'record.approved' => 'When a record is verified',
        'athlete.claimed' => 'When an athlete profile is claimed',
        'ranking.updated' => 'When rankings are recalculated',
    ];

    /**
     * Webhooks table
     */
    private string $table;

    /**
     * Delivery log table
     */
    private string $log_table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'pausatf_webhooks';
        $this->log_table = $wpdb->prefix . 'pausatf_webhook_logs';

        // Register hooks for events
        add_action('pausatf_results_imported', [$this, 'on_results_imported'], 10, 2);
        add_action('save_post_pausatf_event', [$this, 'on_event_saved'], 10, 3);
        add_action('pausatf_new_record', [$this, 'on_new_record'], 10, 2);
        add_action('pausatf_athlete_claimed', [$this, 'on_athlete_claimed'], 10, 2);
        add_action('pausatf_rankings_updated', [$this, 'on_rankings_updated'], 10, 2);

        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    /**
     * Create webhook tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $webhooks_table = $wpdb->prefix . 'pausatf_webhooks';
        $sql_webhooks = "CREATE TABLE {$webhooks_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            url varchar(500) NOT NULL,
            secret varchar(255) DEFAULT NULL,
            events text NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        $logs_table = $wpdb->prefix . 'pausatf_webhook_logs';
        $sql_logs = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            webhook_id bigint(20) unsigned NOT NULL,
            event varchar(50) NOT NULL,
            payload text NOT NULL,
            response_code int DEFAULT NULL,
            response_body text DEFAULT NULL,
            duration_ms int DEFAULT NULL,
            status enum('pending','success','failed') DEFAULT 'pending',
            attempts int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            delivered_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY webhook_id (webhook_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_webhooks);
        dbDelta($sql_logs);
    }

    /**
     * Register a webhook
     *
     * @param array $data Webhook configuration
     * @return int|false Webhook ID or false
     */
    public function register_webhook(array $data): int|false {
        global $wpdb;

        if (empty($data['url']) || empty($data['events'])) {
            return false;
        }

        // Validate URL
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            return false;
        }

        // Validate events
        $events = is_array($data['events']) ? $data['events'] : [$data['events']];
        $valid_events = array_intersect($events, array_keys(self::EVENTS));

        if (empty($valid_events)) {
            return false;
        }

        // Generate secret if not provided
        $secret = $data['secret'] ?? wp_generate_password(32, false);

        $result = $wpdb->insert($this->table, [
            'name' => sanitize_text_field($data['name'] ?? 'Webhook'),
            'url' => esc_url_raw($data['url']),
            'secret' => $secret,
            'events' => json_encode($valid_events),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_by' => get_current_user_id(),
        ]);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update webhook
     */
    public function update_webhook(int $webhook_id, array $data): bool {
        global $wpdb;

        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }

        if (isset($data['url'])) {
            if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
                return false;
            }
            $update_data['url'] = esc_url_raw($data['url']);
        }

        if (isset($data['events'])) {
            $events = is_array($data['events']) ? $data['events'] : [$data['events']];
            $valid_events = array_intersect($events, array_keys(self::EVENTS));
            $update_data['events'] = json_encode($valid_events);
        }

        if (isset($data['is_active'])) {
            $update_data['is_active'] = (int) $data['is_active'];
        }

        if (empty($update_data)) {
            return false;
        }

        return (bool) $wpdb->update($this->table, $update_data, ['id' => $webhook_id]);
    }

    /**
     * Delete webhook
     */
    public function delete_webhook(int $webhook_id): bool {
        global $wpdb;

        // Delete logs first
        $wpdb->delete($this->log_table, ['webhook_id' => $webhook_id]);

        return (bool) $wpdb->delete($this->table, ['id' => $webhook_id]);
    }

    /**
     * Get all webhooks
     */
    public function get_webhooks(bool $active_only = false): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table}";

        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY name";

        $webhooks = $wpdb->get_results($sql, ARRAY_A);

        foreach ($webhooks as &$webhook) {
            $webhook['events'] = json_decode($webhook['events'], true);
        }

        return $webhooks;
    }

    /**
     * Get webhook by ID
     */
    public function get_webhook(int $id): ?array {
        global $wpdb;

        $webhook = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);

        if ($webhook) {
            $webhook['events'] = json_decode($webhook['events'], true);
        }

        return $webhook;
    }

    /**
     * Trigger webhook event
     *
     * @param string $event Event name
     * @param array $payload Event data
     */
    public function trigger(string $event, array $payload): void {
        // Get all active webhooks subscribed to this event
        $webhooks = $this->get_webhooks_for_event($event);

        foreach ($webhooks as $webhook) {
            $this->queue_delivery($webhook, $event, $payload);
        }
    }

    /**
     * Get webhooks for event
     */
    private function get_webhooks_for_event(string $event): array {
        global $wpdb;

        $webhooks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE is_active = 1 AND events LIKE %s",
            '%"' . $event . '"%'
        ), ARRAY_A);

        foreach ($webhooks as &$webhook) {
            $webhook['events'] = json_decode($webhook['events'], true);
        }

        return $webhooks;
    }

    /**
     * Queue webhook delivery
     */
    private function queue_delivery(array $webhook, string $event, array $payload): int {
        global $wpdb;

        $full_payload = [
            'event' => $event,
            'timestamp' => current_time('c'),
            'data' => $payload,
        ];

        $wpdb->insert($this->log_table, [
            'webhook_id' => $webhook['id'],
            'event' => $event,
            'payload' => json_encode($full_payload),
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $log_id = $wpdb->insert_id;

        // Schedule immediate delivery
        wp_schedule_single_event(time(), 'pausatf_deliver_webhook', [$log_id]);

        return $log_id;
    }

    /**
     * Deliver webhook
     */
    public function deliver(int $log_id): bool {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, w.url, w.secret FROM {$this->log_table} l
             INNER JOIN {$this->table} w ON l.webhook_id = w.id
             WHERE l.id = %d",
            $log_id
        ), ARRAY_A);

        if (!$log) {
            return false;
        }

        // Update attempts
        $wpdb->update($this->log_table, ['attempts' => $log['attempts'] + 1], ['id' => $log_id]);

        // Prepare request
        $payload = $log['payload'];
        $signature = hash_hmac('sha256', $payload, $log['secret']);

        $start_time = microtime(true);

        $response = wp_remote_post($log['url'], [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PAUSATF-Event' => $log['event'],
                'X-PAUSATF-Signature' => 'sha256=' . $signature,
                'X-PAUSATF-Delivery' => $log_id,
            ],
            'body' => $payload,
        ]);

        $duration = (int) ((microtime(true) - $start_time) * 1000);

        // Process response
        if (is_wp_error($response)) {
            $wpdb->update($this->log_table, [
                'status' => 'failed',
                'response_body' => $response->get_error_message(),
                'duration_ms' => $duration,
            ], ['id' => $log_id]);

            // Retry logic
            $this->maybe_retry($log_id, $log['attempts'] + 1);

            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $success = $response_code >= 200 && $response_code < 300;

        $wpdb->update($this->log_table, [
            'status' => $success ? 'success' : 'failed',
            'response_code' => $response_code,
            'response_body' => substr($response_body, 0, 1000),
            'duration_ms' => $duration,
            'delivered_at' => $success ? current_time('mysql') : null,
        ], ['id' => $log_id]);

        if (!$success) {
            $this->maybe_retry($log_id, $log['attempts'] + 1);
        }

        return $success;
    }

    /**
     * Maybe schedule retry
     */
    private function maybe_retry(int $log_id, int $attempts): void {
        $max_attempts = 5;
        $retry_delays = [60, 300, 1800, 7200, 21600]; // 1m, 5m, 30m, 2h, 6h

        if ($attempts >= $max_attempts) {
            return;
        }

        $delay = $retry_delays[$attempts - 1] ?? 21600;
        wp_schedule_single_event(time() + $delay, 'pausatf_deliver_webhook', [$log_id]);
    }

    /**
     * Event handlers
     */
    public function on_results_imported(int $event_id, int $count): void {
        $event = get_post($event_id);

        $this->trigger('result.created', [
            'event_id' => $event_id,
            'event_name' => $event ? $event->post_title : '',
            'results_count' => $count,
            'event_date' => get_post_meta($event_id, '_pausatf_event_date', true),
        ]);
    }

    public function on_event_saved(int $post_id, \WP_Post $post, bool $update): void {
        if ($post->post_status !== 'publish') {
            return;
        }

        $event = $update ? 'event.updated' : 'event.created';

        $this->trigger($event, [
            'event_id' => $post_id,
            'event_name' => $post->post_title,
            'event_date' => get_post_meta($post_id, '_pausatf_event_date', true),
            'event_location' => get_post_meta($post_id, '_pausatf_event_location', true),
        ]);
    }

    public function on_new_record(int $record_id, array $record): void {
        $this->trigger('record.created', [
            'record_id' => $record_id,
            'event' => $record['event'],
            'division' => $record['division_code'],
            'performance' => $record['performance_display'],
            'athlete_name' => $record['athlete_name'],
            'record_date' => $record['record_date'],
        ]);
    }

    public function on_athlete_claimed(int $athlete_id, int $user_id): void {
        $athlete = get_post($athlete_id);

        $this->trigger('athlete.claimed', [
            'athlete_id' => $athlete_id,
            'athlete_name' => $athlete ? $athlete->post_title : '',
            'user_id' => $user_id,
        ]);
    }

    public function on_rankings_updated(string $event, int $count): void {
        $this->trigger('ranking.updated', [
            'event' => $event,
            'athletes_ranked' => $count,
        ]);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'pausatf-results',
            'Webhooks',
            'Webhooks',
            'manage_options',
            'pausatf-webhooks',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page(): void {
        $webhooks = $this->get_webhooks();

        ?>
        <div class="wrap">
            <h1>Webhooks</h1>

            <h2>Registered Webhooks</h2>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>URL</th>
                        <th>Events</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($webhooks as $webhook): ?>
                        <tr>
                            <td><?php echo esc_html($webhook['name']); ?></td>
                            <td><code><?php echo esc_html($webhook['url']); ?></code></td>
                            <td><?php echo esc_html(implode(', ', $webhook['events'])); ?></td>
                            <td>
                                <?php if ($webhook['is_active']): ?>
                                    <span class="status-active">Active</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="#" class="edit-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>">Edit</a> |
                                <a href="#" class="delete-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>">Delete</a> |
                                <a href="#" class="test-webhook" data-id="<?php echo esc_attr($webhook['id']); ?>">Test</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($webhooks)): ?>
                        <tr>
                            <td colspan="5">No webhooks registered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2>Add New Webhook</h2>

            <form method="post" action="">
                <?php wp_nonce_field('pausatf_add_webhook'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="webhook-name">Name</label></th>
                        <td><input type="text" id="webhook-name" name="name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="webhook-url">URL</label></th>
                        <td><input type="url" id="webhook-url" name="url" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th>Events</th>
                        <td>
                            <?php foreach (self::EVENTS as $event => $description): ?>
                                <label>
                                    <input type="checkbox" name="events[]" value="<?php echo esc_attr($event); ?>">
                                    <code><?php echo esc_html($event); ?></code> - <?php echo esc_html($description); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="Add Webhook">
                </p>
            </form>

            <h2>Available Events</h2>

            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (self::EVENTS as $event => $description): ?>
                        <tr>
                            <td><code><?php echo esc_html($event); ?></code></td>
                            <td><?php echo esc_html($description); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Get delivery logs
     */
    public function get_logs(int $webhook_id = 0, int $limit = 50): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->log_table}";

        if ($webhook_id) {
            $sql .= $wpdb->prepare(" WHERE webhook_id = %d", $webhook_id);
        }

        $sql .= " ORDER BY created_at DESC LIMIT " . (int) $limit;

        return $wpdb->get_results($sql, ARRAY_A);
    }
}

// Initialize
new Webhooks();

// Register cron handler
add_action('pausatf_deliver_webhook', function ($log_id) {
    $webhooks = new Webhooks();
    $webhooks->deliver($log_id);
});
