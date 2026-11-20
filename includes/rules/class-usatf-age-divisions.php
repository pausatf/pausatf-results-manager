<?php
/**
 * USATF Age Divisions
 *
 * Defines age group divisions per the 2026 USATF Competition Rules: Rule 141 classifications
 * (p. 48-49), Rule 300.1 youth divisions and Rule 320 masters divisions (p. 189).
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USATF Age Division definitions by year
 */
class USATFAgeDivisions {
    /**
     * Youth divisions per USATF Rule 300.1(b): 8 & under, 9-10, 11-12, 13-14, 15-16, 17-18,
     * assigned by year of birth (age reached by December 31 of the competition year).
     * Names are the Pacific Association's; Sub-Bantam is PA-only and competes as Bantam
     * at national meets. Codes are legacy identifiers kept stable for stored records.
     */
    private const YOUTH_DIVISIONS = [
        'U8' => ['code' => 'U8', 'name' => 'Sub-Bantam', 'min_age' => 0, 'max_age' => 8, 'gender' => 'X'],
        'U10' => ['code' => 'U10', 'name' => 'Bantam', 'min_age' => 9, 'max_age' => 10, 'gender' => 'X'],
        'U12' => ['code' => 'U12', 'name' => 'Midget', 'min_age' => 11, 'max_age' => 12, 'gender' => 'X'],
        'U14' => ['code' => 'U14', 'name' => 'Youth', 'min_age' => 13, 'max_age' => 14, 'gender' => 'X'],
        'U16' => ['code' => 'U16', 'name' => 'Intermediate', 'min_age' => 15, 'max_age' => 16, 'gender' => 'X'],
        'U18' => ['code' => 'U18', 'name' => 'Young', 'min_age' => 17, 'max_age' => 18, 'gender' => 'X'],
    ];

    /**
     * Age rules per discipline, in one place. Filter `pausatf_results_age_rules` (rules) can
     * change them for a meet.
     *
     * - open_min: Rule 141.1(a), 14 on the day of competition for track & field and race
     *   walking, 16 for cross country and long distance running.
     * - masters_min: Rule 141.1(d), 35 for track & field and race walking, 40 for long
     *   distance running, on the first day of the meet.
     * - open_age_on / masters_age_on: the date age is taken on (141.1(a), 141.1(d), 320.4).
     * - youth_age_on: Rule 300.1(b), the age reached by December 31 of the competition year.
     */
    public const AGE_RULES = [
        'track_field' => ['open_min' => 14, 'masters_min' => 35, 'open_age_on' => 'competition_day', 'masters_age_on' => 'first_day_of_meet', 'youth_age_on' => 'year_of_birth'],
        'race_walk' => ['open_min' => 14, 'masters_min' => 35, 'open_age_on' => 'competition_day', 'masters_age_on' => 'first_day_of_meet', 'youth_age_on' => 'year_of_birth'],
        'road' => ['open_min' => 16, 'masters_min' => 40, 'open_age_on' => 'competition_day', 'masters_age_on' => 'first_day_of_meet', 'youth_age_on' => 'year_of_birth'],
        'cross_country' => ['open_min' => 16, 'masters_min' => 40, 'open_age_on' => 'competition_day', 'masters_age_on' => 'first_day_of_meet', 'youth_age_on' => 'year_of_birth'],
        'mut' => ['open_min' => 16, 'masters_min' => 40, 'open_age_on' => 'competition_day', 'masters_age_on' => 'first_day_of_meet', 'youth_age_on' => 'year_of_birth'],
    ];

    /** Rule 141.3: minimum ages for events longer than the half marathon and the marathon. */
    private const HALF_MARATHON_M = 21097.5;
    private const MARATHON_M = 42195.0;

    /**
     * Open division. Its lower bound depends on the discipline (Rule 141.1(a)); there is no maximum.
     */
    private const OPEN_DIVISIONS = [
        'OPEN' => ['code' => 'OPEN', 'name' => 'Open', 'min_age' => 14, 'max_age' => null, 'gender' => 'X'],
    ];

