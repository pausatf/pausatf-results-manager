<?php
/**
 * Road record course limits: 2026 USATF Competition Rules, Rule 265.4 (p. 158-159).
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Rules\USATFChampionshipRules;
use PAUSATF\Results\Rules\USATFCompetitionRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rules/class-usatf-competition-rules.php';
require_once __DIR__ . '/../includes/rules/class-usatf-championship-rules.php';

class RoadRecordCourseTest extends TestCase {
    public function test_point_to_point_courses_can_be_record_eligible(): void {
        $rules = USATFCompetitionRules::get_road_certification_rules(2026)['record_eligibility'];

        self::assertTrue($rules['point_to_point']);
        self::assertSame(1.0, $rules['max_drop_per_km']);
        self::assertSame(0.5, $rules['max_separation']);
    }

    public function test_limits_are_inclusive(): void {
        // A point-to-point 10K whose finish is 5 km from the start and 10 m lower sits exactly on both limits.
        self::assertTrue(USATFCompetitionRules::road_course_meets_record_limits(10000, 5000, 10.0));
        // A loop course with a net climb.
        self::assertTrue(USATFCompetitionRules::road_course_meets_record_limits(42195, 0, -12.0));
    }

    public function test_courses_beyond_either_limit_are_not_eligible(): void {
        self::assertFalse(USATFCompetitionRules::road_course_meets_record_limits(10000, 5001, 0.0));
        // A marathon whose finish is 139 m lower than its start drops 3.3 m per km.
        self::assertFalse(USATFCompetitionRules::road_course_meets_record_limits(42195, 0, 139.0));
        self::assertFalse(USATFCompetitionRules::road_course_meets_record_limits(0, 0, 0.0));
    }

    public function test_championship_rules_use_the_same_limits(): void {
        $eligible = USATFChampionshipRules::get_rules('road_championships', 2026)['course_requirements']['record_eligible'];

        self::assertSame(USATFCompetitionRules::ROAD_RECORD_MAX_DROP_PER_KM, $eligible['max_drop']);
        self::assertSame((int) (USATFCompetitionRules::ROAD_RECORD_MAX_SEPARATION * 100), $eligible['max_separation']);
    }
}
