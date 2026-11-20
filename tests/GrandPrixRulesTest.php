<?php
/**
 * 2026 PA Grand Prix standings: road, cross country and MUT, built from the rule statements.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\GrandPrixRules;
use PAUSATF\Results\GrandPrixStandings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-grand-prix-standings.php';

class GrandPrixRulesTest extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['pausatf_test_filters']['pausatf_results_gp_member_status'],
            $GLOBALS['pausatf_test_filters']['pausatf_results_gp_birth_date']
        );
    }

    /** @param array<string, mixed> $extra */
    private static function row(int $event, string $name, int $age, int $place, array $extra = []): array {
        return $extra + ['event_id' => $event, 'athlete_name' => $name, 'athlete_id' => null, 'sex' => 'M',
            'athlete_age' => $age, 'place' => $place, 'time_seconds' => 1000 + $place, 'club' => '', 'division' => ''];
    }

    /** @return list<array<string, mixed>> */
    private static function events(int $count, array $overrides = []): array {
        $events = [];
        for ($i = 1; $i <= $count; $i++) {
            $events[] = ($overrides[$i] ?? []) + ['event_id' => $i, 'multiplier' => 1.0, 'event_date' => sprintf('2026-%02d-01', $i), 'distance_m' => 10000];
        }
        return $events;
    }

    /** @return array<string, float> "type|sex|division|name" => total points */
    private static function totals(array $rows, string $entry_type = 'individual'): array {
        $out = [];
        foreach ($rows as $row) {
            if ($row['entry_type'] === $entry_type) {
                $out["{$row['standing_type']}|{$row['gender']}|{$row['division']}|{$row['name']}"] = $row['total_points'];
            }
        }
        return $out;
    }

    public function test_points_tables(): void {
        $points = GrandPrixRules::PA_2026_POINTS;
        // LDR 11.1 and XC section 1.
        self::assertCount(100, $points);
        self::assertSame([150, 135, 125, 120, 116, 113, 110, 108, 106, 104, 102, 100], array_slice($points, 0, 12));
        self::assertSame([78, 77, 76], array_slice($points, 22, 3));
        self::assertSame(51, $points[49]);
        self::assertSame(1, $points[99]);
        // MUT rule 3.
        self::assertSame([40, 31, 24, 19, 16, 14, 12, 11, 10, 9], array_slice(GrandPrixRules::MUT_POINTS, 0, 10));
        self::assertCount(18, GrandPrixRules::MUT_POINTS);
        // LDR 12.1.
        self::assertSame(10.0, GrandPrixRules::road_team_points(1));
        self::assertSame(2.0, GrandPrixRules::road_team_points(9));
        self::assertSame(1.0, GrandPrixRules::road_team_points(14));
        self::assertNull(GrandPrixRules::get('legacy'));
    }

    public function test_road_places_are_within_division_among_eligible_athletes(): void {
        $GLOBALS['pausatf_test_filters']['pausatf_results_gp_member_status'] = static fn($status, $row) => $row['athlete_name'] === 'Visitor' ? false : null;
        $results = [
            self::row(1, 'P1', 25, 1),
            self::row(1, 'P2', 45, 2),
            self::row(1, 'P3', 30, 3),
            self::row(1, 'Junior', 15, 4),
            self::row(1, 'Visitor', 28, 5),
            self::row(1, 'P6', 33, 6),
            self::row(1, 'Unlisted', 35, 7, ['sex' => '']),
        ];
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::ROAD), self::events(1), $results, 2026));

        // Division standings leave out older divisions (LDR 10.0 (1)).
        self::assertSame(150.0, $totals['division|M|OPEN|P1']);
        self::assertSame(135.0, $totals['division|M|OPEN|P3']);
        self::assertSame(125.0, $totals['division|M|OPEN|P6']);
        self::assertSame(150.0, $totals['division|M|40|P2']);
        // Age+ standings include everyone at or above the age (LDR 10.0 (2)).
        self::assertSame(135.0, $totals['age_plus|M|OPEN|P2']);
        self::assertSame(120.0, $totals['age_plus|M|OPEN|P6']);
        self::assertSame(150.0, $totals['age_plus|M|40+|P2']);
        // Under 16, non-members and unlisted sex are not scored and take no place.
        foreach (array_keys($totals) as $key) {
            self::assertStringNotContainsString('Junior', $key);
            self::assertStringNotContainsString('Visitor', $key);
            self::assertStringNotContainsString('Unlisted', $key);
        }
    }

    public function test_scoring_value_multiplies_points_and_unknown_membership_is_unverified(): void {
        $rows = GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::ROAD),
            self::events(1, [1 => ['multiplier' => 1.5]]), [self::row(1, 'P1', 25, 1)], 2026);
        $row = $rows[0];
        self::assertSame(225.0, $row['total_points']);
        self::assertFalse($row['verified'], 'No roster data: eligible but unverified.');
    }

    public function test_decade_carry_over(): void {
        $rules = GrandPrixRules::get(GrandPrixRules::ROAD);
        $results = [
            self::row(1, 'Pat', 49, 1),
            self::row(2, 'Pat', 50, 1),
            self::row(2, 'Quinn', 50, 2),
        ];
        $totals = self::totals(GrandPrixStandings::compute($rules, self::events(2), $results, 2026));
        // Pat raced at 49 earlier in the year, so stays scored with the 40s and also scores in the 50s.
        self::assertSame(300.0, $totals['division|M|40|Pat']);
        self::assertSame(150.0, $totals['division|M|50|Pat']);
        self::assertSame(135.0, $totals['division|M|50|Quinn']);
        self::assertArrayNotHasKey('division|M|40|Quinn', $totals);

        // With a birth date showing Quinn turned 50 this year, Quinn is carried too.
        $GLOBALS['pausatf_test_filters']['pausatf_results_gp_birth_date'] = static fn($date, $key, $row) => $row['athlete_name'] === 'Quinn' ? '1976-07-01' : null;
        $totals = self::totals(GrandPrixStandings::compute($rules, self::events(2), $results, 2026));
        self::assertSame(135.0, $totals['division|M|40|Quinn']);

        // MUT scores race-day age only (rule 8) and has a 30-39 division.
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::MUT), self::events(2), $results, 2026));
        self::assertSame(40.0, $totals['division|M|40|Pat']);
        self::assertSame(40.0, $totals['division|M|50|Pat']);
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::MUT), self::events(1), [self::row(1, 'Sam', 34, 1)], 2026));
        self::assertSame(40.0, $totals['division|M|30|Sam']);
    }

    public function test_road_teams_by_time_with_short_teams_last_and_b_team_displacement(): void {
        $results = [];
        foreach (range(0, 9) as $i) {
            $results[] = self::row(1, "A{$i}", 25, 0, ['club' => 'Club A', 'time_seconds' => 100 + $i]);
        }
        foreach (range(0, 4) as $i) {
            $results[] = self::row(1, "B{$i}", 25, 0, ['club' => 'Club B', 'time_seconds' => 106 + $i]);
        }
        foreach (range(0, 3) as $i) {
            $results[] = self::row(1, "C{$i}", 25, 0, ['club' => 'Club C', 'time_seconds' => 90 + $i]);
        }
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::ROAD), self::events(1), $results, 2026), 'team');

        // A (510s) 1st; A's B team (535s) 2nd, scoring nothing; B (540s) 3rd; C has four
        // runners, so it ranks after every full team despite the fastest time.
        self::assertSame(10.0, $totals['team|M|OPEN|Club A']);
        self::assertSame(8.0, $totals['team|M|OPEN|Club B']);
        self::assertSame(7.0, $totals['team|M|OPEN|Club C']);
        self::assertCount(3, $totals);
    }

    public function test_road_team_sizes_and_season_counts_one_race_fewer(): void {
        self::assertSame(['scorers' => 5, 'short_min' => 4], GrandPrixRules::road_team_size('F', 'OPEN', 10000));
        self::assertSame(['scorers' => 5, 'short_min' => 4], GrandPrixRules::road_team_size('M', '40', 21097));
        self::assertSame(['scorers' => 3, 'short_min' => null], GrandPrixRules::road_team_size('F', '40', 10000));
        self::assertSame(['scorers' => 4, 'short_min' => 3], GrandPrixRules::road_team_size('M', '50', 10000));
        self::assertSame(['scorers' => 3, 'short_min' => null], GrandPrixRules::road_team_size('M', 'OPEN', 42195));

        $results = [];
        foreach ([1, 2, 3] as $event) {
            foreach (range(0, 2) as $i) {
                $results[] = self::row($event, "W{$i}", 45, 0, ['sex' => 'F', 'club' => 'Club W', 'time_seconds' => 200 + $i]);
            }
        }
        $events = self::events(3, [3 => ['distance_m' => 30000, 'multiplier' => 2.0]]);
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::ROAD), $events, $results, 2026), 'team');
        // Three team races: the best two count (LDR 12.3): 20 at the 2.0 race, then 10.
        self::assertSame(30.0, $totals['team|F|40|Club W']);
    }

    public function test_short_and_long_series_distances(): void {
        self::assertSame('short', GrandPrixRules::road_series_part(5000));
        self::assertSame('short', GrandPrixRules::road_series_part(10000));
        self::assertNull(GrandPrixRules::road_series_part(11000));
        self::assertSame('long', GrandPrixRules::road_series_part(12000));
        self::assertSame('long', GrandPrixRules::road_series_part(42195));
    }

    public function test_xc_counts_best_five_plus_the_championship(): void {
        $results = [];
        foreach (range(1, 6) as $event) {
            $results[] = self::row($event, 'Runner', 25, 1);
        }
        $results[] = self::row(7, 'Faster', 25, 1);
        $results[] = self::row(7, 'Runner', 25, 2);
        $events = self::events(7, [7 => ['is_championship' => 1]]);
        $rows = GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::XC), $events, $results, 2026);
        $totals = self::totals($rows);
        // Best five of the six 150s, plus 135 at the championship (not a sixth 150).
        self::assertSame(885.0, $totals['division|M|OPEN|Runner']);
    }

    public function test_xc_teams_use_the_xc_team_rules(): void {
        $results = [];
        foreach (range(1, 5) as $i) {
            $results[] = self::row(1, "X{$i}", 25, $i, ['club' => 'Club X']);
        }
        foreach (range(6, 10) as $i) {
            $results[] = self::row(1, "Y{$i}", 25, $i, ['club' => 'Club Y']);
        }
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::XC), self::events(1), $results, 2026), 'team');
        // XC section 2: 15 for 1st, 13.5 for 2nd.
        self::assertSame(15.0, $totals['team|M|OPEN|Club X']);
        self::assertSame(13.5, $totals['team|M|OPEN|Club Y']);
    }

    public function test_mut_top_seven_with_scoring_factor_limits(): void {
        $factors = [1 => 1.5, 2 => 1.5, 3 => 1.5, 4 => 1.5, 5 => 1.5, 6 => 2.0, 7 => 2.0, 8 => 1.0];
        $overrides = [];
        $results = [];
        foreach ($factors as $event => $factor) {
            $overrides[$event] = ['multiplier' => $factor];
            $results[] = self::row($event, 'Trail', 35, 1);
        }
        $rows = GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::MUT), self::events(8, $overrides), $results, 2026);
        $row = array_values(array_filter($rows, static fn($r) => $r['entry_type'] === 'individual'))[0];
        // One 2.0 race (80), three more above 1.0 (3 x 60), and the 1.0 race (40).
        self::assertSame(300.0, $row['total_points']);
        self::assertSame(5, count(array_filter($row['races'], static fn($r) => $r['counted'])));

        // Seven races or fewer in the series: all count.
        $rows = GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::MUT), self::events(7, $overrides), array_slice($results, 0, 7), 2026);
        self::assertSame(5 * 60.0 + 2 * 80.0, $rows[0]['total_points']);
    }

    public function test_mut_teams_by_top_three_time_with_overall(): void {
        $results = [];
        foreach (range(0, 2) as $i) {
            $results[] = self::row(1, "M{$i}", 30 + 10 * $i, 0, ['club' => 'Trail Club', 'time_seconds' => 3000 + $i]);
            $results[] = self::row(1, "F{$i}", 30 + 10 * $i, 0, ['club' => 'Trail Club', 'sex' => 'F', 'time_seconds' => 3100 + $i]);
            $results[] = self::row(1, "N{$i}", 30, 0, ['club' => 'Hill Club', 'time_seconds' => 3050 + $i]);
        }
        $totals = self::totals(GrandPrixStandings::compute(GrandPrixRules::get(GrandPrixRules::MUT), self::events(1), $results, 2026), 'team');
        self::assertSame(40.0, $totals['team|M|MEN|Trail Club']);
        self::assertSame(31.0, $totals['team|M|MEN|Hill Club']);
        self::assertSame(40.0, $totals['team|F|WOMEN|Trail Club']);
        self::assertSame(80.0, $totals['team|X|OVERALL|Trail Club']);
        self::assertSame(31.0, $totals['team|X|OVERALL|Hill Club']);
    }
}