    /**
     * Masters meet divisions, Rule 320.2 (p. 189): five-year groups from 25-29 to 95-99, then 100+.
     * The masters classification itself starts at 35 or 40 (Rule 141.1(d)); M/W25 and M/W30 are
     * used only where a meet follows the Rule 320 masters age groups.
     */
    private const MASTERS_DIVISIONS = [
        'M25' => ['code' => 'M25', 'name' => 'Masters 25-29', 'min_age' => 25, 'max_age' => 29, 'gender' => 'M'],
        'M30' => ['code' => 'M30', 'name' => 'Masters 30-34', 'min_age' => 30, 'max_age' => 34, 'gender' => 'M'],
        'M35' => ['code' => 'M35', 'name' => 'Masters 35-39', 'min_age' => 35, 'max_age' => 39, 'gender' => 'M'],
        'M40' => ['code' => 'M40', 'name' => 'Masters 40-44', 'min_age' => 40, 'max_age' => 44, 'gender' => 'M'],
        'M45' => ['code' => 'M45', 'name' => 'Masters 45-49', 'min_age' => 45, 'max_age' => 49, 'gender' => 'M'],
        'M50' => ['code' => 'M50', 'name' => 'Masters 50-54', 'min_age' => 50, 'max_age' => 54, 'gender' => 'M'],
        'M55' => ['code' => 'M55', 'name' => 'Masters 55-59', 'min_age' => 55, 'max_age' => 59, 'gender' => 'M'],
        'M60' => ['code' => 'M60', 'name' => 'Masters 60-64', 'min_age' => 60, 'max_age' => 64, 'gender' => 'M'],
        'M65' => ['code' => 'M65', 'name' => 'Masters 65-69', 'min_age' => 65, 'max_age' => 69, 'gender' => 'M'],
        'M70' => ['code' => 'M70', 'name' => 'Masters 70-74', 'min_age' => 70, 'max_age' => 74, 'gender' => 'M'],
        'M75' => ['code' => 'M75', 'name' => 'Masters 75-79', 'min_age' => 75, 'max_age' => 79, 'gender' => 'M'],
        'M80' => ['code' => 'M80', 'name' => 'Masters 80-84', 'min_age' => 80, 'max_age' => 84, 'gender' => 'M'],
        'M85' => ['code' => 'M85', 'name' => 'Masters 85-89', 'min_age' => 85, 'max_age' => 89, 'gender' => 'M'],
        'M90' => ['code' => 'M90', 'name' => 'Masters 90-94', 'min_age' => 90, 'max_age' => 94, 'gender' => 'M'],
        'M95' => ['code' => 'M95', 'name' => 'Masters 95-99', 'min_age' => 95, 'max_age' => 99, 'gender' => 'M'],
        'M100' => ['code' => 'M100', 'name' => 'Masters 100+', 'min_age' => 100, 'max_age' => null, 'gender' => 'M'],
        'W25' => ['code' => 'W25', 'name' => 'Masters 25-29', 'min_age' => 25, 'max_age' => 29, 'gender' => 'F'],
        'W30' => ['code' => 'W30', 'name' => 'Masters 30-34', 'min_age' => 30, 'max_age' => 34, 'gender' => 'F'],
        'W35' => ['code' => 'W35', 'name' => 'Masters 35-39', 'min_age' => 35, 'max_age' => 39, 'gender' => 'F'],
        'W40' => ['code' => 'W40', 'name' => 'Masters 40-44', 'min_age' => 40, 'max_age' => 44, 'gender' => 'F'],
        'W45' => ['code' => 'W45', 'name' => 'Masters 45-49', 'min_age' => 45, 'max_age' => 49, 'gender' => 'F'],
        'W50' => ['code' => 'W50', 'name' => 'Masters 50-54', 'min_age' => 50, 'max_age' => 54, 'gender' => 'F'],
        'W55' => ['code' => 'W55', 'name' => 'Masters 55-59', 'min_age' => 55, 'max_age' => 59, 'gender' => 'F'],
        'W60' => ['code' => 'W60', 'name' => 'Masters 60-64', 'min_age' => 60, 'max_age' => 64, 'gender' => 'F'],
        'W65' => ['code' => 'W65', 'name' => 'Masters 65-69', 'min_age' => 65, 'max_age' => 69, 'gender' => 'F'],
        'W70' => ['code' => 'W70', 'name' => 'Masters 70-74', 'min_age' => 70, 'max_age' => 74, 'gender' => 'F'],
        'W75' => ['code' => 'W75', 'name' => 'Masters 75-79', 'min_age' => 75, 'max_age' => 79, 'gender' => 'F'],
        'W80' => ['code' => 'W80', 'name' => 'Masters 80-84', 'min_age' => 80, 'max_age' => 84, 'gender' => 'F'],
        'W85' => ['code' => 'W85', 'name' => 'Masters 85-89', 'min_age' => 85, 'max_age' => 89, 'gender' => 'F'],
        'W90' => ['code' => 'W90', 'name' => 'Masters 90-94', 'min_age' => 90, 'max_age' => 94, 'gender' => 'F'],
        'W95' => ['code' => 'W95', 'name' => 'Masters 95-99', 'min_age' => 95, 'max_age' => 99, 'gender' => 'F'],
        'W100' => ['code' => 'W100', 'name' => 'Masters 100+', 'min_age' => 100, 'max_age' => null, 'gender' => 'F'],
    ];

