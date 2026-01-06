<?php
/**
 * USATF Championship Rules
 *
 * Rules specific to various championship types
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Championship-specific rules
 */
class USATFChampionshipRules {
    /**
     * Championship types
     */
    public const CHAMPIONSHIP_TYPES = [
        'outdoor_nationals' => 'USATF Outdoor Championships',
        'indoor_nationals' => 'USATF Indoor Championships',
        'masters_outdoor' => 'USATF Masters Outdoor Championships',
        'masters_indoor' => 'USATF Masters Indoor Championships',
        'junior_olympics' => 'USATF Junior Olympics',
        'youth_outdoor' => 'USATF Youth Outdoor Championships',
        'xc_nationals' => 'USATF Cross Country Championships',
        'club_xc' => 'USATF Club Cross Country Championships',
        'road_championships' => 'USATF Road Running Championships',
        'marathon_championships' => 'USATF Marathon Championships',
        'association' => 'Association Championships',
    ];

    /**
     * Association Championship rules (PA-USATF)
     */
    private const ASSOCIATION_RULES = [
        'membership_required' => true,
        'residence_requirement' => [
            'primary' => 'Must reside in association territory',
            'exception' => 'May compete for association where USATF club is registered',
        ],
        'age_determination' => 'dec31', // Age as of December 31
        'awards' => [
            'individual' => [1, 2, 3], // Places awarded
            'age_group' => [1, 2, 3],
            'team' => [1, 2, 3],
        ],
    ];

    /**
     * Cross Country Championship rules
     */
    private const XC_RULES = [
        'course' => [
            'terrain' => 'natural_grass_preferred',
            'loops' => 'allowed',
            'obstacles' => 'natural_only',
        ],
        'distances' => [
            'open_men' => 10000, // meters
            'open_women' => 6000,
            'masters_men' => 8000,
            'masters_women' => 6000,
            'junior_men' => 8000,
            'junior_women' => 6000,
        ],
        'team_scoring' => [
            'scorers' => 5, // First 5 finishers score
            'max_team_size' => 7,
            'low_score_wins' => true,
            'tiebreaker' => '6th_runner_place',
        ],
    ];

    /**
     * Road running championship rules
     */
    private const ROAD_RULES = [
        'championship_distances' => [
            '5K', '10K', '15K', '10 Miles', '20K',
            'Half Marathon', '25K', '30K', 'Marathon',
        ],
        'course_requirements' => [
            'certified' => true,
            'record_eligible' => [
                'loop_course' => true,
                'point_to_point' => false,
                'max_drop' => 1.0, // meter per km
                'max_separation' => 50, // percent of distance
            ],
        ],
        'team_scoring' => [
            'open' => 3, // First 3 score
            'masters' => 3,
            'grand_masters' => 3,
        ],
    ];

    /**
     * Track & Field Championship rules
     */
    private const TRACK_RULES = [
        'qualifying' => [
            'methods' => ['time_standard', 'ranking', 'descending_order'],
            'declaration_deadline' => 'day_before',
        ],
        'rounds' => [
            'sprints' => ['heats', 'semifinals', 'final'],
            'distance' => ['heats', 'final'],
            'field' => ['qualifying', 'final'],
        ],
        'finals' => [
            'track_finalists' => 8,
            'field_qualifying_standard' => 12, // Advance to final
            'field_finalists' => 8, // After 3 rounds
            'field_final_attempts' => 3, // After qualifying cut
        ],
    ];

    /**
     * Masters-specific rules
     */
    private const MASTERS_RULES = [
        'age_minimum' => 35,
        'age_groups' => '5_year',
        'implements' => 'age_graded', // Lighter implements for older ages
        'hurdle_heights' => 'age_graded',
        'combined_events' => [
            'throws_pentathlon' => true,
            'weight_pentathlon' => true,
        ],
    ];

