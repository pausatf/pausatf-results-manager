<?php
/**
 * Grand Prix standings under a 2026 PA rule set (GrandPrixRules), without database writes.
 *
 * Individuals score by place within their division among eligible athletes at each race:
 * PA members (GPEligibility::member), aged 16+, listed M or F. Points are multiplied by the
 * event's scoring value (LDR 13.1, MUT rule 5). Athletes are matched across races by
 * AthleteIdentity, not by printed name. Teams score by GPEligibility::check, which also
 * requires a PA club.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-grand-prix-rules.php';
require_once __DIR__ . '/class-grand-prix-scoring.php';
require_once __DIR__ . '/class-gp-eligibility.php';
require_once __DIR__ . '/class-xc-team-scoring.php';
require_once __DIR__ . '/class-athlete-identity.php';

final class GrandPrixStandings {
    /**
     * @param array<string, mixed> $rules GrandPrixRules::get()
     * @param list<array<string, mixed>> $events Series events: event_id, multiplier, team_multiplier,
     *        is_championship, distance_m, event_date.
     * @param list<array<string, mixed>> $results Result rows for those events.
     * @return list<array<string, mixed>> Rows: entry_type (individual, team), standing_type
     *         (division, age_plus, team), gender, division, key, name, athlete_id, total_points,
     *         races_completed, rank_position, races, verified.
     */
    public static function compute(array $rules, array $events, array $results, int $season_year): array {
        $by_event = [];
        foreach ($events as $event) {
            $multiplier = (float) ($event['multiplier'] ?? 1.0);
            $team_multiplier = $event['team_multiplier'] ?? null;
            $by_event[(int) $event['event_id']] = [
                'multiplier' => $multiplier,
                // LDR 13.2(d): values above 1.0 may be split differently for individuals and teams.
                'team_multiplier' => $team_multiplier === null || $team_multiplier === '' ? $multiplier : (float) $team_multiplier,
                'championship' => !empty($event['is_championship']),
                'distance_m' => isset($event['distance_m']) && $event['distance_m'] !== '' ? (int) $event['distance_m'] : null,
                'date' => (string) ($event['event_date'] ?? ''),
            ];
        }

        $entries = self::entries($results, $by_event);
        $rows = [];
        foreach (self::individual_races($rules, $entries, $by_event, $season_year) as $group) {
            $rows[] = self::total('individual', $group, self::select($group['races'], (string) $rules['selection']['type'], $rules, $by_event));
        }
        foreach (self::team_races($rules, $entries, $by_event) as $group) {
            $rows[] = self::total('team', $group, self::select($group['races'], (string) $rules['team']['season'], $rules, $by_event));
        }

        // MUT rule 2: the Overall team division is the sum of the other team divisions.
        if ($rules['team']['method'] === 'mut') {
            $overall = [];
            foreach ($rows as $row) {
                if ($row['entry_type'] !== 'team') {
                    continue;
                }
                $overall[$row['key']] ??= ['entry_type' => 'team', 'standing_type' => 'team', 'gender' => 'X', 'division' => 'OVERALL',
                    'key' => $row['key'], 'name' => $row['name'], 'athlete_id' => null, 'total_points' => 0.0,
                    'races_completed' => 0, 'races' => [], 'verified' => true];
                $overall[$row['key']]['total_points'] += $row['total_points'];
                $overall[$row['key']]['races_completed'] += $row['races_completed'];
                $overall[$row['key']]['verified'] = $overall[$row['key']]['verified'] && $row['verified'];
            }
            array_push($rows, ...array_values($overall));
        }

        return self::rank($rows);
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array{event_id: int, place: int, points: float, counted: bool}> $races
     * @return array<string, mixed>
     */
    private static function total(string $entry_type, array $group, array $races): array {
        $points = 0.0;
        foreach ($races as $race) {
            $points += $race['counted'] ? $race['points'] : 0.0;
        }
        return [
            'entry_type' => $entry_type,
            'standing_type' => $group['standing_type'],
            'gender' => $group['gender'],
            'division' => $group['division'],
            'key' => $group['key'],
            'name' => $group['name'],
            'athlete_id' => $group['athlete_id'],
            'total_points' => round($points, 2),
            'races_completed' => count($races),
            'races' => $races,
            'verified' => $group['verified'],
        ];
    }

    /**
     * Normalized entries for results at series events. Results whose sex is neither M nor F
     * are left out: categories USATF does not score stay unscored.
     *
     * @param list<array<string, mixed>> $results
     * @param array<int, array<string, mixed>> $by_event
     * @return list<array<string, mixed>>
     */
    private static function entries(array $results, array $by_event): array {
        $entries = [];
        foreach ($results as $row) {
            $event_id = (int) ($row['event_id'] ?? 0);
            $sex = GrandPrixScoring::resolve_gender($row['sex'] ?? null, $row['division'] ?? null);
            if (!isset($by_event[$event_id]) || ($sex !== 'M' && $sex !== 'F')) {
                continue;
            }
            $identity = AthleteIdentity::resolve((string) ($row['athlete_name'] ?? ''), isset($row['athlete_id']) ? (int) $row['athlete_id'] : null);
            $age = $row['athlete_age'] ?? null;
            $time = isset($row['time_seconds']) ? (int) $row['time_seconds'] : 0;
            $place = isset($row['place']) ? (int) $row['place'] : 0;
            $entries[] = [
                'row' => $row,
                'event_id' => $event_id,
                'sex' => $sex,
                'key' => $identity['key'],
                'identity_verified' => $identity['verified'],
                'age' => $age === null || $age === '' ? null : (int) $age,
                // Finishing order: the printed overall place, else the time.
                'order' => $place > 0 ? (float) $place : ($time > 0 ? 1e7 + $time : INF),
                'member' => GPEligibility::member($row),
                'team_ok' => GPEligibility::check($row),
                'club' => XCTeamScoring::team_key(isset($row['club']) ? (string) $row['club'] : null),
                'club_name' => trim((string) ($row['club'] ?? '')),
                'time' => $time > 0 ? $time : null,
            ];
        }
        return $entries;
    }

    /**
     * Divisions an entry scores in at its race.
     *
     * Road and XC carry a runner who enters a new decade in the younger division for the rest
     * of the year, and score them in the new one from the birthday (LDR 10.0). With a birth
     * date from `pausatf_results_gp_birth_date` (null, athlete key, row; returns Y-m-d), that
     * is decided from the birth year. Without one, it applies when the same athlete raced at
     * the younger age earlier in the series.
     *
     * Without an age, the listed division is used if it reads as Open or a decade, and the
     * entry is unverified. No age is derived from it.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $entry
     * @param array<string, list<array{date: string, age: int}>> $seen
     * @return list<string>
     */
    private static function divisions(array $rules, array $entry, string $date, array $seen, int $season_year): array {
        if ($entry['age'] === null) {
            $listed = (string) ($entry['row']['division'] ?? '');
            if (preg_match('/open/i', $listed) === 1) {
                return ['OPEN'];
            }
            if (preg_match('/(\d{2})/', $listed, $m) === 1) {
                $division = GrandPrixRules::division_for_age((int) $m[1], $rules['divisions']);
                return $division !== null && $division !== 'OPEN' ? [$division] : [];
            }
            return [];
        }
        if ($entry['age'] < GrandPrixRules::MIN_AGE) {
            return [];
        }
        $own = GrandPrixRules::division_for_age($entry['age'], $rules['divisions']);
        $divisions = $own === null ? [] : [$own];
        $younger = GrandPrixRules::division_for_age($entry['age'] - 1, $rules['divisions']);
        if (!$rules['carry_over'] || $younger === null || $younger === $own) {
            return $divisions;
        }
        $birth = apply_filters('pausatf_results_gp_birth_date', null, $entry['key'], $entry['row']);
        if (is_string($birth) && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $birth, $m) === 1) {
            $carried = $season_year - (int) $m[1] === $entry['age'];
        } else {
            $carried = false;
            foreach ($seen[$entry['key']] ?? [] as $earlier) {
                if ($earlier['date'] < $date && $earlier['age'] === $entry['age'] - 1) {
                    $carried = true;
                    break;
                }
            }
        }
        if ($carried) {
            $divisions[] = $younger;
        }
        return $divisions;
    }

    /**
     * Individual race points, grouped per standing. Division standings leave out runners
     * from older divisions; age+ standings (road and XC, LDR 10.0) include everyone at or
     * above the division's age.
     *
     * @param array<string, mixed> $rules
     * @param list<array<string, mixed>> $entries
     * @param array<int, array<string, mixed>> $by_event
     * @return array<string, array<string, mixed>>
     */
    private static function individual_races(array $rules, array $entries, array $by_event, int $season_year): array {
        $seen = [];
        foreach ($entries as $entry) {
            if ($entry['age'] !== null) {
                $seen[$entry['key']][] = ['date' => $by_event[$entry['event_id']]['date'], 'age' => $entry['age']];
            }
        }

        // event => standing type => sex => division => entries
        $fields = [];
        foreach ($entries as $entry) {
            if (!$entry['member']['eligible']) {
                continue;
            }
            $date = $by_event[$entry['event_id']]['date'];
            foreach (self::divisions($rules, $entry, $date, $seen, $season_year) as $division) {
                $fields[$entry['event_id']]['division'][$entry['sex']][$division][] = $entry;
            }
            if ($rules['age_plus'] && $entry['age'] !== null && $entry['age'] >= GrandPrixRules::MIN_AGE) {
                foreach ($rules['divisions'] as $code => $min) {
                    if ($entry['age'] >= $min) {
                        $fields[$entry['event_id']]['age_plus'][$entry['sex']][$code === 'OPEN' ? 'OPEN' : $code . '+'][] = $entry;
                    }
                }
            }
        }

        $groups = [];
        foreach ($fields as $event_id => $types) {
            foreach ($types as $type => $sexes) {
                foreach ($sexes as $sex => $divisions) {
                    foreach ($divisions as $division => $field) {
                        usort($field, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
                        $places = GrandPrixScoring::competition_ranks(array_map(static fn(array $e): float => -$e['order'], $field));
                        foreach ($field as $i => $entry) {
                            $id = "{$type}|{$sex}|{$division}|{$entry['key']}";
                            $groups[$id] ??= ['standing_type' => $type, 'gender' => $sex, 'division' => (string) $division,
                                'key' => $entry['key'], 'name' => (string) $entry['row']['athlete_name'],
                                'athlete_id' => isset($entry['row']['athlete_id']) ? (int) $entry['row']['athlete_id'] : null,
                                'races' => [], 'verified' => true];
                            $groups[$id]['races'][] = [
                                'event_id' => (int) $event_id,
                                'place' => $places[$i],
                                'points' => GrandPrixScoring::points_for_place($places[$i], $rules['points']) * $by_event[$event_id]['multiplier'],
                                'counted' => true,
                            ];
                            $groups[$id]['verified'] = $groups[$id]['verified'] && $entry['member']['verified']
                                && $entry['identity_verified'] && $entry['age'] !== null;
                        }
                    }
                }
            }
        }
        return $groups;
    }

    /**
     * Team race points, grouped per club and team division. B teams take a place but score
     * nothing (LDR 20.1, XC section 3 G).
     *
     * @param array<string, mixed> $rules
     * @param list<array<string, mixed>> $entries
     * @param array<int, array<string, mixed>> $by_event
     * @return array<string, array<string, mixed>>
     */
    private static function team_races(array $rules, array $entries, array $by_event): array {
        $method = (string) $rules['team']['method'];
        $fields = [];
        foreach ($entries as $entry) {
            if (!$entry['team_ok']['eligible'] || $entry['club'] === null) {
                continue;
            }
            if ($method === 'mut') {
                // MUT rule 2: Men and Women teams regardless of age. Mixed is not scored yet.
                $division = $entry['sex'] === 'F' ? 'WOMEN' : 'MEN';
            } elseif ($method === 'xc') {
                $division = XCTeamScoring::team_division($entry['row']);
            } else {
                $division = $entry['age'] !== null && $entry['age'] >= GrandPrixRules::MIN_AGE
                    ? GrandPrixRules::division_for_age($entry['age'], $rules['divisions']) : null;
                // LDR 18.2 and 19: drop-downs and stepping down depend on team sheets RM does not have.
                $division = apply_filters('pausatf_results_gp_team_division', $division, $entry['row']);
            }
            if (is_string($division) && $division !== '') {
                $fields[$entry['event_id']][$entry['sex']][$division][] = $entry;
            }
        }

        $groups = [];
        foreach ($fields as $event_id => $sexes) {
            $event = $by_event[$event_id];
            foreach ($sexes as $sex => $divisions) {
                foreach ($divisions as $division => $field) {
                    $division = (string) $division;
                    if ($method === 'xc') {
                        $scored = self::xc_teams((string) $sex, $division, $field);
                    } elseif ($method === 'mut') {
                        $scored = self::time_teams($field, 3, null, false,
                            static fn(int $place): float => GrandPrixScoring::points_for_place($place, GrandPrixRules::MUT_POINTS));
                    } else {
                        $size = GrandPrixRules::road_team_size((string) $sex, $division, $event['distance_m']);
                        $scored = self::time_teams($field, $size['scorers'], $size['short_min'], true,
                            static fn(int $place): float => GrandPrixRules::road_team_points($place));
                    }
                    foreach ($scored as $team) {
                        if ($team['b_team']) {
                            continue;
                        }
                        $id = "team|{$sex}|{$division}|{$team['club']}";
                        $groups[$id] ??= ['standing_type' => 'team', 'gender' => (string) $sex, 'division' => $division,
                            'key' => 'club:' . $team['club'], 'name' => $team['name'], 'athlete_id' => null, 'races' => [], 'verified' => true];
                        $groups[$id]['races'][] = ['event_id' => (int) $event_id, 'place' => $team['place'],
                            'points' => $team['points'] * $event['team_multiplier'], 'counted' => true];
                        $groups[$id]['verified'] = $groups[$id]['verified'] && $team['verified'];
                    }
                }
            }
        }
        return $groups;
    }

    /**
     * XC section 3 teams for one division of one race.
     *
     * @param list<array<string, mixed>> $field
     * @return list<array{club: string, name: string, place: int, points: float, b_team: bool, verified: bool}>
     */
    private static function xc_teams(string $sex, string $division, array $field): array {
        $rules = XCTeamScoring::division_rules($sex, $division);
        if ($rules === null) {
            return [];
        }
        usort($field, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        $places = GrandPrixScoring::competition_ranks(array_map(static fn(array $e): float => -$e['order'], $field));
        $finishers = [];
        foreach ($field as $i => $entry) {
            $finishers[] = ['id' => $i, 'place' => $places[$i], 'team' => $entry['club'], 'eligible' => true];
        }
        $teams = [];
        foreach (XCTeamScoring::score($finishers, $rules) as $team) {
            $verified = true;
            foreach ($team['scorers'] as $id) {
                $verified = $verified && $field[(int) $id]['team_ok']['verified'];
            }
            $first = $field[(int) $team['scorers'][0]];
            $teams[] = ['club' => (string) $first['club'], 'name' => (string) $first['club_name'], 'place' => $team['place'],
                'points' => $team['points'], 'b_team' => false, 'verified' => $verified];
        }
        return $teams;
    }

    /**
     * Teams ranked by the cumulative time of their scorers (LDR 12.2(a), MUT rule 4). Teams
     * with short_min to scorers-1 runners rank after full teams. With b_teams, a club's next
     * runners form a B team that takes a place but scores nothing (LDR 20.1).
     *
     * @param list<array<string, mixed>> $field
     * @param callable(int): float $points
     * @return list<array{club: string, name: string, place: int, points: float, b_team: bool, verified: bool}>
     */
    private static function time_teams(array $field, int $scorers, ?int $short_min, bool $b_teams, callable $points): array {
        $clubs = [];
        foreach ($field as $entry) {
            if ($entry['time'] !== null) {
                $clubs[$entry['club']][] = $entry;
            }
        }
        $teams = [];
        foreach ($clubs as $club => $runners) {
            usort($runners, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
            $squads = [array_slice($runners, 0, $scorers)];
            if ($b_teams && count($runners) > $scorers) {
                $squads[] = array_slice($runners, $scorers, $scorers);
            }
            foreach ($squads as $n => $squad) {
                $complete = count($squad) >= $scorers;
                if (!$complete && ($short_min === null || count($squad) < $short_min)) {
                    continue;
                }
                $verified = true;
                foreach ($squad as $runner) {
                    $verified = $verified && $runner['team_ok']['verified'];
                }
                $teams[] = ['club' => (string) $club, 'name' => (string) $squad[0]['club_name'], 'complete' => $complete,
                    'time' => array_sum(array_column($squad, 'time')), 'b_team' => $n === 1, 'verified' => $verified];
            }
        }
        usort($teams, static fn(array $a, array $b): int => [$b['complete'], $a['time']] <=> [$a['complete'], $b['time']]);
        $out = [];
        foreach ($teams as $i => $team) {
            $previous = $out[$i - 1] ?? null;
            $place = $previous !== null && $previous['complete'] === $team['complete'] && $previous['time'] === $team['time']
                ? $previous['place'] : $i + 1;
            $out[] = $team + ['place' => $place, 'points' => $points($place)];
        }
        return array_map(static fn(array $t): array => ['club' => $t['club'], 'name' => $t['name'], 'place' => $t['place'],
            'points' => $t['points'], 'b_team' => $t['b_team'], 'verified' => $t['verified']], $out);
    }

    /**
     * Mark which races count toward the season total.
     *
     * @param list<array{event_id: int, place: int, points: float, counted: bool}> $races
     * @param array<string, mixed> $rules
     * @param array<int, array<string, mixed>> $by_event
     * @return list<array{event_id: int, place: int, points: float, counted: bool}>
     */
    private static function select(array $races, string $how, array $rules, array $by_event): array {
        $order = array_keys($races);
        usort($order, static fn(int $a, int $b): int => $races[$b]['points'] <=> $races[$a]['points']);
        $keep = [];

        if ($how === 'best_plus_championship') {
            // XC section 4: the championship race plus the best five others.
            $others = 0;
            foreach ($order as $i) {
                if ($by_event[$races[$i]['event_id']]['championship']) {
                    $keep[$i] = true;
                } elseif ($others < (int) $rules['selection']['best']) {
                    $keep[$i] = true;
                    $others++;
                }
            }
        } elseif ($how === 'all_but_one') {
            // LDR 12.3: one race fewer than the series has.
            foreach (array_slice($order, 0, max(1, count($by_event) - 1)) as $i) {
                $keep[$i] = true;
            }
        } elseif ($how === 'mut') {
            // MUT rule 3: top seven, at most four above factor 1.0 and at most one at 2.0, or
            // all races when the series has seven or fewer. Taking the best race that still
            // fits is optimal because factor-2.0 races are a subset of factor-above-1.0 races.
            $limits = $rules['selection'];
            if (count($by_event) <= (int) $limits['count']) {
                $keep = array_fill_keys($order, true);
            } else {
                $above = 0;
                $at_two = 0;
                foreach ($order as $i) {
                    if (count($keep) >= (int) $limits['count']) {
                        break;
                    }
                    $factor = (float) $by_event[$races[$i]['event_id']]['multiplier'];
                    if (($factor > 1.0 && $above >= (int) $limits['max_above_one']) || ($factor >= 2.0 && $at_two >= (int) $limits['max_at_two'])) {
                        continue;
                    }
                    $above += $factor > 1.0 ? 1 : 0;
                    $at_two += $factor >= 2.0 ? 1 : 0;
                    $keep[$i] = true;
                }
            }
        } else {
            $keep = array_fill_keys($order, true);
        }

        foreach ($races as $i => $race) {
            $races[$i]['counted'] = isset($keep[$i]);
        }
        return array_values($races);
    }

    /**
     * Competition ranks within each standing; equal totals share a rank.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function rank(array $rows): array {
        $buckets = [];
        foreach ($rows as $row) {
            $buckets["{$row['entry_type']}|{$row['standing_type']}|{$row['gender']}|{$row['division']}"][] = $row;
        }
        $out = [];
        foreach ($buckets as $bucket) {
            usort($bucket, static fn(array $a, array $b): int => [$b['total_points'], $a['name']] <=> [$a['total_points'], $b['name']]);
            $ranks = GrandPrixScoring::competition_ranks(array_column($bucket, 'total_points'));
            foreach ($bucket as $i => $row) {
                $out[] = $row + ['rank_position' => $ranks[$i]];
            }
        }
        return $out;
    }
}
