<?php
/**
 * Cross country team scoring per the PA Cross Country Grand Prix rules (updated 3/1/26).
 *
 * Section 3:
 * - A: a runner's points are their finishing place in the division, after non-scoring
 *   runners are removed.
 * - B: only current USATF Pacific members on PA teams score. Unattached runners and
 *   incomplete teams are removed.
 * - C/D: the number of scorers, the two displacers, and the shorter teams ranked after
 *   full ones depend on the division.
 * - E: low total wins; a tie goes to the team whose last scorer placed better.
 * - G: B-team runners (8th-12th, 7th-11th or 6th-10th on a team) displace but score nothing.
 *
 * Section 2 gives the per-race team points (15 down to 1.5 for 10th).
 *
 * Membership and PA-club status are inputs: RM does not hold rosters, so callers pass
 * them, usually from GPEligibility::check(), which reads the pausatf_results_gp_member_status
 * and pausatf_results_gp_is_pa_club filters.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class XCTeamScoring {
    /** Section 2: team points for places 1-10. */
    public const TEAM_POINTS = [15.0, 13.5, 12.0, 10.5, 9.0, 7.5, 6.0, 4.5, 3.0, 1.5];

    /**
     * Section 3 C/D scorers per division; displacers are always the next two runners.
     * Short teams (one runner fewer) are ranked after full teams in five-scorer divisions
     * and in Men's 50s.
     */
    private const SCORERS = [
        'M' => ['OPEN' => 5, '40' => 5, '50' => 4, '60' => 3, '70' => 3, '80' => 3],
        'F' => ['OPEN' => 5, '40' => 3, '50' => 3, '60' => 3, '70' => 3, '80' => 3],
    ];

    /** Section 3 G: how many runners past the A team can displace as a B team. */
    private const B_TEAM_SIZE = 5;

    /**
     * Scoring rules for a team division, or null when PA scores no team there.
     *
     * @param string $division OPEN, 40, 50, 60, 70 or 80
     * @return array{scorers: int, a_team: int, short_min: int|null, b_team_last: int}|null
     */
    public static function division_rules(string $sex, string $division): ?array {
        $scorers = self::SCORERS[$sex][strtoupper($division)] ?? null;
        if ($scorers === null) {
            return null;
        }
        $a_team = $scorers + 2;
        return [
            'scorers' => $scorers,
            'a_team' => $a_team,
            'short_min' => ($scorers === 5 || $scorers === 4) ? $scorers - 1 : null,
            'b_team_last' => $a_team + self::B_TEAM_SIZE,
        ];
    }

    public static function team_points(int $place): float {
        return self::TEAM_POINTS[$place - 1] ?? 0.0;
    }

    /**
     * Score one division of one race.
     *
     * @param list<array{id: int|string, place: int, team: ?string, eligible: bool}> $finishers
     *        Every finisher in the division, with their finishing order in it.
     * @param array{scorers: int, a_team: int, short_min: int|null, b_team_last: int} $rules
     * @return list<array{team: string, place: int, score: int, scorers: list<int|string>, displacers: list<int|string>, complete: bool, points: float}>
     */
    public static function score(array $finishers, array $rules): array {
        usort($finishers, static fn(array $a, array $b): int => $a['place'] <=> $b['place']);

        // B: only eligible runners on a team.
        $by_team = [];
        foreach ($finishers as $runner) {
            $team = self::team_key($runner['team'] ?? null);
            if ($runner['eligible'] && $team !== null) {
                $by_team[$team][] = $runner;
            }
        }

        // C/D: drop teams below the minimum; keep the A team and B-team displacers only.
        $min = $rules['short_min'] ?? $rules['scorers'];
        $counted = [];
        $a_teams = [];
        foreach ($by_team as $team => $runners) {
            if (count($runners) < $min) {
                continue;
            }
            $a_teams[$team] = array_slice($runners, 0, $rules['a_team']);
            foreach (array_slice($runners, 0, $rules['b_team_last']) as $runner) {
                $counted[] = $runner + ['team_key' => $team];
            }
        }
        usort($counted, static fn(array $a, array $b): int => $a['place'] <=> $b['place']);

        // A: re-place the remaining runners; equal finishing places share a place.
        $team_place = [];
        $last_place = null;
        $last_value = 0;
        foreach ($counted as $i => $runner) {
            if ($runner['place'] !== $last_place) {
                $last_value = $i + 1;
                $last_place = $runner['place'];
            }
            $team_place[(string) $runner['id']] = $last_value;
        }

        $teams = [];
        foreach ($a_teams as $team => $runners) {
            $complete = count($runners) >= $rules['scorers'];
            $scoring = array_slice($runners, 0, $complete ? $rules['scorers'] : $min);
            $places = array_map(static fn(array $r): int => $team_place[(string) $r['id']], $scoring);
            $teams[] = [
                'team' => (string) ($runners[0]['team'] ?? $team),
                'place' => 0,
                'score' => array_sum($places),
                'last_scorer' => (int) end($places),
                'scorers' => array_column($scoring, 'id'),
                'displacers' => array_column(array_slice($runners, count($scoring), 2), 'id'),
                'complete' => $complete,
                'points' => 0.0,
            ];
        }

        // C/E: full teams first; then low score; then the better last scorer.
        usort($teams, static fn(array $a, array $b): int =>
            [$b['complete'], $a['score'], $a['last_scorer']] <=> [$a['complete'], $b['score'], $b['last_scorer']]);

        foreach ($teams as $i => &$team) {
            $previous = $teams[$i - 1] ?? null;
            $tied = $previous !== null && [$previous['complete'], $previous['score'], $previous['last_scorer']]
                === [$team['complete'], $team['score'], $team['last_scorer']];
            $team['place'] = $tied ? $previous['place'] : $i + 1;
            $team['points'] = self::team_points($team['place']);
        }
        unset($team);

        return array_map(static function (array $team): array {
            unset($team['last_scorer']);
            return $team;
        }, $teams);
    }

    /**
     * Team division (OPEN, 40 ... 80) from age on race day, or from the listed division when
     * the age is missing. Runners under 16 are not eligible for team scoring (PA XC rules,
     * divisions). Drop-downs to a younger team are allowed only for runners in that team's
     * race (section 3 J); RM cannot tell heats apart, so `pausatf_results_xc_team_division`
     * (division, result row) can move a runner.
     *
     * @param array<string, mixed> $result
     */
    public static function team_division(array $result): ?string {
        $age = isset($result['athlete_age']) && $result['athlete_age'] !== '' ? (int) $result['athlete_age'] : null;
        $division = null;
        if ($age !== null) {
            $division = $age < 16 ? null : ($age < 40 ? 'OPEN' : (string) min(80, intdiv($age, 10) * 10));
        } else {
            $listed = (string) ($result['division'] ?? '');
            if (preg_match('/open/i', $listed) === 1) {
                $division = 'OPEN';
            } elseif (preg_match('/(\d{2})/', $listed, $m) === 1 && (int) $m[1] >= 40) {
                $division = (string) min(80, intdiv((int) $m[1], 10) * 10);
            }
        }
        $division = apply_filters('pausatf_results_xc_team_division', $division, $result);
        return is_string($division) && $division !== '' ? strtoupper($division) : null;
    }

    /** Normalized team key; null for unattached or blank. */
    public static function team_key(?string $team): ?string {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $team) ?? ''));
        if ($key === '' || preg_match('/^(unattached|unatt\.?|ua|none|n\/a|-)$/', $key) === 1) {
            return null;
        }
        return $key;
    }
}
