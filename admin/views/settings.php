<?php
/**
 * Admin Settings View
 *
 * @package PAUSATF\Results
 */

if (!defined('ABSPATH')) {
    exit;
}

// Save settings
if (isset($_POST['pausatf_save_settings']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_settings')) {
    update_option('pausatf_sync_enabled', isset($_POST['sync_enabled']) ? 1 : 0);
    update_option('pausatf_sync_frequency', sanitize_text_field($_POST['sync_frequency'] ?? 'daily'));
    update_option('pausatf_auto_create_athletes', isset($_POST['auto_create_athletes']) ? 1 : 0);
    update_option('pausatf_min_events_for_athlete', absint($_POST['min_events'] ?? 3));

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'pausatf-results') . '</p></div>';
}

$sync_enabled = get_option('pausatf_sync_enabled', 0);
$sync_frequency = get_option('pausatf_sync_frequency', 'daily');
$auto_create_athletes = get_option('pausatf_auto_create_athletes', 0);
$min_events = get_option('pausatf_min_events_for_athlete', 3);
?>

<div class="wrap">
    <h1><?php esc_html_e('PAUSATF Results Settings', 'pausatf-results'); ?></h1>

    <form method="post">
        <?php wp_nonce_field('pausatf_settings'); ?>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Automatic Sync', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable Auto-Sync', 'pausatf-results'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sync_enabled" value="1" <?php checked($sync_enabled, 1); ?>>
                            <?php esc_html_e('Automatically check for new results', 'pausatf-results'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, the plugin will periodically check pausatf.org for new results.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sync_frequency"><?php esc_html_e('Sync Frequency', 'pausatf-results'); ?></label></th>
                    <td>
                        <select name="sync_frequency" id="sync_frequency">
                            <option value="hourly" <?php selected($sync_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'pausatf-results'); ?></option>
                            <option value="twicedaily" <?php selected($sync_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'pausatf-results'); ?></option>
                            <option value="daily" <?php selected($sync_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'pausatf-results'); ?></option>
                            <option value="weekly" <?php selected($sync_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'pausatf-results'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Athlete Management', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Auto-Create Athletes', 'pausatf-results'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_create_athletes" value="1" <?php checked($auto_create_athletes, 1); ?>>
                            <?php esc_html_e('Automatically create athlete profiles', 'pausatf-results'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Create athlete profiles for competitors who appear in multiple events.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="min_events"><?php esc_html_e('Minimum Events', 'pausatf-results'); ?></label></th>
                    <td>
                        <input type="number" name="min_events" id="min_events" value="<?php echo esc_attr($min_events); ?>" min="1" max="20" class="small-text">
                        <p class="description">
                            <?php esc_html_e('Minimum number of events before an athlete profile is created.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Data Source', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Legacy Source URL', 'pausatf-results'); ?></th>
                    <td>
                        <code><?php echo esc_html(PAUSATF_LEGACY_SOURCE_URL); ?></code>
                        <p class="description">
                            <?php esc_html_e('The base URL for fetching legacy results. This is configured in the plugin.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" name="pausatf_save_settings" class="button button-primary">
                <?php esc_html_e('Save Settings', 'pausatf-results'); ?>
            </button>
        </p>
    </form>

    <!-- Tools -->
    <div class="pausatf-section">
        <h2><?php esc_html_e('Tools', 'pausatf-results'); ?></h2>

        <p>
            <button type="button" class="button" id="pausatf-bulk-create-athletes">
                <?php esc_html_e('Create Missing Athlete Profiles', 'pausatf-results'); ?>
            </button>
            <span class="spinner" style="float: none;"></span>
        </p>
        <p class="description">
            <?php esc_html_e('Creates athlete profiles for all competitors meeting the minimum events threshold.', 'pausatf-results'); ?>
        </p>

        <p>
            <button type="button" class="button" id="pausatf-reparse-all">
                <?php esc_html_e('Re-parse All Events', 'pausatf-results'); ?>
            </button>
            <span class="spinner" style="float: none;"></span>
        </p>
        <p class="description">
            <?php esc_html_e('Re-download and parse all previously imported events. Use after parser updates.', 'pausatf-results'); ?>
        </p>
    </div>
</div>

<style>
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
</style>

<script>
jQuery(document).ready(function($) {
    $('#pausatf-bulk-create-athletes').on('click', function() {
        var $btn = $(this);
        var $spinner = $btn.next('.spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(ajaxurl, {
            action: 'pausatf_bulk_create_athletes',
            _wpnonce: '<?php echo wp_create_nonce('pausatf_ajax'); ?>'
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (response.success) {
                alert('Created ' + response.data.created + ' athlete profiles.');
            } else {
                alert('Error: ' + response.data);
            }
        });
    });
});
</script>
