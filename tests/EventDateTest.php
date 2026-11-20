<?php
/**
 * Competition date: PAUSATF Site's _pausatf_event_start first, then the legacy _pausatf_event_date.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\EventDate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-event-date.php';

class EventDateTest extends TestCase {
    private ?object $saved_wpdb = null;

    protected function setUp(): void {
        $this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = (object) ['postmeta' => 'wp_postmeta', 'posts' => 'wp_posts'];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
    }

    public function test_site_start_wins_over_legacy_date(): void {
        self::assertSame('2026-03-07', EventDate::from_meta('2026-03-07 08:00:00', '2026-03-06'));
    }

    public function test_legacy_date_is_the_fallback(): void {
        self::assertSame('2011-05-14', EventDate::from_meta('', '2011-05-14'));
        self::assertSame('', EventDate::from_meta('', ''));
        // An unparseable start does not hide a usable legacy date.
        self::assertSame('2011-05-14', EventDate::from_meta('soon', '2011-05-14'));
    }

    public function test_local_start_is_not_shifted_by_timezone(): void {
        // 23:30 in Los Angeles is the next day in UTC; the competition day is the local one.
        self::assertSame('2026-11-01', EventDate::from_meta('2026-11-01 23:30:00', '', 'America/Los_Angeles'));
    }

    public function test_start_with_offset_is_read_in_event_timezone(): void {
        self::assertSame('2026-11-01', EventDate::from_meta('2026-11-02T06:30:00Z', '', 'America/Los_Angeles'));
        self::assertSame('2026-11-02', EventDate::from_meta('2026-11-02T06:30:00Z', ''));
    }

    public function test_sql_expression_prefers_start_date_part(): void {
        if (!class_exists(\Pdo\Sqlite::class)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $pdo = new \Pdo\Sqlite('sqlite::memory:');
        $pdo->createFunction('LEFT', static fn($value, $length) => $value === null ? null : substr((string) $value, 0, (int) $length), 2);
        $pdo->exec('CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)');
        $pdo->exec('CREATE TABLE e (id INTEGER)');
        $pdo->exec("INSERT INTO e VALUES (1), (2), (3), (4)");
        $pdo->exec("INSERT INTO wp_postmeta VALUES
            (1, '_pausatf_event_start', '2026-03-07 08:00:00'), (1, '_pausatf_event_date', '2026-03-01'),
            (2, '_pausatf_event_date', '2011-05-14'),
            (3, '_pausatf_event_start', '')");

        $sql = 'SELECT e.id, ' . EventDate::sql_expr('d') . ' AS day FROM e ' . EventDate::sql_join('e.id', 'd') . ' ORDER BY e.id';
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame([1 => '2026-03-07', 2 => '2011-05-14', 3 => null, 4 => null], $rows);
    }

    public function test_order_query_replaces_meta_ordering(): void {
        $args = EventDate::order_query(['post_type' => 'pausatf_event', 'meta_key' => '_pausatf_event_date', 'orderby' => 'meta_value', 'order' => 'DESC']);

        self::assertArrayNotHasKey('meta_key', $args);
        self::assertArrayNotHasKey('orderby', $args);
        self::assertFalse($args['suppress_filters']);
        self::assertSame('DESC', $args[EventDate::ORDER_QUERY_VAR]);
    }

    public function test_posts_clauses_only_touch_flagged_queries(): void {
        $flagged = new class {
            public function get(string $var) {
                return $var === EventDate::ORDER_QUERY_VAR ? 'ASC' : '';
            }
        };
        $plain = new class {
            public function get(string $var) {
                return '';
            }
        };
        $clauses = ['join' => '', 'orderby' => 'wp_posts.post_date DESC'];

        self::assertSame($clauses, EventDate::posts_clauses($clauses, $plain));
        $ordered = EventDate::posts_clauses($clauses, $flagged);
        self::assertStringContainsString("meta_key = '_pausatf_event_start'", $ordered['join']);
        self::assertStringContainsString("meta_key = '_pausatf_event_date'", $ordered['join']);
        self::assertStringEndsWith('ASC, wp_posts.ID ASC', $ordered['orderby']);
    }
}
