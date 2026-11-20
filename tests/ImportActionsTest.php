<?php
/**
 * Every importer fires pausatf_results_imported once per event it commits, Grand Prix
 * recomputes on it, and claims and ranking regeneration fire their webhook sources.
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
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data) {
            return json_encode($data);
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
    if (!function_exists('get_post_status')) {
        function get_post_status($post = null) {
            return 'publish';
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
    if (!function_exists('update_user_meta')) {
        function update_user_meta($user_id, $key, $value) {
            return true;
        }
    }
    if (!function_exists('get_the_title')) {
        function get_the_title($post_id = 0) {
            return '';
        }
    }
    if (!function_exists('get_user_by')) {
        function get_user_by($field, $value) {
            return false;
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\AthleteClaim;
    use PAUSATF\Results\CanonicalEventService;
    use PAUSATF\Results\CSVImporter;
    use PAUSATF\Results\GrandPrix;
    use PAUSATF\Results\GrandPrixScoring;
    use PAUSATF\Results\Integrations\AthlinksIntegration;
    use PAUSATF\Results\Integrations\ChronoTrackClient;
    use PAUSATF\Results\Integrations\HyTekImporter;
    use PAUSATF\Results\Integrations\MYLAPSClient;
    use PAUSATF\Results\Integrations\RunSignUpIntegration;
    use PAUSATF\Results\Integrations\UltraSignupImport;
    use PAUSATF\Results\Integrations\WebscorerClient;
    use PAUSATF\Results\RankingSystem;
    use PAUSATF\Results\ResultsImporter;
    use PAUSATF\Results\Webhooks;
    use PHPUnit\Framework\TestCase;

    /** wpdb double: stores result rows per event and answers the Grand Prix recompute from them. */
    final class ImportActionsWpdb {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        public int $insert_id = 1;
        /** @var array<int, list<array<string, mixed>>> */
        public array $results = [];
        /** @var list<array<string, mixed>> */
        public array $standings = [];
        /** @var list<int> Series containing every event, for update_standings_for_event(). */
        public array $series_ids = [];

        public function prepare(string $sql, ...$args): string {
            return $sql;
        }
        public function query(string $sql) {
            return true;
        }
        public function get_var(string $sql) {
            return null;
        }
        public function get_col(string $sql): array {
            return str_contains($sql, 'grand_prix_events') ? $this->series_ids : [];
        }
        public function get_row(string $sql, $output = null): ?array {
            if (!str_contains($sql, 'grand_prix_series')) {
                return null;
            }
            return ['id' => 7, 'place_points' => json_encode(GrandPrixScoring::PA_ROAD_POINTS_A), 'bonus_points' => '{}',
                'divisions' => json_encode(['Open']), 'min_races' => 0, 'max_races' => null, 'drop_worst' => 0];
        }
        public function get_results(string $sql, $output = null): array {
            if (str_contains($sql, 'grand_prix_events')) {
                return array_map(static fn(int $id): array => ['event_id' => $id, 'multiplier' => 1.0,
                    'event_name' => "Event {$id}", 'event_date' => '2026-03-01'], array_keys($this->results));
            }
            if (str_contains($sql, 'pausatf_results')) {
                $rows = [];
                foreach ($this->results as $event_id => $event_rows) {
                    foreach ($event_rows as $row) {
                        $rows[] = $row + ['athlete_id' => null, 'sex' => null, 'event_name' => "Event {$event_id}"];
                    }
                }
                return $rows;
            }
            return [];
        }
        public function delete(string $table, array $where): int {
            if ($table === 'wp_pausatf_results' && isset($where['event_id'])) {
                unset($this->results[(int) $where['event_id']]);
            }
            return 0;
        }
        public function insert(string $table, array $data, $formats = null): int {
            if ($table === 'wp_pausatf_results') {
                $this->results[(int) $data['event_id']][] = $data;
            } elseif ($table === 'wp_pausatf_grand_prix_standings') {
                $this->standings[] = $data;
            }
            return 1;
        }
        public function update(string $table, array $data, array $where, $format = null, $where_format = null): int {
            return 1;
        }
        public function get_charset_collate(): string {
            return '';
        }
    }

    final class ImportActionsTest extends TestCase {
        private const FILES = [
        'includes/class-event-date.php', 'includes/class-listed-sex.php', 'includes/class-placement-time.php',
        'includes/parsers/class-parser-detector.php', 'includes/parsers/class-parser-table.php',
        'includes/parsers/class-parser-pre.php', 'includes/parsers/class-parser-word.php',
        'includes/class-results-importer.php', 'includes/storage/class-results-repository.php',
        'includes/class-canonical-event-service.php', 'includes/class-csv-importer.php',
        'includes/integrations/class-hytek-importer.php', 'includes/integrations/class-runsignup-integration.php',
        'includes/integrations/class-athlinks-integration.php', 'includes/integrations/class-timing-systems.php',
        'includes/integrations/class-ultrasignup-import.php', 'includes/class-grand-prix.php',
        'includes/class-athlete-claim.php', 'includes/class-age-grading.php', 'includes/rules/class-usatf-rules-engine.php',
        'includes/rules/class-usatf-age-divisions.php', 'includes/class-ranking-system.php', 'includes/class-webhooks.php',
    ];

        private mixed $previous_wpdb = null;
        private ImportActionsWpdb $db;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            $this->db = new ImportActionsWpdb();
            $GLOBALS['wpdb'] = $this->db;
            // GrandPrix instantiates itself on load, so load after $wpdb is in place.
            foreach (self::FILES as $file) {
                require_once dirname(__DIR__) . '/' . $file;
            }
            $GLOBALS['pausatf_test_actions'] = [];
            $GLOBALS['pausatf_test_hooks'] = [];
            $GLOBALS['pausatf_test_inserted_posts'] = [];
            $GLOBALS['pausatf_test_post_meta'] = [];
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset($GLOBALS['pausatf_test_actions'], $GLOBALS['pausatf_test_hooks'], $GLOBALS['pausatf_test_inserted_posts'],
                $GLOBALS['pausatf_test_post_meta'], $GLOBALS['pausatf_test_options']);
        }

        /** @return list<list<mixed>> Arguments of each call to $hook, in order. */
        private function fired(string $hook): array {
            $calls = [];
            foreach ($GLOBALS['pausatf_test_actions'] as $call) {
                if ($call[0] === $hook) {
                    $calls[] = array_slice($call, 1);
                }
            }
            return $calls;
        }

        /** Run the listeners recorded by add_action() for every action fired so far, as WordPress would. */
        private function dispatch(): void {
            foreach ($GLOBALS['pausatf_test_actions'] as $call) {
                foreach ($GLOBALS['pausatf_test_hooks'][$call[0]] ?? [] as [$callback, $accepted]) {
                    $callback(...array_slice($call, 1, $accepted));
                }
            }
        }

        private function table_html(): string {
            return '<html><body><h1>Spring 5K</h1><table><tr><th>Place</th><th>Name</th><th>Time</th></tr>'
                . '<tr><td>1</td><td>Ann Runner</td><td>17:00</td></tr>'
                . '<tr><td>2</td><td>Bea Runner</td><td>17:30</td></tr></table></body></html>';
        }

        public function test_html_import_recomputes_grand_prix_standings(): void {
            $grand_prix = new GrandPrix();
            $this->db->series_ids = [7];

            $result = (new ResultsImporter())->import_from_html($this->table_html(), ['source_url' => 'https://example.test/a.html']);

            self::assertTrue($result['success']);
            self::assertSame([[$result['event_id'], 2]], $this->fired('pausatf_results_imported'));
            self::assertSame([], $this->db->standings);

            $this->dispatch();

            self::assertSame(['Ann Runner', 'Bea Runner'], array_column($this->db->standings, 'athlete_name'));
            self::assertSame(100.0, (float) $this->db->standings[0]['total_points']);
        }

        public function test_failed_parse_fires_nothing(): void {
            (new ResultsImporter())->import_from_html('<html><body><p>No results here.</p></body></html>');

            self::assertSame([], $this->fired('pausatf_results_imported'));
        }

        public function test_canonical_import_fires_once_after_the_commit(): void {
            $result = (new CanonicalEventService())->import([
                'event_uid' => 'pa-2026-spring-5k',
                'name' => 'Spring 5K',
                'results' => [['athlete_name' => 'Ann Runner', 'place' => 1], ['athlete_name' => 'Bea Runner', 'place' => 2]],
            ]);

            self::assertSame([[$result['event_id'], 2]], $this->fired('pausatf_results_imported'));
        }

        public function test_csv_import_fires_once(): void {
            $file = tempnam(sys_get_temp_dir(), 'pausatf-csv');
            file_put_contents($file, "Place,Name,Time\n1,Ann Runner,17:00\n2,Bea Runner,17:30\n3,Cy Runner,18:00\n");
            try {
                (new CSVImporter())->import_from_file($file, ['event_id' => 55]);
            } finally {
                unlink($file);
            }

            self::assertSame([[55, 3]], $this->fired('pausatf_results_imported'));
        }

        public function test_hytek_import_fires_once(): void {
            $line = static fn(array $fields): string => implode('', array_map(
                static fn(array $field): string => str_pad($field[0], $field[1]),
                $fields
            ));
            $file = tempnam(sys_get_temp_dir(), 'pausatf-hy3');
            file_put_contents($file, implode("\n", [
                $line([['D1', 2], ['A1', 12], ['Runner', 20], ['Ann', 20], ['PAC', 6], ['F', 1], ['01011990', 8], ['36', 3]]),
                $line([['D3', 2], ['A1', 12], ['1500', 4], ['4:30.00', 8], ['1', 4]]),
            ]));
            try {
                (new HyTekImporter())->import($file, ['event_id' => 56]);
            } finally {
                unlink($file);
            }

            self::assertSame([[56, 1]], $this->fired('pausatf_results_imported'));
        }

        public function test_runsignup_import_fires_once(): void {
            $GLOBALS['pausatf_test_options']['pausatf_runsignup_api_key'] = 'key';
            $GLOBALS['pausatf_test_options']['pausatf_runsignup_api_secret'] = 'secret';
            $client = self::getStubBuilder(RunSignUpIntegration::class)->onlyMethods(['is_configured', 'get_race', 'get_results'])->getStub();
            $client->method('is_configured')->willReturn(true);
            $client->method('get_race')->willReturn(['name' => 'Spring 5K']);
            $client->method('get_results')->willReturn([
                ['user' => ['first_name' => 'Ann', 'last_name' => 'Runner'], 'place' => 1, 'clock_time' => '17:00'],
                ['user' => ['first_name' => 'Bea', 'last_name' => 'Runner'], 'place' => 2, 'clock_time' => '17:30'],
            ]);

            $result = $client->import_race_results(9);

            self::assertSame([[$result['event_id'], 2]], $this->fired('pausatf_results_imported'));
        }

        public function test_ultrasignup_import_fires_once(): void {
            $client = self::getStubBuilder(UltraSignupImport::class)->onlyMethods(['get_results'])->getStub();
            $client->method('get_results')->willReturn(['event' => ['name' => 'Trail 50K'], 'results' => [
                ['athlete_name' => 'Ann Runner', 'athlete_age' => 30, 'place' => 1, 'time_seconds' => 18000,
                    'time_display' => '5:00:00', 'division' => 'F30-39', 'division_place' => 1, 'gender' => 'F'],
            ]]);

            $result = $client->import_race(9);

            self::assertSame([[$result['event_id'], 1]], $this->fired('pausatf_results_imported'));
        }

        public function test_timing_system_imports_fire_once_each(): void {
            $race = ['name' => 'Spring 5K'];
            foreach ([
                [ChronoTrackClient::class, [['firstName' => 'Ann', 'lastName' => 'Runner', 'gunTimeSeconds' => 1020]]],
                [MYLAPSClient::class, [['participantName' => 'Ann Runner', 'finishTimeMs' => 1020000]]],
                [WebscorerClient::class, [['name' => 'Ann Runner', 'time' => '17:00']]],
            ] as [$class, $rows]) {
                $GLOBALS['pausatf_test_actions'] = [];
                $client = self::getStubBuilder($class)->onlyMethods(['get_race', 'get_results'])->getStub();
                $client->method('get_race')->willReturn($race);
                $client->method('get_results')->willReturn($rows);

                $result = $client->import_race('r1');

                self::assertSame([[$result['event_id'], 1]], $this->fired('pausatf_results_imported'), $class);
            }
        }

        public function test_athlinks_sync_fires_once_per_event_touched(): void {
            $GLOBALS['pausatf_test_options']['pausatf_athlinks_api_key'] = 'key';
            $client = self::getStubBuilder(AthlinksIntegration::class)->onlyMethods(['get_athlete_results'])->getStub();
            $client->method('get_athlete_results')->willReturn([
                ['resultId' => 1, 'raceId' => 10, 'raceName' => 'Spring 5K', 'gunTime' => 1020000],
                ['resultId' => 2, 'raceId' => 11, 'raceName' => 'Fall 10K', 'gunTime' => 2100000],
            ]);

            $client->sync_athlete_results(5, 77);

            self::assertSame([[100, 1], [101, 1]], $this->fired('pausatf_results_imported'));
        }

        public function test_webhooks_and_grand_prix_listen_for_the_action_importers_fire(): void {
            $webhooks = new Webhooks();
            $grand_prix = new GrandPrix();
            $listeners = array_column($GLOBALS['pausatf_test_hooks']['pausatf_results_imported'] ?? [], 0);

            self::assertContains([$webhooks, 'on_results_imported'], $listeners);
            self::assertContains([$grand_prix, 'update_standings_for_event'], $listeners);
            self::assertContains([$webhooks, 'on_athlete_claimed'], array_column($GLOBALS['pausatf_test_hooks']['pausatf_athlete_claimed'], 0));
            self::assertContains([$webhooks, 'on_rankings_updated'], array_column($GLOBALS['pausatf_test_hooks']['pausatf_rankings_updated'], 0));
        }

        public function test_approving_a_claim_fires_athlete_claimed(): void {
            $GLOBALS['pausatf_test_post_meta'][900] = ['_pausatf_user_id' => 3, '_pausatf_athlete_id' => 40];

            $result = AthleteClaim::instance()->approve_claim(900);

            self::assertTrue($result['success']);
            self::assertSame([[40, 3]], $this->fired('pausatf_athlete_claimed'));
        }

        public function test_an_invalid_claim_fires_nothing(): void {
            AthleteClaim::instance()->approve_claim(901);

            self::assertSame([], $this->fired('pausatf_athlete_claimed'));
        }

        public function test_regenerating_rankings_fires_once_per_event(): void {
            (new RankingSystem())->regenerate_all(2026);

            self::assertSame(
                [['5K', 0], ['10K', 0], ['15K', 0], ['10 Miles', 0], ['Half Marathon', 0], ['Marathon', 0]],
                $this->fired('pausatf_rankings_updated')
            );
        }
    }
}
