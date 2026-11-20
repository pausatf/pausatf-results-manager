<?php
/**
 * Age grading from an imported table. The numbers below are synthetic fixtures chosen
 * for easy arithmetic; they are not WMA factors.
 */

namespace {
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) {
            return $GLOBALS['pausatf_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('do_action')) {
        function do_action($hook, ...$args) {
            $GLOBALS['pausatf_test_actions'][] = [$hook, ...$args];
        }
    }
    if (!function_exists('update_option')) {
        function update_option($name, $value, $autoload = null) {
            $GLOBALS['pausatf_test_options'][$name] = $value;
            return true;
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\AgeGrading;
    use PAUSATF\Results\PerformanceTracker;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-age-grading.php';
    require_once __DIR__ . '/../includes/class-performance-tracker.php';

    class AgeGradingTest extends TestCase {
        private const FIXTURE = [
            'edition' => 'test fixture',
            'source' => 'synthetic numbers for unit tests',
            'events' => [
                '5K' => [
                    'M' => ['standard_seconds' => 800, 'factors' => ['17' => 1.0, '30' => 1.0, '50' => 0.8]],
                    'F' => ['standard_seconds' => 900, 'factors' => ['50' => 0.9]],
                ],
            ],
        ];

        protected function setUp(): void {
            AgeGrading::clear_cache();
        }

        protected function tearDown(): void {
            unset($GLOBALS['pausatf_test_options'], $GLOBALS['pausatf_test_actions']);
            AgeGrading::clear_cache();
        }

        public function test_nothing_is_graded_without_a_table(): void {
            self::assertFalse(AgeGrading::has_factors());
            self::assertNull(AgeGrading::grade('5K', 1000, 50, 'M'));
            self::assertNull((new PerformanceTracker())->calculate_age_grade(1000, '5K', 50, 'M'));
            self::assertSame(["ranking_type <> 'age_graded'", []], AgeGrading::rankings_where());
        }

        public function test_percentage_uses_the_factor(): void {
            $grade = AgeGrading::grade('5K', 1000, 50, 'M', AgeGrading::validate(self::FIXTURE));

            // 800 / (1000 x 0.8) = 100%. The old code ignored the factor and gave 80%.
            self::assertSame(100.0, $grade['percentage']);
            self::assertSame(800.0, $grade['age_graded_time']);
            self::assertSame('test fixture', $grade['edition']);
            self::assertSame(80.0, AgeGrading::grade('5K', 1000, 30, 'M', AgeGrading::validate(self::FIXTURE))['percentage']);
        }

        public function test_minors_unknown_sex_and_missing_ages_are_not_graded(): void {
            $table = AgeGrading::validate(self::FIXTURE);

            self::assertNull(AgeGrading::grade('5K', 1000, 17, 'M', $table));
            self::assertNull(AgeGrading::grade('5K', 1000, 50, 'X', $table));
            self::assertNull(AgeGrading::grade('5K', 1000, 51, 'M', $table));
            self::assertNull(AgeGrading::grade('10K', 2000, 50, 'M', $table));
        }

        public function test_import_validates_and_stores_the_edition(): void {
            $path = tempnam(sys_get_temp_dir(), 'ag');
            file_put_contents($path, json_encode(self::FIXTURE));
            try {
                self::assertSame(['edition' => 'test fixture', 'events' => 1], AgeGrading::import_file($path));
            } finally {
                unlink($path);
            }

            self::assertTrue(AgeGrading::has_factors());
            self::assertSame('test fixture', AgeGrading::edition());
            self::assertContains(['pausatf_results_age_grading_imported', 'test fixture'], $GLOBALS['pausatf_test_actions'] ?? []);
            self::assertSame(80.0, (new PerformanceTracker())->calculate_age_grade(1250, '5K', 50, 'F'));
        }

        /** @return array<string, array{array<string, mixed>}> */
        public static function badTables(): array {
            $events = self::FIXTURE['events'];
            return [
                'no edition' => [['source' => 's', 'events' => $events]],
                'no source' => [['edition' => 'e', 'events' => $events]],
                'no events' => [['edition' => 'e', 'source' => 's', 'events' => []]],
                'factor above 1' => [['edition' => 'e', 'source' => 's', 'events' => ['5K' => ['M' => ['standard_seconds' => 800, 'factors' => ['40' => 1.2]]]]]],
                'zero standard' => [['edition' => 'e', 'source' => 's', 'events' => ['5K' => ['M' => ['standard_seconds' => 0, 'factors' => ['40' => 0.9]]]]]],
                'no sex' => [['edition' => 'e', 'source' => 's', 'events' => ['5K' => ['X' => []]]]],
            ];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('badTables')]
        public function test_invalid_tables_are_rejected(array $table): void {
            $this->expectException(\InvalidArgumentException::class);
            AgeGrading::validate($table);
        }

        public function test_a_corrupt_stored_table_counts_as_none(): void {
            $GLOBALS['pausatf_test_options'][AgeGrading::OPTION] = ['edition' => 'x'];
            self::assertFalse(AgeGrading::has_factors());
        }
    }
}
