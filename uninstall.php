<?php
/**
 * Uninstall cleanup for PAUSATF Results Manager.
 *
 * Removes plugin options only. Imported result data in custom tables is
 * deliberately preserved: it is the association's records and must not be
 * destroyed by an accidental plugin deletion. Drop those tables manually if
 * a full removal is intended.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (['pausatf_results_settings', 'pausatf_results_db_version', 'pausatf_results_version'] as $option) {
    delete_option($option);
}
