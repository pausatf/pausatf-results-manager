<?php
/**
 * Championship rules: age determination, XC tiebreak, road record courses, track team points.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Rules\USATFChampionshipRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rules/class-usatf-championship-rules.php';

class ChampionshipRulesTest extends TestCase {
    public function test_association_meets_use_competition_day_age_except_youth(): void {
        $rules = USATFChampionshipRules::get_rules('association', 2024);
        self::assertSame('competition_day', $rules['age_determination']);
        self::assertSame('year_of_birth', $rules['youth_age_determination']);
    }

    public function test_xc_team_tie_is_broken_by_the_last_scorer(): void {
        self::assertSame('last_scorer_place', USATFChampionshipRules::get_team_scoring_rules('cross_country', 2024)['tiebreaker']);
    }

    public function test_point_to_point_courses_are_not_banned_from_records(): void {
        $eligible = USATFChampionshipRules::get_rules('road_championships', 2024)['course_requirements']['record_eligible'];
        self::assertTrue($eligible['point_to_point']);
        self::assertSame(50, $eligible['max_separation']);
        self::assertSame(1.0, $eligible['max_drop']);
    }

    public function test_track_team_points_default_to_rule_7_3(): void {
        self::assertSame([5, 3, 2, 1], USATFChampionshipRules::get_team_scoring_rules('track', 2024)['scoring_places']);
        self::assertSame(11, USATFChampionshipRules::calculate_tf_team_score([['place' => 1], ['place' => 1], ['place' => 4], ['place' => 5]]));
        // A meet's published table (Rule 7.1) can replace the default.
        self::assertSame(18, USATFChampionshipRules::calculate_tf_team_score([['place' => 1], ['place' => 2]], [10, 8, 6, 5, 4, 3, 2, 1]));
    }
}
