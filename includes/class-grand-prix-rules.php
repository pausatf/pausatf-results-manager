<?php
/**
 * 2026 PA Grand Prix rule sets: road, cross country and MUT.
 *
 * Sources (copies dated as shown):
 * - Road: USATF Pacific LDR Guidelines and Rules, updated 2/27/2026 (LDR), sections 9-13, 18-20.
 * - Cross country: PA Cross Country Grand Prix rules, updated 3/1/26 (XC), sections 1-4.
 * - MUT: USATF Pacific MUT Grand Prix Rules, updated January 13, 2026 (MUT), rules 2-8.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class GrandPrixRules {
    /** LDR 11.1 and XC section 1: individual points for places 1-100. */
    public const PA_2026_POINTS = [
        150, 135, 125, 120, 116, 113, 110, 108, 106, 104,
        102, 100, 98, 96, 94, 92, 90, 88, 86, 84,
        82, 80, 78, 77, 76, 75, 74, 73, 72, 71,
        70, 69, 68, 67, 66, 65, 64, 63, 62, 61,
        60, 59, 58, 57, 56, 55, 54, 53, 52, 51,
        50, 49, 48, 47, 46, 45, 44, 43, 42, 41,
        40, 39, 38, 37, 36, 35, 34, 33, 32, 31,
        30, 29, 28, 27, 26, 25, 24, 23, 22, 21,
        20, 19, 18, 17, 16, 15, 14, 13, 12, 11,
        10, 9, 8, 7, 6, 5, 4, 3, 2, 1,
    ];

    /** LDR 12.1: road team points for places 1-9; 10th and beyond score 1. */
    public const ROAD_TEAM_POINTS = [10, 9, 8, 7, 6, 5, 4, 3, 2];

    /** MUT rule 3 (individuals) and rule 4 (teams): points for places 1-18. */
    public const MUT_POINTS = [40, 31, 24, 19, 16, 14, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];

    public const ROAD = 'pa_road_2026';
    public const XC = 'pa_xc_2026';
    public const MUT = 'pa_mut_2026';

    /** Youngest age scored (LDR 10.0, XC divisions, MUT rule 2). */
    public const MIN_AGE = 16;

    /**
     * Rules for a rule set, or null for an unknown one (legacy series).
     *
     * divisions: division code => youngest age in it, oldest last.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $ruleset): ?array {
        $decades = ['OPEN' => self::MIN_AGE, '40' => 40, '50' => 50, '60' => 60, '70' => 70, '80' => 80];
        return match ($ruleset) {
            // LDR 10.0 divisions with decade carry-over; 11.1 points; 9.2 sets no individual
            // race cap (the 2019 guide's six-race limit is gone); 12.1-12.3 teams; 13.1 values.
            self::ROAD => [
                'discipline' => 'road',
                'points' => self::PA_2026_POINTS,
                'divisions' => $decades,
                'carry_over' => true,
                'age_plus' => true,
                'selection' => ['type' => 'all'],
                'team' => ['method' => 'time', 'season' => 'all_but_one'],
            ],
            // XC divisions and section 1 points; section 4 best five plus the championship;
            // section 3 team scoring with section 2 points.
            self::XC => [
                'discipline' => 'cross_country',
                'points' => self::PA_2026_POINTS,
                'divisions' => $decades,
                'carry_over' => true,
                'age_plus' => true,
                'selection' => ['type' => 'best_plus_championship', 'best' => 5],
                'team' => ['method' => 'xc', 'season' => 'best_plus_championship'],
            ],
            // MUT rule 2 divisions, race-day age only (rule 8); rule 3 top seven with factor
            // limits; rule 4 teams by top-three time; rule 5 factors multiply points.
            self::MUT => [
                'discipline' => 'mut',
                'points' => self::MUT_POINTS,
                'divisions' => ['OPEN' => self::MIN_AGE, '30' => 30, '40' => 40, '50' => 50, '60' => 60, '70' => 70, '80' => 80],
                'carry_over' => false,
                'age_plus' => false,
                'selection' => ['type' => 'mut', 'count' => 7, 'max_above_one' => 4, 'max_at_two' => 1],
                'team' => ['method' => 'mut', 'season' => 'mut'],
            ],
            default => null,
        };
    }

    /**
     * Division code for an age under a rule set's divisions; null under 16.
     *
     * @param array<string, int> $divisions
     */
    public static function division_for_age(int $age, array $divisions): ?string {
        $found = null;
        foreach ($divisions as $code => $min) {
            if ($age >= $min) {
                $found = (string) $code;
            }
        }
        return $found;
    }

    /**
     * Road team scorers for a division and event distance (LDR 12.2(a)): beyond 25K three
     * for everyone; otherwise five for Open men and women and Men's 40s (four-runner teams
     * ranked after), four for Men's 50s (three-runner teams after), three for all others.
     *
     * @return array{scorers: int, short_min: int|null}
     */
    public static function road_team_size(string $sex, string $division, ?int $distance_m): array {
        if ($distance_m !== null && $distance_m > 25000) {
            return ['scorers' => 3, 'short_min' => null];
        }
        if ($division === 'OPEN' || ($sex === 'M' && $division === '40')) {
            return ['scorers' => 5, 'short_min' => 4];
        }
        if ($sex === 'M' && $division === '50') {
            return ['scorers' => 4, 'short_min' => 3];
        }
        return ['scorers' => 3, 'short_min' => null];
    }

    /** LDR 12.1 team points for a place. */
    public static function road_team_points(int $place): float {
        return $place <= 0 ? 0.0 : (float) (self::ROAD_TEAM_POINTS[$place - 1] ?? 1);
    }

    /**
     * Road Short or Long series for an event distance: the Short series is 10K and
     * shorter, the Long series 12K to the marathon (pausatf.org road racing page).
     * Null for distances outside both.
     */
    public static function road_series_part(int $distance_m): ?string {
        if ($distance_m <= 10000) {
            return 'short';
        }
        if ($distance_m >= 12000 && $distance_m <= 42195) {
            return 'long';
        }
        return null;
    }
}
