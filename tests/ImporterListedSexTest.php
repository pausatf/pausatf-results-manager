<?php
/**
 * Every importer stores sex as listed in sex_listed and its scoring category in sex,
 * through ListedSex. A value other than M or F (or W) is kept and never scored as M.
 */

namespace {
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }
    if (!function_exists('do_action')) {
        function do_action($hook, ...$args) {
            $GLOBALS['pausatf_test_actions'][] = [$hook, ...$args];
        }
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
    if (!function_exists('get_post_meta')) {
        function get_post_meta($post_id, $key = '', $single = false) {
            return $GLOBALS['pausatf_test_post_meta'][$post_id][$key] ?? '';
        }
    }
    if (!function_exists('update_post_meta')) {
        function update_post_meta($post_id, $key, $value) {
            $GLOBALS['pausatf_test_post_meta'][$post_id][$key] = $value;
            return true;
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
    if (!function_exists('wp_insert_post')) {
        // Each new post gets the next ID from 100, so tests can tell events apart.
        function wp_insert_post($args, $wp_error = false) {
            $GLOBALS['pausatf_test_inserted_posts'][] = $args;
            return 99 + count($GLOBALS['pausatf_test_inserted_posts']);
        }
    }
    if (!function_exists('wp_update_post')) {
        function wp_update_post($args, $wp_error = false) {
            return (int) ($args['ID'] ?? 0);
        }
    }
    if (!function_exists('get_post')) {
        function get_post($post_id) {
            return null;
        }
    }
    if (!function_exists('get_posts')) {
        function get_posts($args = []) {
            return [];
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            return $thing instanceof \WP_Error;
        }
    }
    if (!function_exists('wp_set_object_terms')) {
        function wp_set_object_terms($object_id, $terms, $taxonomy) {
            return [];
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\CSVImporter;
    use PAUSATF\Results\Integrations\AthlinksIntegration;
    use PAUSATF\Results\Integrations\ChronoTrackClient;
    use PAUSATF\Results\Integrations\HyTekImporter;
    use PAUSATF\Results\Integrations\MYLAPSClient;
    use PAUSATF\Results\Integrations\RunSignUpIntegration;
    use PAUSATF\Results\Integrations\UltraSignupImport;
    use PAUSATF\Results\Integrations\WebscorerClient;
    use PAUSATF\Results\ListedSex;
    use PAUSATF\Results\ResultsImporter;
    use PAUSATF\Results\Rules\USATFAgeDivisions;
    use PHPUnit\Framework\TestCase;

    $root = dirname(__DIR__);
    foreach ([
        'includes/class-event-date.php', 'includes/class-listed-sex.php', 'includes/class-placement-time.php',
        'includes/parsers/class-parser-detector.php', 'includes/parsers/class-parser-table.php',
        'includes/parsers/class-parser-pre.php', 'includes/parsers/class-parser-word.php',
        'includes/class-results-importer.php', 'includes/class-csv-importer.php',
        'includes/integrations/class-hytek-importer.php', 'includes/integrations/class-runsignup-integration.php',
        'includes/integrations/class-athlinks-integration.php', 'includes/integrations/class-timing-systems.php',
        'includes/integrations/class-ultrasignup-import.php', 'includes/rules/class-usatf-age-divisions.php',
    ] as $file) {
        require_once $root . '/' . $file;
    }

    final class ImporterListedSexTest extends TestCase {
        /** Listed value => expected scoring category. */
        private const LISTED = ['W' => 'F', 'm' => 'M', 'Non-binary' => null, 'X' => null, 'NB' => null, 'U' => null];

        private mixed $previous_wpdb = null;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                public string $posts = 'wp_posts';
                public string $postmeta = 'wp_postmeta';
                public string $last_error = '';
                public int $insert_id = 1;
                /** @var list<array<string, mixed>> */
                public array $rows = [];
                public function prepare(string $sql, ...$args): string {
                    return $sql;
                }
                public function get_var(string $sql) {
                    return null;
                }
                public function delete(string $table, array $where): int {
                    return 0;
                }
                public function insert(string $table, array $data, $formats = null): int {
                    if ($table === 'wp_pausatf_results') {
                        $this->rows[] = $data;
                    }
                    return 1;
                }
            };
            $GLOBALS['pausatf_test_options'][ListedSex::SCHEMA_OPTION] = ListedSex::SCHEMA_VERSION;
            $GLOBALS['pausatf_test_inserted_posts'] = [];
            $GLOBALS['pausatf_test_post_meta'] = [];
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset($GLOBALS['pausatf_test_options'], $GLOBALS['pausatf_test_inserted_posts'], $GLOBALS['pausatf_test_post_meta'],
                $GLOBALS['pausatf_test_actions']);
        }

        /** Stored [sex_listed => sex] for every row written, in order. */
        private function stored(): array {
            $stored = [];
            foreach ($GLOBALS['wpdb']->rows as $row) {
                self::assertArrayHasKey('sex_listed', $row);
                $stored[] = [$row['sex_listed'], $row['sex']];
            }
            return $stored;
        }

        /** @return list<array{0: string, 1: ?string}> */
        private static function expected(): array {
            return array_map(null, array_map('strval', array_keys(self::LISTED)), array_values(self::LISTED));
        }

        public function test_html_import_keeps_the_listed_sex(): void {
            $rows = '';
            foreach (array_keys(self::LISTED) as $i => $sex) {
                $rows .= '<tr><td>' . ($i + 1) . '</td><td>Runner ' . ($i + 1) . '</td><td>' . $sex . '</td><td>17:0' . $i . '</td></tr>';
            }
            $html = '<html><body><h1>Spring 5K</h1><table><tr><th>Place</th><th>Name</th><th>Sex</th><th>Time</th></tr>'
                . $rows . '</table></body></html>';

            $result = (new ResultsImporter())->import_from_html($html);

            self::assertTrue($result['success']);
            self::assertSame(self::expected(), $this->stored());
        }

        public function test_csv_import_maps_sex_and_keeps_the_listed_value(): void {
            $csv = "Place,Name,Sex,Time\n";
            foreach (array_keys(self::LISTED) as $i => $sex) {
                $csv .= ($i + 1) . ",Runner " . ($i + 1) . ",{$sex},17:0{$i}\n";
            }
            $file = tempnam(sys_get_temp_dir(), 'pausatf-csv');
            file_put_contents($file, $csv);
            try {
                (new CSVImporter())->import_from_file($file, ['event_id' => 55]);
            } finally {
                unlink($file);
            }

            self::assertSame(self::expected(), $this->stored());
        }

        public function test_hytek_import_keeps_the_athlete_sex_code(): void {
            $line = static fn(array $fields): string => implode('', array_map(
                static fn(array $field): string => str_pad($field[0], $field[1]),
                $fields
            ));
            $lines = [];
            foreach (['F' => 'F', 'X' => null, 'M' => 'M'] as $code => $category) {
                $lines[] = $line([['D1', 2], ["A{$code}", 12], ['Runner', 20], [$code, 20], ['PAC', 6], [$code, 1], ['01011990', 8], ['36', 3]]);
                $lines[] = $line([['D3', 2], ["A{$code}", 12], ['1500', 4], ['4:30.00', 8], ['1', 4]]);
            }
            $file = tempnam(sys_get_temp_dir(), 'pausatf-hy3');
            file_put_contents($file, implode("\n", $lines));
            try {
                (new HyTekImporter())->import($file, ['event_id' => 56]);
            } finally {
                unlink($file);
            }

            self::assertSame([['F', 'F'], ['X', null], ['M', 'M']], $this->stored());
        }

        public function test_runsignup_import_keeps_the_listed_sex(): void {
            $client = self::getStubBuilder(RunSignUpIntegration::class)->onlyMethods(['is_configured', 'get_race', 'get_results'])->getStub();
            $client->method('is_configured')->willReturn(true);
            $client->method('get_race')->willReturn(['name' => 'Spring 5K']);
            $client->method('get_results')->willReturn(array_map(
                static fn(string $sex): array => ['user' => ['first_name' => 'Pat', 'last_name' => 'Runner', 'gender' => $sex], 'place' => 1, 'clock_time' => '17:00'],
                array_map('strval', array_keys(self::LISTED))
            ));

            $client->import_race_results(9);

            self::assertSame(self::expected(), $this->stored());
        }

        public function test_athlinks_sync_keeps_the_listed_sex(): void {
            $GLOBALS['pausatf_test_options']['pausatf_athlinks_api_key'] = 'key';
            $client = self::getStubBuilder(AthlinksIntegration::class)->onlyMethods(['get_athlete_results'])->getStub();
            $results = [];
            foreach (array_keys(self::LISTED) as $i => $sex) {
                $results[] = ['resultId' => $i + 1, 'raceId' => 10, 'raceName' => 'Spring 5K', 'gunTime' => 1020000, 'gender' => (string) $sex];
            }
            $client->method('get_athlete_results')->willReturn($results);

            $client->sync_athlete_results(5, 77);

            self::assertSame(self::expected(), $this->stored());
        }

        public function test_timing_system_imports_keep_the_listed_sex(): void {
            foreach ([
                ChronoTrackClient::class => static fn(string $sex): array => ['firstName' => 'Pat', 'lastName' => 'Runner', 'gunTimeSeconds' => 1020, 'gender' => $sex],
                MYLAPSClient::class => static fn(string $sex): array => ['participantName' => 'Pat Runner', 'finishTimeMs' => 1020000, 'gender' => $sex],
                WebscorerClient::class => static fn(string $sex): array => ['name' => 'Pat Runner', 'time' => '17:00', 'gender' => $sex],
            ] as $class => $row) {
                $GLOBALS['wpdb']->rows = [];
                $client = self::getStubBuilder($class)->onlyMethods(['get_race', 'get_results'])->getStub();
                $client->method('get_race')->willReturn(['name' => 'Spring 5K']);
                $client->method('get_results')->willReturn(array_map($row, array_map('strval', array_keys(self::LISTED))));

                $client->import_race('r1');

                self::assertSame(self::expected(), $this->stored(), $class);
            }
        }

        public function test_ultrasignup_import_keeps_the_listed_sex(): void {
            $client = self::getStubBuilder(UltraSignupImport::class)->onlyMethods(['get_results'])->getStub();
            $client->method('get_results')->willReturn(['event' => ['name' => 'Trail 50K'], 'results' => array_map(
                static fn(string $sex): array => ['athlete_name' => 'Pat Runner', 'athlete_age' => 30, 'place' => 1, 'time_seconds' => 18000,
                    'time_display' => '5:00:00', 'division' => null, 'division_place' => 0, 'gender' => $sex],
                array_map('strval', array_keys(self::LISTED))
            )]);

            $client->import_race(9);

            self::assertSame(self::expected(), $this->stored());
        }

        public function test_fixed_width_parser_keeps_the_printed_sex_letter(): void {
            $html = "<html><body><pre>\nPacific Association/USATF 5K Championship\nApril 24, 1996\n\n"
                . "Place   Name                    Div      Time\n"
                . "    1. Ann Runner               w40-44   17:13\n"
                . "    2. Bob Runner               M40-44   17:20\n"
                . "</pre></body></html>";

            $results = (new \PAUSATF\Results\Parsers\ParserPre())->parse($html)->results;

            self::assertSame(['w', 'M'], array_column($results, 'sex'));
            self::assertSame('F', ListedSex::category($results[0]['sex']));
        }

        public function test_no_unknown_value_is_scored_as_men(): void {
            foreach (['X', 'NB', 'Non-binary', 'U', 'Mixed', '', ' '] as $value) {
                self::assertNull(ListedSex::columns($value)['sex'], var_export($value, true));
            }
        }

        public function test_scoring_sex_accepts_the_same_forms_as_listed_sex(): void {
            foreach (['M', 'm', 'Male', 'MEN', 'boys', 'F', 'W', 'w', 'Female', 'women', 'Girl', 'X', 'NB', 'Non-binary', '', null] as $value) {
                self::assertSame(ListedSex::category($value), USATFAgeDivisions::scoring_sex($value), var_export($value, true));
            }
            self::assertSame('F', USATFAgeDivisions::scoring_sex('Women'));
            self::assertSame('M', USATFAgeDivisions::get_division_for_age(30, 'Male', 2026)['gender']);
        }
    }
}