    /**
     * Get all divisions for a given year
     */
    public static function get_divisions(int $year): array {
        $divisions = [];

        // Youth divisions. U20 is an eligibility test on dates (is_u20()), not an age band.
        foreach (self::YOUTH_DIVISIONS as $code => $division) {
            $divisions[$code . '_M'] = array_merge($division, ['gender' => 'M']);
            $divisions[$code . '_F'] = array_merge($division, ['gender' => 'F']);
        }

        // Open
        $divisions['OPEN_M'] = array_merge(self::OPEN_DIVISIONS['OPEN'], ['gender' => 'M']);
        $divisions['OPEN_F'] = array_merge(self::OPEN_DIVISIONS['OPEN'], ['gender' => 'F']);

        // Masters
        foreach (self::MASTERS_DIVISIONS as $code => $division) {
            $divisions[$code] = $division;
        }

        return $divisions;
    }

    /**
     * The category a listed sex is scored in: M, or F for a listed F or W. Anything else
     * (unknown, non-binary, X, blank) has no USATF scoring category and returns null; it is
     * never folded into the men's category.
     */
    public static function scoring_sex(?string $sex): ?string {
        return match (strtoupper(trim((string) $sex))) {
            'M' => 'M',
            'F', 'W' => 'F',
            default => null,
        };
    }

    /**
     * Age rules for a discipline; unknown disciplines use track & field.
     *
     * @return array{open_min: int, masters_min: int, open_age_on: string, masters_age_on: string, youth_age_on: string}
     */
    public static function age_rules(string $discipline): array {
        $all = apply_filters('pausatf_results_age_rules', self::AGE_RULES);
        $all = is_array($all) ? $all : self::AGE_RULES;
        return ($all[$discipline] ?? null) ?: ($all['track_field'] ?? self::AGE_RULES['track_field']);
    }

    /**
     * Division for an age (taken on the date age_rules() names) and listed sex.
     *
     * Youth bands cover 18 and under by year of birth. From the discipline's masters age the
     * five-year masters group applies; between the two, Open. Returns null when the sex has
     * no scoring category or the age is below every division.
     */
    public static function get_division_for_age(int $age, string $gender, int $year, string $discipline = 'track_field'): ?array {
        $gender = self::scoring_sex($gender);
        if ($gender === null || $age < 0) {
            return null;
        }
        $gender_code = $gender === 'F' ? 'W' : 'M';
        $rules = self::age_rules($discipline);

        // Youth (age as reached by December 31, i.e. by year of birth)
        if ($age <= 18) {
            foreach (self::YOUTH_DIVISIONS as $code => $division) {
                if ($age >= $division['min_age'] && $age <= $division['max_age']) {
                    return array_merge($division, ['gender' => $gender, 'code' => $code]);
                }
            }
        }

        if ($age < $rules['masters_min']) {
            return array_merge(self::OPEN_DIVISIONS['OPEN'], ['gender' => $gender, 'min_age' => $rules['open_min']]);
        }

        return self::MASTERS_DIVISIONS[$gender_code . (string) min(100, intdiv($age, 5) * 5)] ?? null;
    }

    /**
     * Rule 320.2 division for a masters meet that uses the Rule 320 age groups, including
     * M/W25 and M/W30. Age is taken on the first day of the meet (320.4).
     */
    public static function get_masters_meet_division(int $age, string $gender): ?array {
        $gender = self::scoring_sex($gender);
        if ($gender === null || $age < 25) {
            return null;
        }
        return self::MASTERS_DIVISIONS[($gender === 'F' ? 'W' : 'M') . (string) min(100, intdiv($age, 5) * 5)] ?? null;
    }

    /** Age in whole years on a date. */
    public static function age_on(\DateTimeInterface $birth_date, \DateTimeInterface $on): int {
        $diff = $birth_date->diff($on);
        return $diff->invert === 1 ? -1 : $diff->y;
    }

    /**
     * Rule 141.1(b): U20 means at least 14 on the day of competition and not turning 20
     * during the competition year.
     */
    public static function is_u20(\DateTimeInterface $birth_date, \DateTimeInterface $competition_day): bool {
        $turns_20 = (int) $birth_date->format('Y') + 20;
        return self::age_on($birth_date, $competition_day) >= 14 && $turns_20 > (int) $competition_day->format('Y');
    }

    /**
     * Rule 141.3: events longer than the half marathon or one hour need 16; longer than the
     * marathon or two hours need 18. Null when the event sets no minimum beyond Rule 141.1.
     */
    public static function minimum_age_for_event(?float $distance_m, ?float $duration_s = null): ?int {
        if (($distance_m !== null && $distance_m > self::MARATHON_M) || ($duration_s !== null && $duration_s > 7200)) {
            return 18;
        }
        if (($distance_m !== null && $distance_m > self::HALF_MARATHON_M) || ($duration_s !== null && $duration_s > 3600)) {
            return 16;
        }
        return null;
    }

