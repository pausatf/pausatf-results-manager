<?php
/**
 * Every selectable webhook event has a trigger. record.approved fires from
 * RecordsDatabase::approve_record(); result.updated is not offered because RM never edits a stored result.
 */

namespace {
    if (!function_exists('do_action')) {
        function do_action($hook, ...$args) {
            $GLOBALS['pausatf_test_actions'][] = [$hook, ...$args];
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type) {
            return '2026-01-01 00:00:00';
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {
            $GLOBALS['pausatf_test_scheduled'][] = [$hook, $args];
            return true;
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\RecordsDatabase;
    use PAUSATF\Results\Webhooks;
    use PHPUnit\Framework\TestCase;

    final class WebhookEventsFakeWpdb {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        /** @var list<array{string, array<string, mixed>}> */
        public array $inserts = [];
        /** @var array<string, mixed>|null */
        public ?array $pending = null;
        public function prepare(string $sql, ...$args): string {
            return $sql;
        }
        public function get_row(string $sql, $output = null) {
            return str_contains($sql, 'pausatf_pending_records') ? $this->pending : null;
        }
        /** The one webhook subscribed to the queried event. */
        public function get_results(string $sql, $output = null) {
            return str_contains($sql, 'is_active = 1')
                ? [['id' => 7, 'url' => 'https://example.test/hook', 'secret' => 's', 'events' => '["record.approved"]']]
                : [];
        }
        public function query(string $sql) {
            return 0;
        }
        public function insert(string $table, array $data) {
            $this->inserts[] = [$table, $data];
            $this->insert_id = 40 + count($this->inserts);
            return 1;
        }
        public function update(string $table, array $data, array $where) {
            return 1;
        }
        public function delete(string $table, array $where) {
            return 1;
        }
    }

    final class WebhookEventsTest extends TestCase {
        private mixed $previous_wpdb = null;
        private WebhookEventsFakeWpdb $db;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            $this->db = new WebhookEventsFakeWpdb();
            $GLOBALS['wpdb'] = $this->db;
            $GLOBALS['pausatf_test_actions'] = [];
            $GLOBALS['pausatf_test_hooks'] = [];
            if (!defined('ARRAY_A')) {
                define('ARRAY_A', 'ARRAY_A');
            }
            foreach (['includes/rules/class-usatf-rules-engine.php', 'includes/rules/class-usatf-age-divisions.php',
                'includes/class-records-database.php', 'includes/class-webhooks.php'] as $file) {
                require_once dirname(__DIR__) . '/' . $file;
            }
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset($GLOBALS['pausatf_test_actions'], $GLOBALS['pausatf_test_scheduled']);
        }

        public function test_result_updated_is_not_offered(): void {
            self::assertArrayNotHasKey('result.updated', Webhooks::EVENTS);
            self::assertArrayHasKey('record.approved', Webhooks::EVENTS);
        }

        public function test_approving_a_pending_record_fires_the_webhook_source_action(): void {
            $this->db->pending = [
                'id' => 5, 'record_type' => 'association', 'event' => '10K', 'venue_type' => 'road',
                'gender' => 'F', 'division_code' => 'W40', 'performance' => 2100.0,
                'performance_display' => '35:00', 'athlete_name' => 'Jane Runner', 'athlete_id' => null,
                'athlete_age' => 41, 'club' => null, 'location' => null, 'competition' => 'Test 10K',
                'record_date' => '2026-05-01', 'wind_reading' => null, 'source_result_id' => null,
            ];

            self::assertTrue((new RecordsDatabase())->approve_record(5, 9, 'ok'));

            $fired = array_values(array_filter(
                $GLOBALS['pausatf_test_actions'],
                static fn(array $a): bool => $a[0] === 'pausatf_record_approved'
            ));
            self::assertCount(1, $fired);
            [, $record_id, $record, $pending_id] = $fired[0];
            self::assertSame(41, $record_id);
            self::assertSame(5, $pending_id);
            self::assertSame('Jane Runner', $record['athlete_name']);
            self::assertSame(9, $record['verified_by']);
        }

        public function test_the_handler_queues_a_record_approved_delivery(): void {
            $webhooks = new Webhooks();
            self::assertContains(
                [$webhooks, 'on_record_approved'],
                array_column($GLOBALS['pausatf_test_hooks']['pausatf_record_approved'], 0)
            );

            $webhooks->on_record_approved(41, [
                'event' => '10K', 'division_code' => 'W40', 'performance_display' => '35:00',
                'athlete_name' => 'Jane Runner', 'record_date' => '2026-05-01', 'verified_by' => 9,
            ], 5);

            [$table, $row] = end($this->db->inserts);
            self::assertSame('wp_pausatf_webhook_logs', $table);
            self::assertSame('record.approved', $row['event']);
            $payload = json_decode($row['payload'], true);
            self::assertSame('record.approved', $payload['event']);
            self::assertSame(
                ['record_id' => 41, 'pending_id' => 5, 'event' => '10K', 'division' => 'W40', 'performance' => '35:00',
                    'athlete_name' => 'Jane Runner', 'record_date' => '2026-05-01', 'verified_by' => 9],
                $payload['data']
            );
        }
    }
}
