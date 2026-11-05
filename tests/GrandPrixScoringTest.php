<?php
/**
 * Grand Prix scoring rules: PA points tables, gender resolution and shared ranks.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\GrandPrixScoring;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-grand-prix-scoring.php';

class GrandPrixScoringTest extends TestCase {
    public function test_default_table_is_pa_road_column_a(): void {
        $table = GrandPrixScoring::PA_ROAD_POINTS_A;
        // PA LDR Guide, updated 1/13/2003, R100 column A (confirmed by 2003-2005 standings).
        self::assertSame([100, 90, 81, 73, 69, 66, 63, 60, 57, 54], array_slice($table, 0, 10));
        self::assertSame([51, 48, 45, 43, 41, 39, 37, 35, 33, 31, 30], array_slice($table, 10, 11));
        self::assertCount(50, $table);
        self::assertSame(1, $table[49]);
    }

    public function test_places_beyond_the_table_score_zero(): void {
        $series = ['place_points' => GrandPrixScoring::PA_ROAD_POINTS_A];
        self::assertSame(81.0, GrandPrixScoring::points_for_place(3, $series['place_points']));
        self::assertSame(1.0, GrandPrixScoring::points_for_place(50, $series['place_points']));
        self::assertSame(0.0, GrandPrixScoring::points_for_place(51, $series['place_points']));
        self::assertSame(0.0, GrandPrixScoring::points_for_place(0, $series['place_points']));
    }

    public function test_recorded_sex_wins_over_division_prefix(): void {
        self::assertSame('F', GrandPrixScoring::resolve_gender('F', 'OPEN'));
        self::assertSame('M', GrandPrixScoring::resolve_gender('m', 'W40'));
        self::assertSame('F', GrandPrixScoring::resolve_gender(null, 'W50'));
        self::assertSame('F', GrandPrixScoring::resolve_gender('', 'Open Women'));
        self::assertSame('M', GrandPrixScoring::resolve_gender(null, 'M40'));
        self::assertSame('M', GrandPrixScoring::resolve_gender(null, 'Masters Men'));
    }

    public function test_open_without_recorded_sex_is_unknown_not_male(): void {
        self::assertSame('X', GrandPrixScoring::resolve_gender(null, 'OPEN'));
        self::assertSame('X', GrandPrixScoring::resolve_gender(null, null));
        self::assertSame('X', GrandPrixScoring::resolve_gender('', ''));
    }

    public function test_equal_totals_share_a_rank(): void {
        self::assertSame([1, 2, 2, 4, 5, 5, 5, 8], GrandPrixScoring::competition_ranks([90, 81, 81, 73, 69, 69, 69, 1]));
        self::assertSame([], GrandPrixScoring::competition_ranks([]));
        self::assertSame([1, 1], GrandPrixScoring::competition_ranks([30.5, 30.5]));
    }

    public function test_series_defaults_have_no_minimum_or_bonus(): void {
        $defaults = GrandPrixScoring::series_defaults();
        self::assertSame(0, $defaults['min_races']);
        self::assertSame('{}', $defaults['bonus_points']);
        self::assertSame(GrandPrixScoring::PA_ROAD_POINTS_A, json_decode($defaults['place_points'], true));
    }
}