    /**
     * Get division by code
     */
    public static function get_division_by_code(string $code, int $year): ?array {
        $all_divisions = self::get_divisions($year);
        return $all_divisions[$code] ?? null;
    }

    /**
     * Divisions an athlete can be scored in: the age division plus Open for masters and for
     * youth old enough for Open in the discipline (Rule 141.1(a)).
     */
    public static function get_eligible_divisions(int $age, string $gender, int $year, string $discipline = 'track_field'): array {
        $eligible = [];
        $primary = self::get_division_for_age($age, $gender, $year, $discipline);
        if ($primary === null) {
            return [];
        }
        $eligible[] = $primary;

        $rules = self::age_rules($discipline);
        if ($primary['code'] !== 'OPEN' && $age >= $rules['open_min']) {
            $eligible[] = array_merge(self::OPEN_DIVISIONS['OPEN'], [
                'gender' => $primary['gender'],
                'min_age' => $rules['open_min'],
                'note' => 'May compete in open division',
            ]);
        }

        return $eligible;
    }

    /**
     * Youth division for an athlete's year of birth (Rule 300.1(b)); null above 18.
     *
     * @return array<string, mixed>|null
     */
    public static function get_youth_division_for_birth_year(int $birth_year, int $competition_year, string $gender): ?array {
        $age = $competition_year - $birth_year;
        if ($age > 18) {
            return null;
        }
        return self::get_division_for_age(max($age, 0), $gender, $competition_year);
    }

    /**
     * How age is determined, by context:
     * - open / U20: age on the day of competition (Rule 141.1(a)-(b))
     * - masters (track & field, race walk) and masters_ldr: age on the first day of the meet
     *   (Rule 141.1(d), 320.4)
     * - youth: year of birth (300.1(b))
     */
    public static function get_age_determination_rule(int $year, string $context = 'open'): string {
        return match ($context) {
            'youth' => 'year_of_birth',
            'masters', 'masters_ldr' => 'first_day_of_meet',
            default => 'competition_day',
        };
    }

    /**
     * Calculate single-year age groups for road running
     * Per USATF Road Running Technical Council
     */
    public static function get_single_year_division(int $age, string $gender): ?array {
        $gender = self::scoring_sex($gender);
        if ($gender === null) {
            return null;
        }
        $gender_code = $gender === 'F' ? 'W' : 'M';

        return [
            'code' => $gender_code . $age,
            'name' => "Age {$age}",
            'min_age' => $age,
            'max_age' => $age,
            'gender' => $gender,
        ];
    }

    /**
     * Get 10-year age groups (common for road races)
     */
    public static function get_10year_division(int $age, string $gender): ?array {
        $gender = self::scoring_sex($gender);
        if ($gender === null) {
            return null;
        }
        $gender_code = $gender === 'F' ? 'W' : 'M';
        $decade = floor($age / 10) * 10;
        $max_age = $decade + 9;

        $name_map = [
            10 => '10-19',
            20 => '20-29',
            30 => '30-39',
            40 => '40-49',
            50 => '50-59',
            60 => '60-69',
            70 => '70-79',
            80 => '80-89',
            90 => '90-99',
        ];

        return [
            'code' => $gender_code . $decade . '-' . $max_age,
            'name' => $name_map[$decade] ?? "{$decade}+",
            'min_age' => $decade,
            'max_age' => $decade === 90 ? null : $max_age,
            'gender' => $gender,
        ];
    }

    /**
     * Parse division string to components
     */
    public static function parse_division_string(string $division): array {
        // Handle formats: "M40-44", "W50", "M 35-39", "Masters 40+", etc.

        $division = strtoupper(trim($division));

        // Extract gender; null when the division names none
        $gender = null;
        if (preg_match('/^(W|F)/i', $division)) {
            $gender = 'F';
        } elseif (preg_match('/^M(?!ASTERS)/i', $division)) {
            $gender = 'M';
        }

        // Extract ages
        $min_age = null;
        $max_age = null;

        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/', $division, $matches)) {
            $min_age = (int) $matches[1];
            $max_age = (int) $matches[2];
        } elseif (preg_match('/(\d+)\+/', $division, $matches)) {
            $min_age = (int) $matches[1];
            $max_age = null;
        } elseif (preg_match('/(\d+)/', $division, $matches)) {
            $age = (int) $matches[1];
            // Assume 5-year group
            $min_age = $age;
            $max_age = $age + 4;
        }

        return [
            'gender' => $gender,
            'min_age' => $min_age,
            'max_age' => $max_age,
            'original' => $division,
        ];
    }
}
