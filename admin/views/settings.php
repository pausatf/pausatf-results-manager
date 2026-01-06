<?php
/**
 * Admin Settings View
 *
 * @package PAUSATF\Results
 */

use PAUSATF\Results\FeatureManager;

if (!defined('ABSPATH')) {
    exit;
}

// Handle feature toggles save
if (isset($_POST['pausatf_save_features']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_features')) {
    $features = $_POST['features'] ?? [];
    $sanitized = [];

    foreach (FeatureManager::get_all_features() as $feature_id => $feature) {
        if (!$feature['toggleable']) {
            $sanitized[$feature_id] = true;
        } else {
            $sanitized[$feature_id] = isset($features[$feature_id]) ? true : false;
        }
    }

    update_option('pausatf_features', $sanitized);
    FeatureManager::clear_cache();

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Feature settings saved. Some changes may require page refresh.', 'pausatf-results') . '</p></div>';
}

// Handle general settings save
if (isset($_POST['pausatf_save_settings']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_settings')) {
    update_option('pausatf_sync_enabled', isset($_POST['sync_enabled']) ? 1 : 0);
    update_option('pausatf_sync_frequency', sanitize_text_field($_POST['sync_frequency'] ?? 'daily'));
    update_option('pausatf_auto_create_athletes', isset($_POST['auto_create_athletes']) ? 1 : 0);
    update_option('pausatf_min_events_for_athlete', absint($_POST['min_events'] ?? 3));

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'pausatf-results') . '</p></div>';
}

// Handle integration settings save
if (isset($_POST['pausatf_save_integrations']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'pausatf_integrations')) {
    // RunSignUp
    update_option('pausatf_runsignup_api_key', sanitize_text_field($_POST['runsignup_api_key'] ?? ''));
    update_option('pausatf_runsignup_api_secret', sanitize_text_field($_POST['runsignup_api_secret'] ?? ''));

    // Athlinks
    update_option('pausatf_athlinks_api_key', sanitize_text_field($_POST['athlinks_api_key'] ?? ''));

    // USATF
    update_option('pausatf_usatf_api_key', sanitize_text_field($_POST['usatf_api_key'] ?? ''));

    // Strava
    update_option('pausatf_strava_client_id', sanitize_text_field($_POST['strava_client_id'] ?? ''));
    update_option('pausatf_strava_client_secret', sanitize_text_field($_POST['strava_client_secret'] ?? ''));

    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Integration settings saved.', 'pausatf-results') . '</p></div>';
}

$sync_enabled = get_option('pausatf_sync_enabled', 0);
$sync_frequency = get_option('pausatf_sync_frequency', 'daily');
$auto_create_athletes = get_option('pausatf_auto_create_athletes', 0);
$min_events = get_option('pausatf_min_events_for_athlete', 3);

// Get feature categories
$categories = FeatureManager::get_features_by_category();
$feature_states = FeatureManager::get_all_states();

// Current tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'features';
$tabs = [
    'features' => __('Features', 'pausatf-results'),
    'general' => __('General', 'pausatf-results'),
    'integrations' => __('Integrations', 'pausatf-results'),
    'tools' => __('Tools', 'pausatf-results'),
];
?>

<div class="wrap pausatf-settings-wrap">
    <h1><?php esc_html_e('PAUSATF Results Settings', 'pausatf-results'); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_id => $tab_label): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $tab_id)); ?>"
               class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($current_tab === 'features'): ?>
        <!-- Features Tab -->
        <form method="post" class="pausatf-features-form">
            <?php wp_nonce_field('pausatf_features'); ?>

            <div class="pausatf-features-header">
                <p class="description">
                    <?php esc_html_e('Enable or disable plugin features. Disabled features will not load, reducing resource usage.', 'pausatf-results'); ?>
                </p>
                <div class="pausatf-bulk-actions">
                    <button type="button" class="button" id="pausatf-enable-all">
                        <?php esc_html_e('Enable All', 'pausatf-results'); ?>
                    </button>
                    <button type="button" class="button" id="pausatf-disable-optional">
                        <?php esc_html_e('Disable All Optional', 'pausatf-results'); ?>
                    </button>
                    <button type="button" class="button" id="pausatf-reset-defaults">
                        <?php esc_html_e('Reset to Defaults', 'pausatf-results'); ?>
                    </button>
                </div>
            </div>

            <?php foreach ($categories as $category_id => $category): ?>
                <?php if (empty($category['features'])) continue; ?>

                <div class="pausatf-feature-category" data-category="<?php echo esc_attr($category_id); ?>">
                    <div class="pausatf-category-header">
                        <h2>
                            <span class="pausatf-category-toggle dashicons dashicons-arrow-down-alt2"></span>
                            <?php echo esc_html($category['label']); ?>
                            <span class="pausatf-category-count">
                                <?php
                                $enabled = 0;
                                foreach ($category['features'] as $fid => $f) {
                                    if ($feature_states[$fid] ?? false) $enabled++;
                                }
                                printf(
                                    esc_html__('%d of %d enabled', 'pausatf-results'),
                                    $enabled,
                                    count($category['features'])
                                );
                                ?>
                            </span>
                        </h2>
                        <p class="description"><?php echo esc_html($category['description']); ?></p>
                    </div>

                    <div class="pausatf-feature-grid">
                        <?php foreach ($category['features'] as $feature_id => $feature):
                            $is_enabled = $feature_states[$feature_id] ?? false;
                            $is_toggleable = $feature['toggleable'];
                            $requirements = FeatureManager::check_requirements($feature_id);
                            $dependencies = FeatureManager::get_dependencies($feature_id);
                            $dependents = FeatureManager::get_dependents($feature_id);
                        ?>
                            <div class="pausatf-feature-card <?php echo $is_enabled ? 'enabled' : 'disabled'; ?> <?php echo !$is_toggleable ? 'core' : ''; ?>"
                                 data-feature="<?php echo esc_attr($feature_id); ?>"
                                 data-dependencies="<?php echo esc_attr(json_encode($dependencies)); ?>"
                                 data-dependents="<?php echo esc_attr(json_encode($dependents)); ?>"
                                 data-default="<?php echo $feature['default'] ? '1' : '0'; ?>">

                                <div class="pausatf-feature-header">
                                    <span class="dashicons <?php echo esc_attr($feature['icon']); ?>"></span>
                                    <h3><?php echo esc_html($feature['name']); ?></h3>
                                </div>

                                <p class="pausatf-feature-description">
                                    <?php echo esc_html($feature['description']); ?>
                                </p>

                                <?php if (!empty($feature['shortcodes'])): ?>
                                    <div class="pausatf-feature-meta">
                                        <span class="dashicons dashicons-shortcode"></span>
                                        <code><?php echo esc_html('[' . implode('], [', $feature['shortcodes']) . ']'); ?></code>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$requirements['met']): ?>
                                    <div class="pausatf-feature-warning">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php
                                        printf(
                                            esc_html__('Missing: %s', 'pausatf-results'),
                                            implode(', ', array_map([FeatureManager::class, 'get_requirement_name'], $requirements['missing']))
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($dependencies)): ?>
                                    <div class="pausatf-feature-dependencies">
                                        <span class="dashicons dashicons-admin-links"></span>
                                        <?php
                                        $dep_names = [];
                                        foreach ($dependencies as $dep) {
                                            $dep_feature = FeatureManager::get_feature($dep);
                                            if ($dep_feature) {
                                                $dep_names[] = $dep_feature['name'];
                                            }
                                        }
                                        printf(
                                            esc_html__('Requires: %s', 'pausatf-results'),
                                            implode(', ', $dep_names)
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <div class="pausatf-feature-toggle">
                                    <?php if ($is_toggleable && $requirements['met']): ?>
                                        <label class="pausatf-switch">
                                            <input type="checkbox"
                                                   name="features[<?php echo esc_attr($feature_id); ?>]"
                                                   value="1"
                                                   <?php checked($is_enabled); ?>
                                                   class="pausatf-feature-checkbox">
                                            <span class="pausatf-slider"></span>
                                        </label>
                                        <span class="pausatf-toggle-label">
                                            <?php echo $is_enabled ? esc_html__('Enabled', 'pausatf-results') : esc_html__('Disabled', 'pausatf-results'); ?>
                                        </span>
                                    <?php elseif (!$is_toggleable): ?>
                                        <input type="hidden" name="features[<?php echo esc_attr($feature_id); ?>]" value="1">
                                        <span class="pausatf-core-badge">
                                            <span class="dashicons dashicons-lock"></span>
                                            <?php esc_html_e('Core Feature', 'pausatf-results'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="pausatf-unavailable-badge">
                                            <span class="dashicons dashicons-no"></span>
                                            <?php esc_html_e('Requirements Not Met', 'pausatf-results'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <p class="submit">
                <button type="submit" name="pausatf_save_features" class="button button-primary button-large">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e('Save Feature Settings', 'pausatf-results'); ?>
                </button>
            </p>
        </form>

    <?php elseif ($current_tab === 'general'): ?>
        <!-- General Settings Tab -->
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

    <?php elseif ($current_tab === 'integrations'): ?>
        <!-- Integrations Settings Tab -->
        <form method="post">
            <?php wp_nonce_field('pausatf_integrations'); ?>

            <?php if (FeatureManager::is_enabled('runsignup_integration')): ?>
            <div class="pausatf-section">
                <h2>
                    <span class="dashicons dashicons-migrate"></span>
                    <?php esc_html_e('RunSignUp', 'pausatf-results'); ?>
                </h2>

                <table class="form-table">
                    <tr>
                        <th><label for="runsignup_api_key"><?php esc_html_e('API Key', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="text" name="runsignup_api_key" id="runsignup_api_key"
                                   value="<?php echo esc_attr(get_option('pausatf_runsignup_api_key', '')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="runsignup_api_secret"><?php esc_html_e('API Secret', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="password" name="runsignup_api_secret" id="runsignup_api_secret"
                                   value="<?php echo esc_attr(get_option('pausatf_runsignup_api_secret', '')); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Get your API credentials from %s', 'pausatf-results'),
                                    '<a href="https://runsignup.com/API" target="_blank">RunSignUp API Portal</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <?php if (FeatureManager::is_enabled('athlinks_integration')): ?>
            <div class="pausatf-section">
                <h2>
                    <span class="dashicons dashicons-database-import"></span>
                    <?php esc_html_e('Athlinks', 'pausatf-results'); ?>
                </h2>

                <table class="form-table">
                    <tr>
                        <th><label for="athlinks_api_key"><?php esc_html_e('API Key', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="text" name="athlinks_api_key" id="athlinks_api_key"
                                   value="<?php echo esc_attr(get_option('pausatf_athlinks_api_key', '')); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Contact Athlinks for API access.', 'pausatf-results'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <?php if (FeatureManager::is_enabled('usatf_verification')): ?>
            <div class="pausatf-section">
                <h2>
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e('USATF Membership Verification', 'pausatf-results'); ?>
                </h2>

                <table class="form-table">
                    <tr>
                        <th><label for="usatf_api_key"><?php esc_html_e('API Key', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="text" name="usatf_api_key" id="usatf_api_key"
                                   value="<?php echo esc_attr(get_option('pausatf_usatf_api_key', '')); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Contact USATF for association API access.', 'pausatf-results'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <?php if (FeatureManager::is_enabled('strava_sync')): ?>
            <div class="pausatf-section">
                <h2>
                    <span class="dashicons dashicons-share"></span>
                    <?php esc_html_e('Strava', 'pausatf-results'); ?>
                </h2>

                <table class="form-table">
                    <tr>
                        <th><label for="strava_client_id"><?php esc_html_e('Client ID', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="text" name="strava_client_id" id="strava_client_id"
                                   value="<?php echo esc_attr(get_option('pausatf_strava_client_id', '')); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="strava_client_secret"><?php esc_html_e('Client Secret', 'pausatf-results'); ?></label></th>
                        <td>
                            <input type="password" name="strava_client_secret" id="strava_client_secret"
                                   value="<?php echo esc_attr(get_option('pausatf_strava_client_secret', '')); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Create an app at %s', 'pausatf-results'),
                                    '<a href="https://www.strava.com/settings/api" target="_blank">Strava API Settings</a>'
                                );
                                ?>
                            </p>
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Callback URL: %s', 'pausatf-results'),
                                    '<code>' . esc_html(home_url('/pausatf-strava-callback')) . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <?php
            // Check if any integration is enabled
            $any_integration_enabled = FeatureManager::is_enabled('runsignup_integration')
                || FeatureManager::is_enabled('athlinks_integration')
                || FeatureManager::is_enabled('usatf_verification')
                || FeatureManager::is_enabled('strava_sync');

            if (!$any_integration_enabled):
            ?>
            <div class="pausatf-section">
                <p class="description">
                    <?php
                    printf(
                        esc_html__('No integrations are currently enabled. Enable integrations in the %sFeatures%s tab.', 'pausatf-results'),
                        '<a href="' . esc_url(add_query_arg('tab', 'features')) . '">',
                        '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php else: ?>
            <p class="submit">
                <button type="submit" name="pausatf_save_integrations" class="button button-primary">
                    <?php esc_html_e('Save Integration Settings', 'pausatf-results'); ?>
                </button>
            </p>
            <?php endif; ?>
        </form>

    <?php elseif ($current_tab === 'tools'): ?>
        <!-- Tools Tab -->
        <div class="pausatf-section">
            <h2><?php esc_html_e('Bulk Operations', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Create Athlete Profiles', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-bulk-create-athletes">
                            <?php esc_html_e('Create Missing Athlete Profiles', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Creates athlete profiles for all competitors meeting the minimum events threshold.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Re-parse Events', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-reparse-all">
                            <?php esc_html_e('Re-parse All Events', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Re-download and parse all previously imported events. Use after parser updates.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php if (FeatureManager::is_enabled('records_database')): ?>
        <div class="pausatf-section">
            <h2><?php esc_html_e('Records', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Scan for Records', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-scan-records">
                            <?php esc_html_e('Scan All Results for Potential Records', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Scans all imported results to identify potential association records.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <?php if (FeatureManager::is_enabled('ranking_system')): ?>
        <div class="pausatf-section">
            <h2><?php esc_html_e('Rankings', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Regenerate Rankings', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-regenerate-rankings">
                            <?php esc_html_e('Regenerate All Rankings', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Recalculates all rankings based on current results data.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <div class="pausatf-section">
            <h2><?php esc_html_e('Database', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Database Tables', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-repair-tables">
                            <?php esc_html_e('Repair Database Tables', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Recreates any missing database tables required by enabled features.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Cache', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button" id="pausatf-clear-cache">
                            <?php esc_html_e('Clear All Caches', 'pausatf-results'); ?>
                        </button>
                        <span class="spinner" style="float: none;"></span>
                        <p class="description">
                            <?php esc_html_e('Clears all cached data including rankings, records, and transients.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="pausatf-section pausatf-danger-zone">
            <h2><?php esc_html_e('Danger Zone', 'pausatf-results'); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Delete All Data', 'pausatf-results'); ?></th>
                    <td>
                        <button type="button" class="button button-link-delete" id="pausatf-delete-all">
                            <?php esc_html_e('Delete All Results Data', 'pausatf-results'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Permanently deletes all imported results, events, and athlete data. This cannot be undone.', 'pausatf-results'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Feature toggle functionality
    $('.pausatf-feature-checkbox').on('change', function() {
        var $card = $(this).closest('.pausatf-feature-card');
        var $label = $card.find('.pausatf-toggle-label');
        var isChecked = $(this).is(':checked');

        $card.toggleClass('enabled', isChecked).toggleClass('disabled', !isChecked);
        $label.text(isChecked ? '<?php echo esc_js(__('Enabled', 'pausatf-results')); ?>' : '<?php echo esc_js(__('Disabled', 'pausatf-results')); ?>');

        // Handle dependencies
        var dependencies = $card.data('dependencies') || [];
        var dependents = $card.data('dependents') || [];
        var featureId = $card.data('feature');

        if (isChecked) {
            // Enable all dependencies
            dependencies.forEach(function(dep) {
                var $depCard = $('[data-feature="' + dep + '"]');
                var $depCheckbox = $depCard.find('.pausatf-feature-checkbox');
                if (!$depCheckbox.is(':checked')) {
                    $depCheckbox.prop('checked', true).trigger('change');
                }
            });
        } else {
            // Disable all dependents
            dependents.forEach(function(dep) {
                var $depCard = $('[data-feature="' + dep + '"]');
                var $depCheckbox = $depCard.find('.pausatf-feature-checkbox');
                if ($depCheckbox.is(':checked')) {
                    $depCheckbox.prop('checked', false).trigger('change');
                }
            });
        }

        updateCategoryCounts();
    });

    // Category collapse/expand
    $('.pausatf-category-header').on('click', function() {
        var $category = $(this).closest('.pausatf-feature-category');
        var $grid = $category.find('.pausatf-feature-grid');
        var $toggle = $(this).find('.pausatf-category-toggle');

        $grid.slideToggle(200);
        $toggle.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
    });

    // Bulk actions
    $('#pausatf-enable-all').on('click', function() {
        $('.pausatf-feature-checkbox').each(function() {
            if (!$(this).is(':checked')) {
                $(this).prop('checked', true).trigger('change');
            }
        });
    });

    $('#pausatf-disable-optional').on('click', function() {
        $('.pausatf-feature-checkbox').each(function() {
            var $card = $(this).closest('.pausatf-feature-card');
            if (!$card.hasClass('core') && $(this).is(':checked')) {
                $(this).prop('checked', false).trigger('change');
            }
        });
    });

    $('#pausatf-reset-defaults').on('click', function() {
        $('.pausatf-feature-card').each(function() {
            var $card = $(this);
            var $checkbox = $card.find('.pausatf-feature-checkbox');
            var defaultVal = $card.data('default') == '1';

            if ($checkbox.is(':checked') !== defaultVal) {
                $checkbox.prop('checked', defaultVal).trigger('change');
            }
        });
    });

    function updateCategoryCounts() {
        $('.pausatf-feature-category').each(function() {
            var $category = $(this);
            var total = $category.find('.pausatf-feature-card').length;
            var enabled = $category.find('.pausatf-feature-card.enabled').length;
            $category.find('.pausatf-category-count').text(enabled + ' of ' + total + ' enabled');
        });
    }

    // Tools buttons
    function handleToolButton(buttonId, action, confirmMessage) {
        $(buttonId).on('click', function() {
            if (confirmMessage && !confirm(confirmMessage)) {
                return;
            }

            var $btn = $(this);
            var $spinner = $btn.next('.spinner');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.post(ajaxurl, {
                action: action,
                _wpnonce: '<?php echo wp_create_nonce('pausatf_ajax'); ?>'
            }, function(response) {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                if (response.success) {
                    alert(response.data.message || 'Operation completed successfully.');
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                alert('Request failed. Please try again.');
            });
        });
    }

    handleToolButton('#pausatf-bulk-create-athletes', 'pausatf_bulk_create_athletes');
    handleToolButton('#pausatf-reparse-all', 'pausatf_reparse_all');
    handleToolButton('#pausatf-scan-records', 'pausatf_scan_records');
    handleToolButton('#pausatf-regenerate-rankings', 'pausatf_regenerate_rankings');
    handleToolButton('#pausatf-repair-tables', 'pausatf_repair_tables');
    handleToolButton('#pausatf-clear-cache', 'pausatf_clear_cache');
    handleToolButton('#pausatf-delete-all', 'pausatf_delete_all',
        '<?php echo esc_js(__('Are you absolutely sure? This will permanently delete all results, events, and athlete data. This action cannot be undone!', 'pausatf-results')); ?>');
});
</script>
