<?php
/**
 * Admin Dashboard View
 *
 * @package PAUSATF\Results
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Get stats
$events_count = wp_count_posts('pausatf_event')->publish ?? 0;
$athletes_count = wp_count_posts('pausatf_athlete')->publish ?? 0;
$results_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pausatf_results");
$recent_imports = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}pausatf_imports ORDER BY created_at DESC LIMIT 10",
    ARRAY_A
);
?>

<div class="wrap">
    <h1><?php esc_html_e('PAUSATF Results Manager', 'pausatf-results'); ?></h1>

    <div class="pausatf-dashboard">
        <!-- Stats Cards -->
        <div class="pausatf-stats-grid">
            <div class="pausatf-stat-card">
                <span class="dashicons dashicons-calendar-alt"></span>
                <div class="pausatf-stat-content">
                    <strong><?php echo number_format($events_count); ?></strong>
                    <span><?php esc_html_e('Events', 'pausatf-results'); ?></span>
                </div>
            </div>

            <div class="pausatf-stat-card">
                <span class="dashicons dashicons-groups"></span>
                <div class="pausatf-stat-content">
                    <strong><?php echo number_format($athletes_count); ?></strong>
                    <span><?php esc_html_e('Athletes', 'pausatf-results'); ?></span>
                </div>
            </div>

            <div class="pausatf-stat-card">
                <span class="dashicons dashicons-chart-line"></span>
                <div class="pausatf-stat-content">
                    <strong><?php echo number_format($results_count); ?></strong>
                    <span><?php esc_html_e('Individual Results', 'pausatf-results'); ?></span>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Quick Actions', 'pausatf-results'); ?></h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=pausatf-results-import'); ?>" class="button button-primary">
                    <?php esc_html_e('Import Results', 'pausatf-results'); ?>
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=pausatf_event'); ?>" class="button">
                    <?php esc_html_e('View Events', 'pausatf-results'); ?>
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=pausatf_athlete'); ?>" class="button">
                    <?php esc_html_e('View Athletes', 'pausatf-results'); ?>
                </a>
            </p>
        </div>

        <!-- Recent Imports -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Recent Imports', 'pausatf-results'); ?></h2>

            <?php if (empty($recent_imports)) : ?>
                <p><?php esc_html_e('No imports yet. Use the Import page to add results.', 'pausatf-results'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Status', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Records', 'pausatf-results'); ?></th>
                            <th><?php esc_html_e('Date', 'pausatf-results'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_imports as $import) : ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html(basename($import['source_url'])); ?></code>
                                </td>
                                <td>
                                    <span class="pausatf-status pausatf-status-<?php echo esc_attr($import['status']); ?>">
                                        <?php echo esc_html(ucfirst($import['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($import['records_imported']); ?></td>
                                <td><?php echo esc_html($import['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Data Source Info -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Legacy Data Source', 'pausatf-results'); ?></h2>
            <p>
                <?php esc_html_e('Import results from:', 'pausatf-results'); ?>
                <a href="https://www.pausatf.org/data/" target="_blank">
                    https://www.pausatf.org/data/
                </a>
            </p>
            <p class="description">
                <?php esc_html_e('Available years: 1994-2025. Formats include HTML tables, PRE-formatted text, and Microsoft Word HTML.', 'pausatf-results'); ?>
            </p>
        </div>
    </div>
</div>

<style>
.pausatf-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}
.pausatf-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}
.pausatf-stat-card .dashicons {
    font-size: 40px;
    width: 40px;
    height: 40px;
    color: #2271b1;
}
.pausatf-stat-content strong {
    display: block;
    font-size: 28px;
    line-height: 1.2;
}
.pausatf-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}
.pausatf-section h2 {
    margin-top: 0;
}
.pausatf-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
}
.pausatf-status-completed { background: #d1e7dd; color: #0a3622; }
.pausatf-status-processing { background: #fff3cd; color: #664d03; }
.pausatf-status-failed { background: #f8d7da; color: #58151c; }
.pausatf-status-pending { background: #e2e3e5; color: #41464b; }
</style>