    /**
     * Get rules for championship type
     */
    public static function get_rules(string $championship_type, int $year): array {
        $rules = [
            'type' => $championship_type,
            'name' => self::CHAMPIONSHIP_TYPES[$championship_type] ?? $championship_type,
            'year' => $year,
        ];

        switch ($championship_type) {
            case 'association':
                $rules = array_merge($rules, self::ASSOCIATION_RULES);
                break;

            case 'xc_nationals':
            case 'club_xc':
                $rules = array_merge($rules, self::XC_RULES);
                break;

            case 'road_championships':
            case 'marathon_championships':
                $rules = array_merge($rules, self::ROAD_RULES);
                break;

            case 'outdoor_nationals':
            case 'indoor_nationals':
                $rules = array_merge($rules, self::TRACK_RULES);
                break;

            case 'masters_outdoor':
            case 'masters_indoor':
                $rules = array_merge($rules, self::MASTERS_RULES, self::TRACK_RULES);
                break;
        }

        return $rules;
    }

    /**
     * Get team scoring rules for event type
     */
    public static function get_team_scoring_rules(string $event_type, int $year): array {
        switch ($event_type) {
            case 'cross_country':
                return self::XC_RULES['team_scoring'];

            case 'road':
                return self::ROAD_RULES['team_scoring'];

            case 'track':
                return [
                    'scoring_places' => [10, 8, 6, 5, 4, 3, 2, 1],
                    'relay_scoring' => [10, 8, 6, 5, 4, 3, 2, 1],
                ];

            default:
                return [];
        }
    }

    /**
     * Get XC team score
     *
     * @param array $places Array of finishing places for team members
     * @return int|null Team score (null if incomplete team)
     */
    public static function calculate_xc_team_score(array $places): ?int {
        $rules = self::XC_RULES['team_scoring'];

        if (count($places) < $rules['scorers']) {
            return null; // Incomplete team
        }

        // Sort places ascending
        sort($places);

        // Sum first N scorers
        $score = 0;
        for ($i = 0; $i < $rules['scorers']; $i++) {
            $score += $places[$i];
        }

        return $score;
    }

    /**
     * Get track/field team score
     */
    public static function calculate_tf_team_score(array $results): int {
        $scoring = [1 => 10, 2 => 8, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1];
        $score = 0;

        foreach ($results as $result) {
            $place = $result['place'] ?? 0;
            if (isset($scoring[$place])) {
                $score += $scoring[$place];
            }
        }

        return $score;
    }

    /**
     * Get championship event schedule template
     */
    public static function get_schedule_template(string $championship_type): array {
        switch ($championship_type) {
            case 'association':
                return [
                    'day_1' => [
                        'track' => ['100m heats', '400m heats', '1500m', '5000m'],
                        'field' => ['Long Jump', 'Shot Put', 'High Jump'],
                    ],
                    'day_2' => [
                        'track' => ['100m final', '400m final', '200m', '800m', '3000m SC'],
                        'field' => ['Triple Jump', 'Discus', 'Pole Vault'],
                    ],
                ];

            case 'xc_nationals':
                return [
                    'saturday' => [
                        '09:00' => 'Junior Women 6K',
                        '09:45' => 'Junior Men 8K',
                        '10:45' => 'Masters Women 6K',
                        '11:30' => 'Masters Men 8K',
                        '13:00' => 'Open Women 6K',
                        '14:00' => 'Open Men 10K',
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Check eligibility for championship
     */
    public static function check_eligibility(
        string $championship_type,
        array $athlete,
        int $year
    ): array {
        $issues = [];

        // Check membership
        if (!($athlete['usatf_member'] ?? false)) {
            $issues[] = 'USATF membership required';
        }

        // Check residence for association championships
        if ($championship_type === 'association') {
            if (empty($athlete['association'])) {
                $issues[] = 'Must be registered with an association';
            }
        }

        // Check age for masters
        if (in_array($championship_type, ['masters_outdoor', 'masters_indoor'])) {
            $age = $athlete['age'] ?? 0;
            if ($age < 35) {
                $issues[] = 'Must be 35 or older for masters championships';
            }
        }

        return [
            'eligible' => empty($issues),
            'issues' => $issues,
        ];
    }
}
