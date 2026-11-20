<?php
/**
 * Sex is stored as listed; only M and F (or W) get a scoring category.
 */

namespace {
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }
    if (!function_exists('get_option')) {
        function get_option($name, $default = false) {
            return $GLOBALS['pausatf_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type) {
            return '2026-01-01 00:00:00';
        }
    }
    if (!function_exists('remove_accents')) {
        function remove_accents($text) {
            return $text;
        }
    }
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags($text) {
            return strip_tags((string) $text);
        }
    }
    if (!function_exists('get_page_by_title')) {
        function get_page_by_title($title, $output = 'OBJECT', $post_type = 'page') {
            return null;
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\ListedSex;
    use PAUSATF\Results\Parsers\ParserTable;
    use PAUSATF\Results\Storage\ResultsRepository;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-listed-sex.php';
    require_once __DIR__ . '/../includes/parsers/class-parser-table.php';
    require_once __DIR__ . '/../includes/storage/class-results-repository.php';

    class ListedSexTest extends TestCase {
        private mixed $previous_wpdb = null;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $postmeta = 'wp_postmeta';
                public string $last_error = '';
                public int $insert_id = 1;
                /** @var list<array{0: array<string, mixed>, 1: list<string>}> */
                public array $inserts = [];
                public function insert(string $table, array $data, array $formats = []): int {
                    $this->inserts[] = [$data, $formats];
                    return 1;
                }
                public function prepare(string $sql, ...$args): string {
                    return $sql;
                }
                public function get_var(string $sql) {
                    return null;
                }
            };
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset($GLOBALS['pausatf_test_options']);
        }

        public function test_only_men_and_women_get_a_scoring_category(): void {
            foreach (['M', 'm', 'Male', 'Men'] as $value) {
                self::assertSame('M', ListedSex::category($value), $value);
            }
            foreach (['F', 'W', 'w', 'Female', 'Women'] as $value) {
                self::assertSame('F', ListedSex::category($value), $value);
            }
            foreach (['X', 'NB', 'Non-binary', 'U', '', null, 'Mixed'] as $value) {
                self::assertNull(ListedSex::category($value), var_export($value, true));
            }
        }

        public function test_listed_value_is_kept_as_written(): void {
            self::assertSame('Non-binary', ListedSex::listed('  Non-binary '));
            self::assertSame('W', ListedSex::listed('W'));
            self::assertNull(ListedSex::listed('   '));
            self::assertSame(ListedSex::MAX_LENGTH, mb_strlen((string) ListedSex::listed(str_repeat('x', 40))));
        }

        public function test_repository_stores_listed_value_and_category(): void {
            $GLOBALS['pausatf_test_options'][ListedSex::SCHEMA_OPTION] = ListedSex::SCHEMA_VERSION;

            [$w, $w_formats] = $this->insert(['athlete_name' => 'Ann Runner', 'sex' => 'W']);
            [$x] = $this->insert(['athlete_name' => 'Sam Runner', 'sex' => 'X']);

            self::assertSame('F', $w['sex']);
            self::assertSame('W', $w['sex_listed']);
            self::assertCount(count($w), $w_formats);
            self::assertSame('%s', $w_formats[array_search('sex_listed', array_keys($w), true)]);
            self::assertNull($x['sex']);
            self::assertSame('X', $x['sex_listed']);
        }

        public function test_repository_skips_the_column_until_the_schema_is_upgraded(): void {
            [$row, $formats] = $this->insert(['athlete_name' => 'Ann Runner', 'sex' => 'W']);

            self::assertArrayNotHasKey('sex_listed', $row);
            self::assertSame('F', $row['sex']);
            self::assertCount(count($row), $formats);
        }

        public function test_table_parser_keeps_the_listed_value(): void {
            $html = '<html><body><table><tr><th>Place</th><th>Name</th><th>Sex</th></tr>'
                . '<tr><td>1</td><td>Sam Runner</td><td>NB</td></tr>'
                . '<tr><td>2</td><td>Ann Runner</td><td>W</td></tr></table></body></html>';

            $results = (new ParserTable())->parse($html)->results;

            self::assertSame('NB', $results[0]['sex']);
            self::assertSame('W', $results[1]['sex']);
        }

        /** @return array{0: array<string, mixed>, 1: list<string>} */
        private function insert(array $result): array {
            $repo = new ResultsRepository();
            (new \ReflectionMethod($repo, 'insert_single_result'))->invoke($repo, 7, $result);
            return end($GLOBALS['wpdb']->inserts);
        }
    }
}
