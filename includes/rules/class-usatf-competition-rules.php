<?php
/**
 * USATF Competition Rules
 *
 * Contains competition rules for timing, wind, false starts, etc.
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USATF Competition Rules
 */
class USATFCompetitionRules {
    /**
     * Wind rules per USATF Rule 184
     */
    private const WIND_RULES = [
        'max_legal_speed' => 2.0, // m/s for records
        'affected_events' => [
            '100m' => ['max_wind' => 2.0],
            '200m' => ['max_wind' => 2.0],
            '100m Hurdles' => ['max_wind' => 2.0],
            '110m Hurdles' => ['max_wind' => 2.0],
            'Long Jump' => ['max_wind' => 2.0],
            'Triple Jump' => ['max_wind' => 2.0],
        ],
        'measurement_period' => [
            '100m' => 10, // seconds from gun
            '100m Hurdles' => 13,
            '110m Hurdles' => 13,
        ],
    ];

    /**
     * Timing rules per USATF Rule 165
     */
    private const TIMING_RULES = [
        'fully_automatic' => [
            'required_for' => ['records', 'championships'],
            'precision' => 0.001, // seconds (thousandths)
            'rounding' => 'up_to_hundredth',
        ],
        'hand_timing' => [
            'allowed_for' => ['club_meets', 'small_meets'],
            'precision' => 0.1, // seconds (tenths)
            'conversion_to_fat' => 0.24, // add for 100m-400m comparison
        ],
        'road_racing' => [
            'gun_time' => 'official_for_records',
            'chip_time' => 'official_for_placement',
            'precision' => 1, // seconds
        ],
    ];

    /**
     * False start rules per USATF Rule 162
     */
    private const FALSE_START_RULES = [
        // Current rules (2010+)
        'current' => [
            'allowance' => 0, // One false start = disqualification
            'reaction_time_threshold' => 0.100, // seconds
        ],
        // Historical rules
        'pre_2010' => [
            'allowance' => 1, // First false start warning to all
        ],
    ];

    /**
     * Lane rules
     */
    private const LANE_RULES = [
        'standard_lanes' => 8,
        'lane_width' => 1.22, // meters (minimum)
        'events_in_lanes' => ['100m', '200m', '400m', '100m Hurdles', '110m Hurdles', '400m Hurdles'],
        'break_line' => [
            '800m' => 'after_first_turn',
            '4x400m' => 'after_first_leg',
        ],
    ];

    /**
     * Get wind rules for an event
     */
    public static function get_wind_rules(string $event, int $year): array {
        $rules = self::WIND_RULES;

        if (!isset($rules['affected_events'][$event])) {
            return ['wind_affected' => false];
        }

        return [
            'wind_affected' => true,
            'max_legal' => $rules['affected_events'][$event]['max_wind'],
            'measurement_period' => $rules['measurement_period'][$event] ?? null,
        ];
    }

    /**
     * Get timing precision for context
     */
    public static function get_timing_precision(string $context, int $year): array {
        if (in_array($context, ['records', 'championships', 'national'])) {
            return self::TIMING_RULES['fully_automatic'];
        }

        return self::TIMING_RULES['hand_timing'];
    }

    /**
     * Convert hand time to FAT equivalent
     */
    public static function hand_to_fat(float $hand_time, string $event): float {
        $conversion = self::TIMING_RULES['hand_timing']['conversion_to_fat'];

        // Only applies to sprints (100m-400m)
        $sprint_events = ['100m', '200m', '400m', '100m Hurdles', '110m Hurdles', '400m Hurdles'];

        if (in_array($event, $sprint_events)) {
            return $hand_time + $conversion;
        }

        return $hand_time;
    }

    /**
     * Get false start rules for year
     */
    public static function get_false_start_rules(int $year): array {
        if ($year < 2010) {
            return self::FALSE_START_RULES['pre_2010'];
        }

        return self::FALSE_START_RULES['current'];
    }

    /**
     * Validate reaction time
     */
    public static function is_valid_reaction_time(float $reaction_time, int $year): bool {
        $rules = self::get_false_start_rules($year);
        return $reaction_time >= $rules['reaction_time_threshold'];
    }

    /**
     * Road race certification rules
     */
    public static function get_road_certification_rules(int $year): array {
        return [
            'course_measurement' => [
                'method' => 'calibrated_bicycle',
                'tolerance' => 0.001, // 0.1% short allowed, must not be long
                'separation_from_start' => 0.5, // max 50% of race distance for point-to-point
                'drop_per_km' => 1.0, // max 1m per km net downhill
            ],
            'record_eligibility' => [
                'point_to_point' => false, // Loop/out-and-back required for records
                'net_downhill' => false, // Cannot exceed 1m/km
                'wind_assisted' => false, // Following wind limits apply
            ],
        ];
    }

    /**
     * Get drug testing rules
     */
    public static function get_anti_doping_rules(int $year): array {
        return [
            'governing_body' => 'USADA',
            'code' => 'WADA',
            'in_competition_testing' => true,
            'out_of_competition_testing' => true,
            'therapeutic_use_exemption' => true,
        ];
    }

    /**
     * Get protest/appeal rules
     */
    public static function get_protest_rules(int $year): array {
        return [
            'time_limit' => 30, // minutes from announcement
            'fee' => 50.00, // USD, refunded if upheld
            'levels' => [
                'referee' => 'First level of appeal',
                'jury_of_appeals' => 'Second level',
                'arbitration' => 'Final binding arbitration',
            ],
        ];
    }

    /**
     * Altitude adjustment rules for records
     */
    public static function get_altitude_rules(int $year): array {
        return [
            'threshold' => 1000, // meters above sea level
            'notation_required' => true, // "A" denotes altitude-assisted
            'affected_events' => [
                '100m', '200m', '400m', '800m',
                '100m Hurdles', '110m Hurdles', '400m Hurdles',
                'Long Jump', 'Triple Jump',
            ],
            'not_affected' => [
                '1500m', '1 Mile', '3000m', '5000m', '10000m',
                'High Jump', 'Pole Vault', 'Shot Put', 'Discus', 'Hammer', 'Javelin',
            ],
        ];
    }

    /**
     * Get scoring tables reference
     */
    public static function get_scoring_tables(int $year): array {
        return [
            'iaaf_scoring' => 'IAAF Scoring Tables',
            'age_grading' => 'WMA Age-Grading Tables',
            'decathlon' => '2001 IAAF Combined Events Scoring Tables',
            'heptathlon' => '2001 IAAF Combined Events Scoring Tables',
        ];
    }
}
