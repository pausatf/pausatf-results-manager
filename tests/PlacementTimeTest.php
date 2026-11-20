<?php
/**
 * Placement time basis: 2026 USATF Competition Rules, Rules 165.4, 165.11(c) and 165.16 (p. 72-76).
 */

namespace {
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
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\Integrations\ChronoTrackClient;
    use PAUSATF\Results\Integrations\RunSignUpIntegration;
    use PAUSATF\Results\PlacementTime;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/class-placement-time.php';
    require_once __DIR__ . '/../includes/integrations/class-timing-systems.php';
    require_once __DIR__ . '/../includes/integrations/class-runsignup-integration.php';

    class PlacementTimeTest extends TestCase {
        private mixed $previous_wpdb = null;

        protected function setUp(): void {
            $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
                /** @var list<array<string, mixed>> */
                public array $rows = [];
                public function insert(string $table, array $data): int {
                    $this->rows[] = $data;
                    return 1;
                }
            };
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_wpdb;
            unset(
                $GLOBALS['pausatf_test_post_meta'],
                $GLOBALS['pausatf_test_filters']['pausatf_results_placement_time'],
                $GLOBALS['pausatf_test_filters']['pausatf_results_placement_time_combination']
            );
        }

        public function test_event_that_states_nothing_places_by_gun_time(): void {
            self::assertSame(PlacementTime::GUN, PlacementTime::basis(10));
            self::assertSame([1805.2, PlacementTime::GUN], PlacementTime::choose(1805.2, 1790.4, PlacementTime::basis(10)));
        }

        public function test_event_can_state_net_time(): void {
            $GLOBALS['pausatf_test_post_meta'][11][PlacementTime::META] = 'Net';

            self::assertSame(PlacementTime::NET, PlacementTime::basis(11));
            self::assertSame([1790.4, PlacementTime::NET], PlacementTime::choose(1805.2, 1790.4, PlacementTime::NET));
        }

        public function test_unknown_basis_falls_back_to_gun_and_filter_can_set_it(): void {
            $GLOBALS['pausatf_test_post_meta'][12][PlacementTime::META] = 'chip-ish';
            self::assertSame(PlacementTime::GUN, PlacementTime::basis(12));

            $GLOBALS['pausatf_test_filters']['pausatf_results_placement_time'] = static fn($basis, $event_id) => $event_id === 12 ? 'net' : $basis;
            self::assertSame(PlacementTime::NET, PlacementTime::basis(12));
        }

        public function test_combination_is_resolved_by_the_event_filter(): void {
            self::assertSame([100.0, PlacementTime::GUN], PlacementTime::choose(100.0, 95.0, PlacementTime::COMBINATION));

            $GLOBALS['pausatf_test_filters']['pausatf_results_placement_time_combination'] =
                static fn($basis, $context) => ($context['overallPlace'] ?? 0) > 3 ? PlacementTime::NET : PlacementTime::GUN;
            self::assertSame([100.0, PlacementTime::GUN], PlacementTime::choose(100.0, 95.0, PlacementTime::COMBINATION, ['overallPlace' => 1]));
            self::assertSame([95.0, PlacementTime::NET], PlacementTime::choose(100.0, 95.0, PlacementTime::COMBINATION, ['overallPlace' => 9]));
        }

        public function test_missing_time_uses_the_other_and_says_so(): void {
            self::assertSame([95.0, PlacementTime::NET], PlacementTime::choose(null, 95.0, PlacementTime::GUN));
            self::assertSame([100.0, PlacementTime::GUN], PlacementTime::choose(100.0, 0.0, PlacementTime::NET));
            self::assertSame([null, null], PlacementTime::choose(null, null, PlacementTime::GUN));
        }

        public function test_official_time_rounds_up_to_the_next_whole_second(): void {
            self::assertSame(1801, PlacementTime::official_seconds(1800.01));
            self::assertSame(1800, PlacementTime::official_seconds(1800.0));
            self::assertSame(1800, PlacementTime::official_seconds(1800000 / 1000));
            self::assertNull(PlacementTime::official_seconds(null));
            self::assertNull(PlacementTime::official_seconds(-3.0));
        }

        public function test_chronotrack_import_stores_gun_time_by_default(): void {
            $rows = $this->import_chronotrack(20, [
                ['firstName' => 'A', 'lastName' => 'Runner', 'gunTimeSeconds' => 1805.2, 'gunTime' => '30:05.2', 'chipTimeSeconds' => 1790.4, 'chipTime' => '29:50.4'],
            ]);

            self::assertSame(1806, $rows[0]['time_seconds']);
            self::assertSame('30:05.2', $rows[0]['time_display']);
        }

        public function test_chronotrack_import_stores_net_time_when_the_event_states_it(): void {
            $GLOBALS['pausatf_test_post_meta'][21][PlacementTime::META] = 'net';
            $rows = $this->import_chronotrack(21, [
                ['firstName' => 'A', 'lastName' => 'Runner', 'gunTimeSeconds' => 1805.2, 'gunTime' => '30:05.2', 'chipTimeSeconds' => 1790.4, 'chipTime' => '29:50.4'],
            ]);

            self::assertSame(1791, $rows[0]['time_seconds']);
            self::assertSame('29:50.4', $rows[0]['time_display']);
        }

        public function test_runsignup_keeps_fractions_and_rounds_up(): void {
            $client = (new \ReflectionClass(RunSignUpIntegration::class))->newInstanceWithoutConstructor();
            $save = new \ReflectionMethod($client, 'save_results');
            $save->invoke($client, 30, [
                ['clock_time' => '1:02:03.4', 'chip_time' => '1:01:59.9', 'place' => 1, 'user' => ['first_name' => 'B', 'last_name' => 'Runner']],
            ]);

            self::assertSame(3724, $GLOBALS['wpdb']->rows[0]['time_seconds']);
            self::assertSame('1:02:03.4', $GLOBALS['wpdb']->rows[0]['time_display']);
        }

        /** @return list<array<string, mixed>> */
        private function import_chronotrack(int $event_id, array $results): array {
            $client = (new \ReflectionClass(ChronoTrackClient::class))->newInstanceWithoutConstructor();
            (new \ReflectionMethod($client, 'save_results'))->invoke($client, $event_id, $results);
            return $GLOBALS['wpdb']->rows;
        }
    }
}
