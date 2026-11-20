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
     * Road record course limits, 2026 USATF Competition Rules, Rule 265.4 (p. 158-159):
     * (a) the net drop from start to finish is at most 1 part per thousand, i.e. 1 m per km;
     * (b) start and finish are at most 50% of the race distance apart in a straight line.
     * World Athletics Competition Rule 31 sets the same two limits. Point-to-point courses
     * that meet both are record-eligible; the limits govern records only, not certification.
     */
    public const ROAD_RECORD_MAX_DROP_PER_KM = 1.0;
    public const ROAD_RECORD_MAX_SEPARATION = 0.5;

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
            ],
            'record_eligibility' => [
                'point_to_point' => true,
                'max_drop_per_km' => self::ROAD_RECORD_MAX_DROP_PER_KM,
                'max_separation' => self::ROAD_RECORD_MAX_SEPARATION,
                // Rule 265.5: except race walking and Masters, women's road records are
                // kept separately for women-only and mixed races.
                'women_only_and_mixed_separate' => true,
            ],
        ];
    }

    /**
     * Whether a road course meets the Rule 265.4 record limits. A net climb (negative
     * drop) always passes the drop limit; both limits are inclusive.
     *
     * @param float $distance_m   Race distance in metres.
     * @param float $separation_m Straight-line distance between start and finish in metres.
     * @param float $net_drop_m   Start elevation minus finish elevation in metres.
     */
    public static function road_course_meets_record_limits(float $distance_m, float $separation_m, float $net_drop_m): bool {
        if ($distance_m <= 0 || $separation_m < 0) {
            return false;
        }
        return $separation_m <= $distance_m * self::ROAD_RECORD_MAX_SEPARATION
            && $net_drop_m <= ($distance_m / 1000) * self::ROAD_RECORD_MAX_DROP_PER_KM;
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
