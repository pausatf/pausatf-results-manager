<?php
/**
 * PA Cross Country Grand Prix team scoring (PA XC rules, updated 3/1/26, sections 2 and 3).
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\ClubManager;
use PAUSATF\Results\GPEligibility;
use PAUSATF\Results\XCTeamScoring;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-gp-eligibility.php';
require_once __DIR__ . '/../includes/class-xc-team-scoring.php';
require_once __DIR__ . '/../includes/class-club-manager.php';

class XCTeamScoringTest extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['pausatf_test_filters']['pausatf_results_gp_member_status'],
            $GLOBALS['pausatf_test_filters']['pausatf_results_gp_is_pa_club'],
            $GLOBALS['pausatf_test_filters']['pausatf_results_xc_team_division']
        );
    }

    /**
     * @param array<int, string> $teams finishing place => team ('' = unattached)
     * @param list<int> $ineligible places of runners without PA membership
     * @return list<array{id: int, place: int, team: ?string, eligible: bool}>
     */
    private static function race(array $teams, array $ineligible = []): array {
        $finishers = [];
        foreach ($teams as $place => $team) {
            $finishers[] = ['id' => $place, 'place' => $place, 'team' => $team, 'eligible' => !in_array($place, $ineligible, true)];
        }
        return $finishers;
    }

    /** @return array<string, array<string, mixed>> */
    private static function byTeam(array $scored): array {
        return array_column($scored, null, 'team');
    }

    public function test_division_scorer_counts(): void {
        // 3 C: Men's Open and 40s, Women's Open: 5 scorers. 3 D: Men's 50s: 4; others: 3.
        self::assertSame(5, XCTeamScoring::division_rules('M', 'OPEN')['scorers']);
        self::assertSame(5, XCTeamScoring::division_rules('M', '40')['scorers']);
        self::assertSame(5, XCTeamScoring::division_rules('F', 'OPEN')['scorers']);
        self::assertSame(4, XCTeamScoring::division_rules('M', '50')['scorers']);
        foreach ([['F', '40'], ['F', '50'], ['M', '60'], ['F', '70'], ['M', '80']] as [$sex, $division]) {
            self::assertSame(3, XCTeamScoring::division_rules($sex, $division)['scorers'], "{$sex}{$division}");
            self::assertNull(XCTeamScoring::division_rules($sex, $division)['short_min']);
        }
        self::assertNull(XCTeamScoring::division_rules('X', 'OPEN'));
    }

    public function test_unattached_ineligible_and_incomplete_teams_are_removed_and_places_reassigned(): void {
        // 3 A and B. Team C has three runners, below the four needed, so it is removed.
        $race = self::race([1 => 'A', 2 => 'B', 3 => '', 4 => 'A', 5 => 'C', 6 => 'A', 7 => 'B', 8 => 'C',
            9 => 'A', 10 => 'B', 11 => 'A', 12 => 'B', 13 => 'B', 14 => 'C', 15 => 'B'], [15]);

        $teams = self::byTeam(XCTeamScoring::score($race, XCTeamScoring::division_rules('M', 'OPEN')));

        self::assertSame(['A', 'B'], array_keys($teams));
        // Re-placed: A 1,3,4,6,8 and B 2,5,7,9,10.
        self::assertSame(22, $teams['A']['score']);
        self::assertSame(33, $teams['B']['score']);
        self::assertSame([1, 4, 6, 9, 11], $teams['A']['scorers']);
        self::assertSame(1, $teams['A']['place']);
    }

    public function test_displacers_and_b_team_runners_push_other_teams_back(): void {
        // A finishes 1-13. Its 6th and 7th displace (3 C), its 8th-12th displace as a B team
        // (3 G), and its 13th does not count. B finishes 14-18.
        $places = array_fill_keys(range(1, 13), 'A') + array_fill_keys(range(14, 18), 'B');

        $teams = self::byTeam(XCTeamScoring::score(self::race($places), XCTeamScoring::division_rules('M', 'OPEN')));

        self::assertSame(15, $teams['A']['score']);
        self::assertSame([6, 7], $teams['A']['displacers']);
        self::assertSame(13 + 14 + 15 + 16 + 17, $teams['B']['score']);
    }

    public function test_four_runner_teams_rank_after_five_runner_teams_and_tie_on_the_fourth(): void {
        $race = self::race([1 => 'C', 2 => 'D', 3 => 'D', 4 => 'C', 5 => 'B', 6 => 'C', 7 => 'D', 8 => 'D',
            9 => 'C', 10 => 'B', 11 => 'B', 12 => 'B', 13 => 'B']);

        $scored = XCTeamScoring::score($race, XCTeamScoring::division_rules('F', 'OPEN'));

        self::assertSame(['B', 'D', 'C'], array_column($scored, 'team'));
        self::assertTrue($scored[0]['complete']);
        self::assertSame($scored[1]['score'], $scored[2]['score']);
        self::assertSame([1, 2, 3], array_column($scored, 'place'));
    }

    public function test_tie_goes_to_the_better_fifth_runner(): void {
        // 3 E. A: 1,3,7,8,9 (+10 displacing) = 28; B: 2,4,5,6,11 = 28.
        $race = self::race([1 => 'A', 2 => 'B', 3 => 'A', 4 => 'B', 5 => 'B', 6 => 'B', 7 => 'A',
            8 => 'A', 9 => 'A', 10 => 'A', 11 => 'B']);

        $scored = XCTeamScoring::score($race, XCTeamScoring::division_rules('M', '40'));

        self::assertSame(['A', 'B'], array_column($scored, 'team'));
        self::assertSame([28, 28], array_column($scored, 'score'));
        self::assertSame([1, 2], array_column($scored, 'place'));
    }

    public function test_mens_50s_score_four_and_rank_three_runner_teams_after(): void {
        $race = self::race([1 => 'A', 2 => 'A', 3 => 'A', 4 => 'B', 5 => 'B', 6 => 'B', 7 => 'B']);

        $scored = XCTeamScoring::score($race, XCTeamScoring::division_rules('M', '50'));

        self::assertSame(['B', 'A'], array_column($scored, 'team'));
        self::assertSame(4 + 5 + 6 + 7, $scored[0]['score']);
        self::assertFalse($scored[1]['complete']);
    }

    public function test_three_scorer_divisions_drop_teams_of_two(): void {
        $race = self::race([1 => 'A', 2 => 'A', 3 => 'B', 4 => 'B', 5 => 'B']);

        $scored = XCTeamScoring::score($race, XCTeamScoring::division_rules('F', '40'));

        self::assertSame(['B'], array_column($scored, 'team'));
        self::assertSame(1 + 2 + 3, $scored[0]['score']);
    }

    public function test_team_points_per_race(): void {
        // Section 2.
        self::assertSame(15.0, XCTeamScoring::team_points(1));
        self::assertSame(10.5, XCTeamScoring::team_points(4));
        self::assertSame(1.5, XCTeamScoring::team_points(10));
        self::assertSame(0.0, XCTeamScoring::team_points(11));
    }

    public function test_team_division_comes_from_age_and_excludes_under_16(): void {
        self::assertSame('OPEN', XCTeamScoring::team_division(['athlete_age' => 16]));
        self::assertNull(XCTeamScoring::team_division(['athlete_age' => 15]));
        self::assertSame('40', XCTeamScoring::team_division(['athlete_age' => 49]));
        self::assertSame('80', XCTeamScoring::team_division(['athlete_age' => 91]));
        self::assertSame('50', XCTeamScoring::team_division(['athlete_age' => null, 'division' => 'M50-59']));

        $GLOBALS['pausatf_test_filters']['pausatf_results_xc_team_division'] = static fn($division) => $division === '50' ? '40' : $division;
        self::assertSame('40', XCTeamScoring::team_division(['athlete_age' => 52]));
    }

    public function test_eligibility_filters(): void {
        self::assertSame(['eligible' => true, 'verified' => false], GPEligibility::check(['club' => 'A']));

        $GLOBALS['pausatf_test_filters']['pausatf_results_gp_member_status'] = static fn($status, $row) => $row['athlete_name'] !== 'Visitor';
        $GLOBALS['pausatf_test_filters']['pausatf_results_gp_is_pa_club'] = static fn($status, $club) => $club === 'A';
        self::assertSame(['eligible' => true, 'verified' => true], GPEligibility::check(['athlete_name' => 'Pat', 'club' => 'A']));
        self::assertFalse(GPEligibility::check(['athlete_name' => 'Visitor', 'club' => 'A'])['eligible']);
        self::assertFalse(GPEligibility::check(['athlete_name' => 'Pat', 'club' => 'Out of Area TC'])['eligible']);
    }

    public function test_club_manager_scores_each_division_of_a_race(): void {
        $rows = [];
        $add = static function (int $place, string $club, string $sex, int $age) use (&$rows): void {
            $rows[] = ['id' => $place, 'athlete_id' => null, 'athlete_name' => "Runner {$place}", 'place' => $place,
                'sex' => $sex, 'athlete_age' => $age, 'division' => '', 'club' => $club];
        };
        foreach (range(1, 5) as $p) {
            $add($p, 'Alpha', 'M', 25);
        }
        foreach (range(6, 10) as $p) {
            $add($p, 'Beta', 'M', 30);
        }
        foreach (range(11, 13) as $p) {
            $add($p, 'Alpha', 'F', 45);
        }
        $add(14, 'Alpha', 'X', 30);

        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class($rows) {
            public string $prefix = 'wp_';
            public function __construct(private array $rows) {}
            public function prepare(string $sql, ...$args): string {
                return $sql;
            }
            public function get_results(string $sql, $output = null): array {
                return $this->rows;
            }
        };
        try {
            $standings = (new \ReflectionClass(ClubManager::class))->newInstanceWithoutConstructor()->get_event_team_standings(99);
        } finally {
            $GLOBALS['wpdb'] = $previous;
        }

        $summary = array_map(static fn(array $r): string => "{$r['sex']}{$r['division']} {$r['club']} {$r['team_place']} {$r['total_score']} {$r['team_points']}", $standings);
        self::assertSame(['MOPEN Alpha 1 15 15', 'MOPEN Beta 2 40 13.5', 'F40 Alpha 1 6 15'], $summary);
        self::assertFalse($standings[0]['verified']);
    }
}
