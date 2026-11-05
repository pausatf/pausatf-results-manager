<?php
/**
 * USATF/PA age divisions and age-determination rules.
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Rules\USATFAgeDivisions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/rules/class-usatf-age-divisions.php';

class AgeDivisionsTest extends TestCase {
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

    public function test_age_nineteen_is_not_a_youth_division(): void {
        self::assertSame('U20', USATFAgeDivisions::get_division_for_age(19, 'M', 2024)['code']);
    }

    public function test_age_determination_depends_on_context(): void {
        self::assertSame('year_of_birth', USATFAgeDivisions::get_age_determination_rule(2024, 'youth'));
        self::assertSame('competition_day', USATFAgeDivisions::get_age_determination_rule(2024, 'open'));
        self::assertSame('first_day_of_meet', USATFAgeDivisions::get_age_determination_rule(2024, 'masters'));
        self::assertSame('race_start_day', USATFAgeDivisions::get_age_determination_rule(2024, 'masters_ldr'));
        self::assertSame('competition_day', USATFAgeDivisions::get_age_determination_rule(2024));
    }
}
