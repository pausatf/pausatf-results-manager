<?php
/**
 * Delete All Data empties exactly RM's tables and deletes only posts RM owns.
 */

namespace {
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) {
            return $GLOBALS['pausatf_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('get_posts')) {
        // Answers with the IDs a test stored for the queried post type.
        function get_posts($args = []) {
            $GLOBALS['pausatf_test_post_queries'][] = $args;
            return $GLOBALS['pausatf_test_posts_by_type'][$args['post_type'] ?? ''] ?? [];
        }
    }
    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce($nonce, $action = -1) {
            return 1;
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($capability) {
            return true;
        }
    }
    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data = null) {
            $GLOBALS['pausatf_test_json_success'] = $data;
        }
    }
    if (!function_exists('wp_delete_post')) {
        function wp_delete_post($post_id = 0, $force_delete = false) {
            $GLOBALS['pausatf_test_deleted_posts'][] = $post_id;
            return true;
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\Admin\AdminSettings;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__) . '/admin/class-admin-settings.php';

    final class DeleteAllDataTest extends TestCase {
        private mixed $previous_wpdb = null;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            // Every table exists, so the list alone decides what is emptied.
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $options = 'wp_options';
                /** @var list<string> */
                public array $queries = [];
                public function esc_like(string $text): string {
                    return addcslashes($text, '_%\\');
                }
                public function prepare(string $sql, ...$args): string {
                    return vsprintf(str_replace('%s', "'%s'", $sql), $args);
                }
                public function get_var(string $sql) {
                    return preg_match("/^SHOW TABLES LIKE '(.+)'$/", $sql, $m) === 1 ? stripslashes($m[1]) : null;
                }
                public function query(string $sql) {
                    $this->queries[] = $sql;
                    return 0;
                }
            };
            $GLOBALS['pausatf_test_posts_by_type'] = ['pausatf_event' => [11, 12], 'pausatf_athlete' => [21], 'pausatf_claim' => [31]];
            $GLOBALS['pausatf_test_post_queries'] = [];
            $GLOBALS['pausatf_test_deleted_posts'] = [];
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset($GLOBALS['pausatf_test_options'], $GLOBALS['pausatf_test_posts_by_type'],
                $GLOBALS['pausatf_test_post_queries'], $GLOBALS['pausatf_test_deleted_posts'],
                $GLOBALS['pausatf_test_json_success'], $_POST['_wpnonce']);
        }

        /** @return list<string> Tables truncated, without the prefix. */
        private function truncated(): array {
            $tables = [];
            foreach ($GLOBALS['wpdb']->queries as $sql) {
                if (preg_match('/^TRUNCATE TABLE `wp_(\w+)`$/', $sql, $m) === 1) {
                    $tables[] = $m[1];
                }
            }
            return $tables;
        }

        public function test_it_empties_every_table_rm_creates_and_no_other(): void {
            $root = dirname(__DIR__);
            $created = [];
            foreach (['pausatf-results.php', 'includes/class-grand-prix.php', 'includes/class-records-database.php',
                'includes/class-ranking-system.php', 'includes/class-webhooks.php'] as $file) {
                $source = (string) file_get_contents($root . '/' . $file);
                preg_match_all('/\$(\w+)\s*=\s*\$wpdb->prefix\s*\.\s*\'(pausatf_\w+)\'/', $source, $assignments, PREG_SET_ORDER);
                foreach ($assignments as [, $variable, $table]) {
                    if (preg_match('/CREATE TABLE \{?\$' . $variable . '\b/', $source) === 1) {
                        $created[] = $table;
                    }
                }
            }
            sort($created);
            $listed = AdminSettings::DATA_TABLES;
            sort($listed);

            self::assertSame(array_values(array_unique($created)), $listed);

            AdminSettings::delete_all_data();

            self::assertSame(AdminSettings::DATA_TABLES, $this->truncated());
            foreach (['pausatf_grand_prix', 'pausatf_grand_prix_points', 'pausatf_webhook_deliveries'] as $never_created) {
                self::assertNotContains($never_created, $this->truncated());
            }
        }

        public function test_it_never_deletes_events_owned_by_pausatf_site(): void {
            $GLOBALS['pausatf_test_options']['pausatf_results_event_type_owner'] = 'pausatf-site';

            $done = AdminSettings::delete_all_data();

            self::assertNotContains('pausatf_event', array_column($GLOBALS['pausatf_test_post_queries'], 'post_type'));
            self::assertSame([21, 31], $GLOBALS['pausatf_test_deleted_posts']);
            self::assertSame(['pausatf_athlete', 'pausatf_claim'], $done['post_types']);
        }

        public function test_without_a_recorded_owner_events_are_kept(): void {
            AdminSettings::delete_all_data();

            self::assertNotContains(11, $GLOBALS['pausatf_test_deleted_posts']);
        }

        public function test_it_deletes_events_of_every_status_when_rm_owns_the_type(): void {
            $GLOBALS['pausatf_test_options']['pausatf_results_event_type_owner'] = 'pausatf-results';

            AdminSettings::delete_all_data();

            self::assertSame([21, 31, 11, 12], $GLOBALS['pausatf_test_deleted_posts']);
            self::assertSame(['any'], array_unique(array_column($GLOBALS['pausatf_test_post_queries'], 'post_status')));
        }

        public function test_it_deletes_only_rm_transients(): void {
            AdminSettings::delete_all_data();

            $deletes = array_values(array_filter($GLOBALS['wpdb']->queries, static fn(string $sql): bool => str_starts_with($sql, 'DELETE')));
            self::assertCount(2, $deletes);
            self::assertStringContainsString("'\\_transient\\_pausatf\\_usatf\\_%'", $deletes[0]);
            self::assertStringContainsString("'\\_transient\\_timeout\\_pausatf\\_cert\\_gen\\_%'", $deletes[1]);
            foreach ($deletes as $sql) {
                self::assertStringNotContainsString("'_transient_pausatf_%'", $sql);
            }
        }

        public function test_clear_cache_removes_rm_transients_and_keeps_other_plugins(): void {
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $options = 'wp_options';
                /** @var list<string> */
                public array $rows = [
                    '_transient_pausatf_usatf_abc',
                    '_transient_timeout_pausatf_usatf_abc',
                    '_transient_pausatf_cert_gen_def',
                    '_transient_timeout_pausatf_cert_gen_def',
                    '_transient_pausatf_site_archive_stats',
                    '_transient_timeout_pausatf_site_archive_stats',
                    '_transient_other_plugin_cache',
                ];
                public function esc_like(string $text): string {
                    return addcslashes($text, '_%\\');
                }
                public function prepare(string $sql, ...$args): string {
                    return vsprintf(str_replace('%s', "'%s'", $sql), $args);
                }
                /** Applies the LIKE patterns of a DELETE to $rows. */
                public function query(string $sql) {
                    $parts = explode("option_name LIKE '", $sql);
                    array_shift($parts);
                    foreach ($parts as $part) {
                        $like = (string) strstr($part, "'", true);
                        $regex = '/^' . str_replace('%', '.*', preg_quote(stripslashes($like), '/')) . '$/';
                        $this->rows = array_values(array_filter(
                            $this->rows,
                            static fn(string $name): bool => preg_match($regex, $name) !== 1
                        ));
                    }
                    return 0;
                }
            };
            $_POST['_wpnonce'] = 'ok';

            AdminSettings::ajax_clear_cache();

            self::assertSame([
                '_transient_pausatf_site_archive_stats',
                '_transient_timeout_pausatf_site_archive_stats',
                '_transient_other_plugin_cache',
            ], $GLOBALS['wpdb']->rows);
            self::assertArrayHasKey('message', $GLOBALS['pausatf_test_json_success']);
        }
    }
}
