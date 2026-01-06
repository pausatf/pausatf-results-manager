<?php
/**
 * USATF Age Divisions
 *
 * Defines age group divisions per USATF Rules (Rule 141, 300-303)
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
     * Youth divisions (Under 20)
     * Per USATF Rule 302
     */
    private const YOUTH_DIVISIONS = [
        'U8' => ['code' => 'U8', 'name' => 'Sub-Bantam', 'min_age' => 0, 'max_age' => 7, 'gender' => 'X'],
        'U10' => ['code' => 'U10', 'name' => 'Bantam', 'min_age' => 8, 'max_age' => 9, 'gender' => 'X'],
        'U12' => ['code' => 'U12', 'name' => 'Midget', 'min_age' => 10, 'max_age' => 11, 'gender' => 'X'],
        'U14' => ['code' => 'U14', 'name' => 'Youth', 'min_age' => 12, 'max_age' => 13, 'gender' => 'X'],
        'U16' => ['code' => 'U16', 'name' => 'Intermediate', 'min_age' => 14, 'max_age' => 15, 'gender' => 'X'],
        'U18' => ['code' => 'U18', 'name' => 'Young', 'min_age' => 16, 'max_age' => 17, 'gender' => 'X'],
        'U20' => ['code' => 'U20', 'name' => 'Junior', 'min_age' => 18, 'max_age' => 19, 'gender' => 'X'],
    ];

    /**
     * Open division (20-34)
     */
    private const OPEN_DIVISIONS = [
        'OPEN' => ['code' => 'OPEN', 'name' => 'Open', 'min_age' => 20, 'max_age' => 34, 'gender' => 'X'],
    ];

    /**
     * Masters divisions (35+)
     * Per USATF Rule 300 - 5-year age groups
     */
    private const MASTERS_DIVISIONS = [
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
     * Rule changes by year
     */
    private const RULE_CHANGES = [
        2020 => [
            'masters_start_age' => 35, // Prior was 40 for some events
            'youth_age_determination' => 'dec31', // Age as of Dec 31
        ],
        2024 => [
            'masters_start_age' => 35,
            'youth_age_determination' => 'dec31',
        ],
    ];

    /**
     * Get all divisions for a given year
     */
    public static function get_divisions(int $year): array {
        $divisions = [];

        // Youth divisions
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
     * Get division for a specific age and gender
     */
    public static function get_division_for_age(int $age, string $gender, int $year): ?array {
        $gender = strtoupper($gender);
        $gender_code = $gender === 'F' ? 'W' : 'M';

        // Youth
        if ($age < 20) {
            foreach (self::YOUTH_DIVISIONS as $code => $division) {
                if ($age >= $division['min_age'] && $age <= $division['max_age']) {
                    return array_merge($division, ['gender' => $gender, 'code' => $code]);
                }
            }
        }

        // Open
        if ($age >= 20 && $age < 35) {
            return array_merge(self::OPEN_DIVISIONS['OPEN'], ['gender' => $gender]);
        }

        // Masters
        $masters_code = $gender_code . (floor($age / 5) * 5);
        if (isset(self::MASTERS_DIVISIONS[$masters_code])) {
            return self::MASTERS_DIVISIONS[$masters_code];
        }

        // 100+
        if ($age >= 100) {
            return self::MASTERS_DIVISIONS[$gender_code . '100'];
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
     * Get all divisions an athlete is eligible for
     */
    public static function get_eligible_divisions(int $age, string $gender, int $year): array {
        $eligible = [];
        $gender = strtoupper($gender);

        // Primary age division
        $primary = self::get_division_for_age($age, $gender, $year);
        if ($primary) {
            $eligible[] = $primary;
        }

        // Masters can compete in open (but scored separately)
        if ($age >= 35) {
            $eligible[] = array_merge(self::OPEN_DIVISIONS['OPEN'], [
                'gender' => $gender,
                'note' => 'May compete in open division',
            ]);
        }

        return $eligible;
    }

    /**
     * Get age determination rule for year
     */
    public static function get_age_determination_rule(int $year): string {
        // USATF Rule 141: Age as of December 31 of competition year
        return 'dec31';
    }

    /**
     * Calculate single-year age groups for road running
     * Per USATF Road Running Technical Council
     */
    public static function get_single_year_division(int $age, string $gender): array {
        $gender_code = strtoupper($gender) === 'F' ? 'W' : 'M';

        return [
            'code' => $gender_code . $age,
            'name' => "Age {$age}",
            'min_age' => $age,
            'max_age' => $age,
            'gender' => strtoupper($gender),
        ];
    }

    /**
     * Get 10-year age groups (common for road races)
     */
    public static function get_10year_division(int $age, string $gender): array {
        $gender_code = strtoupper($gender) === 'F' ? 'W' : 'M';
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
            'gender' => strtoupper($gender),
        ];
    }

    /**
     * Parse division string to components
     */
    public static function parse_division_string(string $division): array {
        // Handle formats: "M40-44", "W50", "M 35-39", "Masters 40+", etc.

        $division = strtoupper(trim($division));

        // Extract gender
        $gender = 'M';
        if (preg_match('/^(W|F)/i', $division)) {
            $gender = 'F';
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
