<?php
/**
 * Age-graded rankings: graded only with a table, only for the requested sex and adults,
 * and stamped with the table edition. Factors are synthetic test fixtures.
 */

namespace {
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) {
            return $GLOBALS['pausatf_test_options'][$name] ?? $default;
        }
    }
}

namespace PAUSATF\Results\Tests {

use PAUSATF\Results\AgeGrading;
use PAUSATF\Results\RankingSystem;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-event-date.php';
require_once __DIR__ . '/../includes/class-age-grading.php';
require_once __DIR__ . '/../includes/rules/class-usatf-rules-engine.php';
require_once __DIR__ . '/../includes/class-ranking-system.php';
require_once __DIR__ . '/../includes/rules/class-usatf-age-divisions.php';

class AgeGradedRankingsTest extends TestCase {
    private mixed $previous_wpdb = null;

    protected function setUp(): void {
        AgeGrading::clear_cache();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $posts = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            /** @var list<mixed> */
            public array $prepared = [];
            /** @var list<array<string, mixed>> */
            public array $inserted = [];
            /** @var list<array<string, mixed>> */
            public array $rows = [];
            public function prepare(string $sql, ...$args): string {
                $this->prepared = $args;
                return $sql;
            }
            public function get_results(string $sql, $output = null): array {
                return $this->rows;
            }
            public function delete(string $table, array $where): int {
                return 0;
            }
            public function insert(string $table, array $data): int {
                $this->inserted[] = $data;
                return 1;
            }
        };
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        unset($GLOBALS['pausatf_test_options']);
        AgeGrading::clear_cache();
    }

    public function test_no_table_means_no_age_graded_rankings(): void {
        $GLOBALS['wpdb']->rows = [$this->row(1, 'Pat', 1000, 50)];

        self::assertSame(0, (new RankingSystem())->generate_age_graded_rankings('5K', 'M'));
        self::assertSame([], $GLOBALS['wpdb']->inserted);
    }

    public function test_rankings_filter_by_sex_and_adult_age_and_store_the_edition(): void {
        $GLOBALS['pausatf_test_options'][AgeGrading::OPTION] = [
            'edition' => 'test fixture',
            'source' => 'synthetic',
            'events' => ['5K' => ['M' => ['standard_seconds' => 800, 'factors' => ['40' => 0.9, '50' => 0.8]]]],
        ];
        $GLOBALS['wpdb']->rows = [$this->row(1, 'Pat', 1000, 50), $this->row(2, 'Lee', 1000, 40)];

        self::assertSame(2, (new RankingSystem())->generate_age_graded_rankings('5K', 'M'));

        self::assertSame([AgeGrading::MIN_AGE, 'M'], array_slice($GLOBALS['wpdb']->prepared, 0, 2));
        self::assertSame('Pat', $GLOBALS['wpdb']->inserted[0]['athlete_name']);
        self::assertEquals(100.0, $GLOBALS['wpdb']->inserted[0]['age_graded_score']);
        self::assertSame('test fixture', $GLOBALS['wpdb']->inserted[0]['age_grade_edition']);
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $name, int $time, int $age): array {
        return ['id' => $id, 'athlete_id' => null, 'athlete_name' => $name, 'time_seconds' => $time,
            'athlete_age' => $age, 'sex' => 'M', 'event_date' => '2026-05-01'];
    }
}
}
