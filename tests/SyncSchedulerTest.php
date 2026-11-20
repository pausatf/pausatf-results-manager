<?php
/**
 * The scheduled sync and batch import fetch the directory listing with TLS verification.
 */

namespace {
    if (!function_exists('add_filter')) {
        function add_filter(...$args): void {
        }
    }
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) {
            return $GLOBALS['pausatf_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('wp_remote_get')) {
        // Records each request and answers with an empty listing, so the caller stops there.
        function wp_remote_get($url, $args = []) {
            $GLOBALS['pausatf_test_http'][] = ['url' => $url, 'args' => $args];
            return ['body' => ''];
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            return false;
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response) {
            return is_array($response) ? ($response['body'] ?? '') : '';
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\SyncScheduler;
    use PHPUnit\Framework\TestCase;

    final class SyncSchedulerTest extends TestCase {
        protected function setUp(): void {
            defined('PAUSATF_LEGACY_SOURCE_URL') || define('PAUSATF_LEGACY_SOURCE_URL', 'https://www.pausatf.org/data/');
            require_once dirname(__DIR__) . '/includes/parsers/class-parser-detector.php';
            require_once dirname(__DIR__) . '/includes/parsers/class-parser-table.php';
            require_once dirname(__DIR__) . '/includes/parsers/class-parser-pre.php';
            require_once dirname(__DIR__) . '/includes/parsers/class-parser-word.php';
            require_once dirname(__DIR__) . '/includes/class-results-importer.php';
            require_once dirname(__DIR__) . '/cron/class-sync-scheduler.php';
            $GLOBALS['pausatf_test_http'] = [];
            $GLOBALS['pausatf_test_options']['pausatf_sync_enabled'] = 1;
        }

        protected function tearDown(): void {
            unset($GLOBALS['pausatf_test_http'], $GLOBALS['pausatf_test_options']['pausatf_sync_enabled']);
        }

        public function test_sync_and_batch_listing_requests_verify_certificates(): void {
            SyncScheduler::instance()->run_sync();
            SyncScheduler::instance()->run_batch_import(2005);

            $requests = $GLOBALS['pausatf_test_http'];
            self::assertCount(2, $requests);
            self::assertSame('https://www.pausatf.org/data/2005/', $requests[1]['url']);
            foreach ($requests as $request) {
                // Absent means WordPress's default, which verifies against its CA bundle.
                self::assertNotFalse($request['args']['sslverify'] ?? true, $request['url']);
            }
        }
    }
}
