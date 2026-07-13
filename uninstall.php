<?php
/**
 * Uninstall cleanup for PAUSATF Results Manager.
 *
 * Removes all plugin options, including third-party API keys and secrets, so
 * no credentials linger after the plugin is deleted. Imported result data in
 * custom tables is deliberately preserved: it is the association's records and
 * must not be destroyed by an accidental deletion. Drop those tables manually
 * for a full removal.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'pausatf_results',
    'pausatf_features',
    'pausatf_auto_create_athletes',
    'pausatf_min_events_for_athlete',
    'pausatf_sync_enabled',
    'pausatf_sync_frequency',
    'pausatf_sanctions_notification_email',
    // Third-party credentials — remove so secrets do not persist.
    'pausatf_athlinks_api_key',
    'pausatf_chronotrack_api_key',
    'pausatf_mylaps_api_key',
    'pausatf_racetab_api_key',
    'pausatf_webscorer_api_key',
    'pausatf_usatf_api_key',
    'pausatf_usatf_api_secret',
    'pausatf_runsignup_api_key',
    'pausatf_runsignup_api_secret',
    'pausatf_strava_client_id',
    'pausatf_strava_client_secret',
    'pausatf_garmin_consumer_key',
    'pausatf_garmin_consumer_secret',
];

// Per-user metadata, including third-party OAuth tokens, must also be purged.
$user_meta = [
    '_pausatf_strava_access_token',
    '_pausatf_strava_refresh_token',
    '_pausatf_strava_expires_at',
    '_pausatf_strava_athlete_id',
    '_pausatf_garmin_access_token',
    '_pausatf_athlete_id',
    '_pausatf_notify_results',
    '_pausatf_notify_rankings',
];

$delete_all = static function () use ($options, $user_meta): void {
    foreach ($options as $option) {
        delete_option($option);
    }
    foreach ($user_meta as $meta_key) {
        // Delete this key for every user (delete_all = true).
        delete_metadata('user', 0, $meta_key, '', true);
    }
};

if (is_multisite()) {
    // delete_option only touches the current site; sweep every site in the network.
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
        switch_to_blog((int) $blog_id);
        $delete_all();
        restore_current_blog();
    }
} else {
    $delete_all();
}
