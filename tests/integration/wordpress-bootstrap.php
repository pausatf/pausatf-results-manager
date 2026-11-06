<?php
/** Isolated WordPress fixture; never usable as a web request or production installer. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !str_starts_with((string) getenv('PAUSATF_ARCHIVE_WP_TEST_DB'), 'archive_wordpress_test_')) {
    throw new RuntimeException('Explicit isolated CLI fixture configuration required.');
}
$root = getenv('PAUSATF_ARCHIVE_WP_TEST_PATH');
$url = getenv('PAUSATF_ARCHIVE_WP_TEST_URL');
$password = getenv('PAUSATF_ARCHIVE_WP_TEST_PASSWORD');
if (!$root || !$url || !$password || !str_starts_with($url, 'http://127.0.0.1:')) {
    throw new RuntimeException('Loopback WordPress test configuration required.');
}
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
if (DB_NAME !== getenv('PAUSATF_ARCHIVE_WP_TEST_DB') || wp_get_environment_type() !== 'local' || is_blog_installed()) {
    throw new RuntimeException('A fresh, explicitly isolated local database is required.');
}
add_filter('pre_wp_mail', static fn(): bool => true);
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install('Archive workflow verification', 'archive-producer', 'archive@example.invalid', false, '', $password);
foreach (['archive-verifier' => 'administrator', 'archive-subscriber' => 'subscriber'] as $name => $role) {
    $user = wp_insert_user(['user_login' => $name, 'user_pass' => $password, 'user_email' => $name . '@example.invalid', 'role' => $role]);
    if (is_wp_error($user)) { throw new RuntimeException('Fixture user creation failed.'); }
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('pausatf-results-manager/pausatf-results.php');
if (is_wp_error($activated)) { throw new RuntimeException('Fixture plugin activation failed.'); }
update_option('home', $url);
update_option('siteurl', $url);
update_option('permalink_structure', '/%postname%/');
update_option('pausatf_archive_test_fixture', true);
$page = wp_insert_post(['post_title' => 'Archive results', 'post_name' => 'archive-results', 'post_content' => '[pausatf_archive_release id="1"]', 'post_status' => 'publish', 'post_type' => 'page'], true);
if (is_wp_error($page)) { throw new RuntimeException('Fixture page creation failed.'); }
echo json_encode(['installed' => true, 'wordpress_version' => $wp_version, 'mysql_version' => $wpdb->get_var('SELECT VERSION()'), 'page_id' => $page]), "\n";
