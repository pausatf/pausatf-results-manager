<?php
/**
 * The Sanctions Manager is retired: old shortcodes render a notice, the reminder job is
 * unscheduled, and nothing in the plugin still loads the removed files.
 */

namespace {
    if (!function_exists('esc_html__')) {
        function esc_html__($text, $domain = 'default') {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('esc_url')) {
        function esc_url($url) {
            return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook) {
            return $GLOBALS['pausatf_test_cron'][$hook] ?? false;
        }
    }
    if (!function_exists('wp_clear_scheduled_hook')) {
        function wp_clear_scheduled_hook($hook) {
            $GLOBALS['pausatf_test_cleared'][] = $hook;
            unset($GLOBALS['pausatf_test_cron'][$hook]);
            return 1;
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\SanctionsRetirement;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-sanctions-retirement.php';

    class SanctionsRetirementTest extends TestCase {
        protected function tearDown(): void {
            unset($GLOBALS['pausatf_test_cron'], $GLOBALS['pausatf_test_cleared'], $GLOBALS['pausatf_test_filters']['pausatf_results_sanctions_url']);
        }

        public function test_old_shortcodes_render_a_usatf_notice(): void {
            $html = SanctionsRetirement::render_notice();

            self::assertStringContainsString('USA Track &amp; Field', $html);
            self::assertStringContainsString('href="' . SanctionsRetirement::DEFAULT_URL . '"', $html);
        }

        public function test_notice_link_is_filterable_and_escaped(): void {
            $GLOBALS['pausatf_test_filters']['pausatf_results_sanctions_url'] = static fn() => 'https://example.org/?a="b"';

            self::assertStringContainsString('href="https://example.org/?a=&quot;b&quot;"', SanctionsRetirement::render_notice());
        }

        public function test_reminder_job_is_unscheduled_only_when_present(): void {
            SanctionsRetirement::unschedule_reminders();
            self::assertArrayNotHasKey('pausatf_test_cleared', $GLOBALS);

            $GLOBALS['pausatf_test_cron'][SanctionsRetirement::CRON_HOOK] = 1700000000;
            SanctionsRetirement::unschedule_reminders();
            self::assertSame([SanctionsRetirement::CRON_HOOK], $GLOBALS['pausatf_test_cleared']);
        }

        public function test_plugin_no_longer_loads_sanctions_code(): void {
            $root = dirname(__DIR__);
            $bootstrap = (string) file_get_contents($root . '/pausatf-results.php');

            self::assertStringNotContainsString('SanctionsManager', $bootstrap);
            self::assertStringNotContainsString('sanctions_manager', $bootstrap);
            self::assertStringNotContainsString('sanctions_manager', (string) file_get_contents($root . '/includes/class-feature-manager.php'));
            preg_match_all("/PAUSATF_RESULTS_DIR \\. '([^']+\\.php)'/", $bootstrap, $paths);
            foreach ($paths[1] as $path) {
                self::assertFileExists($root . '/' . $path);
            }
        }
    }
}
