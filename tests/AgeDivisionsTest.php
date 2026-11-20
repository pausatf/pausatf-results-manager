<?php
/**
 * USATF/PA age divisions and age-determination rules.
 *
 * Rule citations are to the 2026 USATF Competition Rules: Rule 141 (p. 48-49) and Rule 320 (p. 189).
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Rules\USATFAgeDivisions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rules/class-usatf-age-divisions.php';

class AgeDivisionsTest extends TestCase {
    protected function tearDown(): void {
        unset($GLOBALS['pausatf_test_filters']['pausatf_results_age_rules']);
    }

    /** @return array<string, array{int, string}> */
    public static function youthBands(): array {
        // Rule 300.1(b) bands; names as used by PA (ytfagegroups.html).
        return [
            'age 7' => [7, 'Sub-Bantam'], 'age 8' => [8, 'Sub-Bantam'],
            'age 9' => [9, 'Bantam'], 'age 10' => [10, 'Bantam'],
            'age 11' => [11, 'Midget'], 'age 12' => [12, 'Midget'],
            'age 13' => [13, 'Youth'], 'age 14' => [14, 'Youth'],
            'age 15' => [15, 'Intermediate'], 'age 16' => [16, 'Intermediate'],
            'age 17' => [17, 'Young'], 'age 18' => [18, 'Young'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('youthBands')]
    public function test_youth_bands_match_rule_300(int $age, string $name): void {
        $division = USATFAgeDivisions::get_division_for_age($age, 'F', 2012);
        self::assertNotNull($division);
        self::assertSame($name, $division['name']);
        self::assertSame('F', $division['gender']);
    }

    public function test_youth_division_comes_from_birth_year_not_birthday(): void {
        // Born 2003: 8 & under (Sub-Bantam) for all of 2011 regardless of birthday (ytfagegroups2011.html).
        self::assertSame('Sub-Bantam', USATFAgeDivisions::get_youth_division_for_birth_year(2003, 2011, 'M')['name']);
        self::assertSame('Bantam', USATFAgeDivisions::get_youth_division_for_birth_year(2001, 2011, 'M')['name']);
        self::assertSame('Young', USATFAgeDivisions::get_youth_division_for_birth_year(1993, 2011, 'M')['name']);
        self::assertNull(USATFAgeDivisions::get_youth_division_for_birth_year(1992, 2011, 'M'));
    }

    public function test_masters_start_at_35_for_track_and_40_for_ldr(): void {
        // Rule 141.1(d).
        self::assertSame('M35', USATFAgeDivisions::get_division_for_age(37, 'M', 2026, 'track_field')['code']);
        self::assertSame('W35', USATFAgeDivisions::get_division_for_age(35, 'F', 2026, 'race_walk')['code']);
        foreach (['road', 'cross_country', 'mut'] as $discipline) {
            self::assertSame('OPEN', USATFAgeDivisions::get_division_for_age(37, 'M', 2026, $discipline)['code'], $discipline);
            self::assertSame('W40', USATFAgeDivisions::get_division_for_age(40, 'W', 2026, $discipline)['code'], $discipline);
        }
        self::assertSame('M100', USATFAgeDivisions::get_division_for_age(103, 'M', 2026, 'road')['code']);
    }

    public function test_age_nineteen_is_open_not_a_fixed_u20_band(): void {
        // Rule 141.1(b): U20 depends on dates, not on being 19.
        self::assertSame('OPEN', USATFAgeDivisions::get_division_for_age(19, 'M', 2026)['code']);
        self::assertNull(USATFAgeDivisions::get_division_by_code('U20_M', 2026));
    }

    public function test_open_minimum_age_depends_on_discipline(): void {
        // Rule 141.1(a): 14 for track & field and race walking, 16 for cross country and LDR.
        self::assertSame(14, USATFAgeDivisions::get_division_for_age(25, 'F', 2026, 'track_field')['min_age']);
        self::assertSame(16, USATFAgeDivisions::get_division_for_age(25, 'F', 2026, 'road')['min_age']);

        $codes = static fn(array $divisions): array => array_column($divisions, 'code');
        self::assertSame(['U16', 'OPEN'], $codes(USATFAgeDivisions::get_eligible_divisions(15, 'F', 2026, 'track_field')));
        self::assertSame(['U16'], $codes(USATFAgeDivisions::get_eligible_divisions(15, 'F', 2026, 'cross_country')));
        self::assertSame(['M40', 'OPEN'], $codes(USATFAgeDivisions::get_eligible_divisions(42, 'M', 2026, 'road')));
    }

    public function test_u20_needs_14_on_the_day_and_not_turning_20_that_year(): void {
        $day = new \DateTimeImmutable('2026-06-01');
        self::assertTrue(USATFAgeDivisions::is_u20(new \DateTimeImmutable('2007-01-15'), $day));   // 19, turns 19 in 2026
        self::assertFalse(USATFAgeDivisions::is_u20(new \DateTimeImmutable('2006-12-31'), $day));  // 19 today, turns 20 in 2026
        self::assertTrue(USATFAgeDivisions::is_u20(new \DateTimeImmutable('2012-05-31'), $day));   // 14 yesterday
        self::assertFalse(USATFAgeDivisions::is_u20(new \DateTimeImmutable('2012-06-02'), $day));  // 13 on the day
    }

    public function test_rule_320_masters_meet_groups_include_25_and_30(): void {
        self::assertSame('W25', USATFAgeDivisions::get_masters_meet_division(27, 'F')['code']);
        self::assertSame('M30', USATFAgeDivisions::get_masters_meet_division(34, 'M')['code']);
        self::assertNull(USATFAgeDivisions::get_masters_meet_division(24, 'M'));
        // The masters classification still starts at 35 (Rule 141.1(d)).
        self::assertSame('OPEN', USATFAgeDivisions::get_division_for_age(30, 'M', 2026)['code']);
    }

    public function test_long_events_set_minimum_ages(): void {
        // Rule 141.3.
        self::assertNull(USATFAgeDivisions::minimum_age_for_event(21097.5));
        self::assertSame(16, USATFAgeDivisions::minimum_age_for_event(30000));
        self::assertSame(16, USATFAgeDivisions::minimum_age_for_event(42195));
        self::assertSame(18, USATFAgeDivisions::minimum_age_for_event(50000));
        self::assertSame(16, USATFAgeDivisions::minimum_age_for_event(null, 3601));
        self::assertSame(18, USATFAgeDivisions::minimum_age_for_event(null, 24 * 3600));
    }

    public function test_unlisted_sex_is_never_scored_as_men(): void {
        foreach (['', 'X', 'NB', 'U', 'other'] as $sex) {
            self::assertNull(USATFAgeDivisions::get_division_for_age(45, $sex, 2026, 'road'), $sex);
            self::assertNull(USATFAgeDivisions::get_single_year_division(45, $sex), $sex);
            self::assertNull(USATFAgeDivisions::get_10year_division(45, $sex), $sex);
            self::assertSame([], USATFAgeDivisions::get_eligible_divisions(45, $sex, 2026), $sex);
        }
        self::assertNull(USATFAgeDivisions::parse_division_string('Masters 40+')['gender']);
        self::assertSame('M', USATFAgeDivisions::parse_division_string('M40-44')['gender']);
        self::assertSame('F', USATFAgeDivisions::parse_division_string('W50')['gender']);
    }

    public function test_age_rules_are_filterable_in_one_place(): void {
        $GLOBALS['pausatf_test_filters']['pausatf_results_age_rules'] = static function (array $rules): array {
            $rules['road']['masters_min'] = 50;
            return $rules;
        };
        self::assertSame('OPEN', USATFAgeDivisions::get_division_for_age(45, 'M', 2026, 'road')['code']);
    }

    public function test_age_determination_depends_on_context(): void {
        self::assertSame('year_of_birth', USATFAgeDivisions::get_age_determination_rule(2024, 'youth'));
        self::assertSame('competition_day', USATFAgeDivisions::get_age_determination_rule(2024, 'open'));
        self::assertSame('first_day_of_meet', USATFAgeDivisions::get_age_determination_rule(2024, 'masters'));
        self::assertSame('first_day_of_meet', USATFAgeDivisions::get_age_determination_rule(2024, 'masters_ldr'));
        self::assertSame('competition_day', USATFAgeDivisions::get_age_determination_rule(2024));
    }
}
