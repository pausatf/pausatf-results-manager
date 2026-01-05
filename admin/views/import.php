<?php
/**
 * Admin Import View
 *
 * @package PAUSATF\Results
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle form submissions
$message = '';
$message_type = '';

if (isset($_POST['pausatf_import_url']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_import')) {
    $url = esc_url_raw($_POST['import_url'] ?? '');

    if ($url) {
        $importer = new \PAUSATF\Results\ResultsImporter();
        $result = $importer->import_from_url($url);

        if ($result['success']) {
            $message = sprintf(
                __('Successfully imported %d results from "%s"', 'pausatf-results'),
                $result['records_imported'],
                $result['event_name'] ?? 'Unknown Event'
            );
            $message_type = 'success';
        } else {
            $message = __('Import failed: ', 'pausatf-results') . ($result['error'] ?? 'Unknown error');
            $message_type = 'error';
        }
    }
}

// Handle batch import
if (isset($_POST['pausatf_batch_import']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_import')) {
    $year = (int) ($_POST['batch_year'] ?? 0);

    if ($year >= 1994 && $year <= 2025) {
        // Queue batch import
        $batch_url = PAUSATF_LEGACY_SOURCE_URL . $year . '/';
        $message = sprintf(__('Batch import queued for year %d. Check import history for progress.', 'pausatf-results'), $year);
        $message_type = 'info';

        // Schedule background import
        wp_schedule_single_event(time(), 'pausatf_batch_import', [$year]);
    }
}

// Handle URL analysis
$analysis = null;
if (isset($_POST['pausatf_analyze_url']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_import')) {
    $url = esc_url_raw($_POST['analyze_url'] ?? '');

    if ($url) {
        $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);

        if (!is_wp_error($response)) {
            $html = wp_remote_retrieve_body($response);
            $importer = new \PAUSATF\Results\ResultsImporter();
            $analysis = $importer->get_detector()->analyze($html);
        }
    }
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Import Results', 'pausatf-results'); ?></h1>

    <?php if ($message) : ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <div class="pausatf-import-container">
        <!-- Single URL Import -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Import from URL', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enter the full URL to an HTML results page from pausatf.org/data/', 'pausatf-results'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('pausatf_import'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="import_url"><?php esc_html_e('Results URL', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="url" name="import_url" id="import_url" class="regular-text"
                                   placeholder="https://www.pausatf.org/data/2024/XCChamps2024pa.html">
                            <p class="description">
                                <?php esc_html_e('Example: https://www.pausatf.org/data/2024/XCChamps2024pa.html', 'pausatf-results'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="pausatf_import_url" class="button button-primary">
                        <?php esc_html_e('Import Results', 'pausatf-results'); ?>
                    </button>
                    <button type="submit" name="pausatf_analyze_url" class="button">
                        <?php esc_html_e('Analyze Only', 'pausatf-results'); ?>
                    </button>
                </p>
            </form>

            <?php if ($analysis) : ?>
                <div class="pausatf-analysis">
                    <h3><?php esc_html_e('Format Analysis', 'pausatf-results'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><strong><?php esc_html_e('Selected Parser', 'pausatf-results'); ?></strong></td>
                            <td><?php echo esc_html($analysis['selected_parser'] ?? 'None'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Has HTML Tables', 'pausatf-results'); ?></strong></td>
                            <td><?php echo $analysis['has_tables'] ? '&#10004;' : '&#10006;'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Has PRE Tags', 'pausatf-results'); ?></strong></td>
                            <td><?php echo $analysis['has_pre'] ? '&#10004;' : '&#10006;'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('MS Word HTML', 'pausatf-results'); ?></strong></td>
                            <td><?php echo $analysis['is_word_html'] ? '&#10004;' : '&#10006;'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Estimated Year', 'pausatf-results'); ?></strong></td>
                            <td><?php echo esc_html($analysis['estimated_year'] ?? 'Unknown'); ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Batch Import -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Batch Import by Year', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Import all results from a specific year. This runs in the background.', 'pausatf-results'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('pausatf_import'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="batch_year"><?php esc_html_e('Year', 'pausatf-results'); ?></label></th>
                        <td>
                            <select name="batch_year" id="batch_year">
                                <?php for ($y = 2025; $y >= 1994; $y--) : ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="pausatf_batch_import" class="button button-secondary">
                        <?php esc_html_e('Start Batch Import', 'pausatf-results'); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- File Upload -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Upload HTML File', 'pausatf-results'); ?></h2>
            <p class="description">
                <?php esc_html_e('Upload an HTML results file directly.', 'pausatf-results'); ?>
            </p>

            <form method="post" enctype="multipart/form-data" id="pausatf-upload-form">
                <?php wp_nonce_field('pausatf_import'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="results_file"><?php esc_html_e('HTML File', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="file" name="results_file" id="results_file" accept=".html,.htm">
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="pausatf_upload_file" class="button button-secondary">
                        <?php esc_html_e('Upload & Import', 'pausatf-results'); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- Sync Settings -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Automatic Sync', 'pausatf-results'); ?></h2>
            <?php
            $next_sync = wp_next_scheduled('pausatf_results_sync');
            ?>
            <p>
                <?php if ($next_sync) : ?>
                    <?php printf(
                        esc_html__('Next scheduled sync: %s', 'pausatf-results'),
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_sync)
                    ); ?>
                <?php else : ?>
                    <?php esc_html_e('No sync scheduled.', 'pausatf-results'); ?>
                <?php endif; ?>
            </p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=pausatf-results-settings'); ?>" class="button">
                    <?php esc_html_e('Configure Sync Settings', 'pausatf-results'); ?>
                </a>
            </p>
        </div>
    </div>
</div>

<style>
.pausatf-import-container {
    max-width: 800px;
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
.pausatf-analysis {
    margin-top: 20px;
    padding: 15px;
    background: #f0f0f1;
    border-radius: 4px;
}
.pausatf-analysis h3 {
    margin-top: 0;
}
</style>
