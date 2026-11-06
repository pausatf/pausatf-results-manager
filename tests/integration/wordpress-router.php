<?php
/** Loopback integration-test router for PHP's development server. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || !getenv('PAUSATF_ARCHIVE_WP_TEST_DB')) {
    http_response_code(404);
    exit;
}
$root = realpath((string) getenv('PAUSATF_ARCHIVE_WP_TEST_PATH'));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($root === false || !is_string($path)) { http_response_code(400); exit; }
$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) { return false; }
require $root . '/index.php';
