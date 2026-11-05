<?php
/**
 * Grand Prix scoring rules without WordPress side effects.
 *
 * Sources: PA/USATF LDR Guide (updated 1/13/2003 and later editions), section 11.1,
 * and PA/USATF Cross Country rules (5/15/04 and later). Earlier seasons used
 * different tables; series rows can override the default table.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class GrandPrixScoring {
    /**
     * PA individual points, column A (Open and Masters), places 1-50.
     * Places beyond 50th score nothing.
     */
    public const PA_ROAD_POINTS_A = [
        100, 90, 81, 73, 69, 66, 63, 60, 57, 54,
        51, 48, 45, 43, 41, 39, 37, 35, 33, 31,
        30, 29, 28, 27, 26, 25, 24, 23, 22, 21,
        20, 19, 18, 17, 16, 15, 14, 13, 12, 11,
        10, 9, 8, 7, 6, 5, 4, 3, 2, 1,
    ];

    /**
     * Defaults for a new series. PA sets no minimum number of races to appear in
     * the standings and awards no bonus points in road, cross country or race walk.
     *
     * @return array<string, mixed>
     */
    public static function series_defaults(): array {
        return [
            'name' => '',
            'slug' => '',
            'season_year' => (int) gmdate('Y'),
            'scoring_system' => 'place',
            'place_points' => (string) json_encode(self::PA_ROAD_POINTS_A),
            'min_races' => 0,
            'max_races' => null,
            'drop_worst' => 0,
            'bonus_points' => '{}',
            'divisions' => (string) json_encode(['OPEN', 'M40', 'M50', 'M60', 'M70', 'W40', 'W50', 'W60', 'W70']),
            'status' => 'active',
        ];
    }

    /**
     * Points for a place; 0 for places outside the table.
     *
     * @param list<int|float|string> $table Points for places 1..n.
     */
    public static function points_for_place(int $place, array $table): float {
        if ($place <= 0 || !isset($table[$place - 1])) {
            return 0.0;
        }
        return (float) $table[$place - 1];
    }

    /**
     * Resolve sex for standings: the recorded sex wins; otherwise only an explicit
     * division prefix or word decides. Open divisions exist for both sexes, so an
     * unlabelled Open result is 'X' (unknown), never assumed male.
     */
    public static function resolve_gender(?string $sex, ?string $division): string {
        $sex = strtoupper(trim((string) $sex));
        if ($sex === 'M' || $sex === 'F') {
            return $sex;
        }
        $division = trim((string) $division);
        if ($division === '') {
            return 'X';
        }
        if (preg_match('/\b(women|woman|female|girls?)\b/i', $division) === 1 || preg_match('/^[WF](?![a-z])/i', $division) === 1) {
            return 'F';
        }
        if (preg_match('/\b(men|man|male|boys?)\b/i', $division) === 1 || preg_match('/^M(?![a-z])/i', $division) === 1) {
            return 'M';
        }
        return 'X';
    }

    /**
     * Standard competition ranking for totals already sorted best-first:
     * equal totals share a rank and the next rank skips (1, 2, 2, 4).
     *
     * @param list<int|float> $sorted_totals
     * @return list<int>
     */
    public static function competition_ranks(array $sorted_totals): array {
        $ranks = [];
        $previous = null;
        $rank = 0;
        foreach (array_values($sorted_totals) as $index => $total) {
            if ($previous === null || (float) $total !== (float) $previous) {
                $rank = $index + 1;
            }
            $ranks[] = $rank;
            $previous = $total;
        }
        return $ranks;
    }
}
