<?php
/**
 * USATF Record Categories
 *
 * Defines record categories and standards per USATF Rules
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USATF Record Categories - defines what records can be set
 */
class USATFRecordCategories {
    /**
     * Record types
     */
    public const RECORD_TYPES = [
        'american' => 'American Record',
        'usatf' => 'USATF Championship Record',
        'association' => 'Association Record',
        'meet' => 'Meet Record',
        'facility' => 'Facility Record',
        'club' => 'Club Record',
    ];

    /**
     * Venue types
     */
    public const VENUE_TYPES = [
        'outdoor' => 'Outdoor',
        'indoor' => 'Indoor',
        'road' => 'Road',
        'trail' => 'Trail',
        'xc' => 'Cross Country',
    ];

    /**
     * Standard track events (per USATF Rule 260)
     */
    public const TRACK_EVENTS = [
        // Sprints
        '60m' => ['distance' => 60, 'unit' => 'm', 'type' => 'sprint', 'indoor_only' => true],
        '100m' => ['distance' => 100, 'unit' => 'm', 'type' => 'sprint', 'outdoor_only' => true],
        '200m' => ['distance' => 200, 'unit' => 'm', 'type' => 'sprint'],
        '400m' => ['distance' => 400, 'unit' => 'm', 'type' => 'sprint'],

        // Middle Distance
        '800m' => ['distance' => 800, 'unit' => 'm', 'type' => 'middle'],
        '1500m' => ['distance' => 1500, 'unit' => 'm', 'type' => 'middle'],
        '1 Mile' => ['distance' => 1609.34, 'unit' => 'm', 'type' => 'middle'],
        '3000m' => ['distance' => 3000, 'unit' => 'm', 'type' => 'middle'],

        // Long Distance
        '5000m' => ['distance' => 5000, 'unit' => 'm', 'type' => 'distance'],
        '10000m' => ['distance' => 10000, 'unit' => 'm', 'type' => 'distance'],

        // Hurdles
        '60m Hurdles' => ['distance' => 60, 'unit' => 'm', 'type' => 'hurdles', 'indoor_only' => true],
        '100m Hurdles' => ['distance' => 100, 'unit' => 'm', 'type' => 'hurdles', 'outdoor_only' => true, 'women_only' => true],
        '110m Hurdles' => ['distance' => 110, 'unit' => 'm', 'type' => 'hurdles', 'outdoor_only' => true, 'men_only' => true],
        '400m Hurdles' => ['distance' => 400, 'unit' => 'm', 'type' => 'hurdles'],

        // Steeplechase
        '3000m Steeplechase' => ['distance' => 3000, 'unit' => 'm', 'type' => 'steeplechase', 'outdoor_only' => true],

        // Race Walk
        '3000m Race Walk' => ['distance' => 3000, 'unit' => 'm', 'type' => 'racewalk', 'indoor_only' => true],
        '5000m Race Walk' => ['distance' => 5000, 'unit' => 'm', 'type' => 'racewalk'],
        '10000m Race Walk' => ['distance' => 10000, 'unit' => 'm', 'type' => 'racewalk'],
        '20000m Race Walk' => ['distance' => 20000, 'unit' => 'm', 'type' => 'racewalk'],
    ];

    /**
     * Field events
     */
    public const FIELD_EVENTS = [
        // Jumps
        'High Jump' => ['type' => 'vertical', 'unit' => 'm', 'higher_better' => true],
        'Pole Vault' => ['type' => 'vertical', 'unit' => 'm', 'higher_better' => true],
        'Long Jump' => ['type' => 'horizontal', 'unit' => 'm', 'higher_better' => true],
        'Triple Jump' => ['type' => 'horizontal', 'unit' => 'm', 'higher_better' => true],

        // Throws
        'Shot Put' => ['type' => 'throw', 'unit' => 'm', 'higher_better' => true],
        'Discus' => ['type' => 'throw', 'unit' => 'm', 'higher_better' => true, 'outdoor_only' => true],
        'Hammer' => ['type' => 'throw', 'unit' => 'm', 'higher_better' => true, 'outdoor_only' => true],
        'Javelin' => ['type' => 'throw', 'unit' => 'm', 'higher_better' => true, 'outdoor_only' => true],
        'Weight Throw' => ['type' => 'throw', 'unit' => 'm', 'higher_better' => true, 'indoor_only' => true],
    ];

    /**
     * Road running events
     */
    public const ROAD_EVENTS = [
        '5K' => ['distance' => 5, 'unit' => 'km'],
        '8K' => ['distance' => 8, 'unit' => 'km'],
        '10K' => ['distance' => 10, 'unit' => 'km'],
        '15K' => ['distance' => 15, 'unit' => 'km'],
        '10 Miles' => ['distance' => 10, 'unit' => 'mi'],
        '20K' => ['distance' => 20, 'unit' => 'km'],
        'Half Marathon' => ['distance' => 21.0975, 'unit' => 'km'],
        '25K' => ['distance' => 25, 'unit' => 'km'],
        '30K' => ['distance' => 30, 'unit' => 'km'],
        'Marathon' => ['distance' => 42.195, 'unit' => 'km'],
        '50K' => ['distance' => 50, 'unit' => 'km'],
        '100K' => ['distance' => 100, 'unit' => 'km'],
        '50 Miles' => ['distance' => 50, 'unit' => 'mi'],
        '100 Miles' => ['distance' => 100, 'unit' => 'mi'],
    ];

    /**
     * Combined events
     */
    public const COMBINED_EVENTS = [
        'Pentathlon' => ['events' => 5, 'indoor_only' => true],
        'Heptathlon' => ['events' => 7, 'outdoor_only' => true, 'women_only' => true],
        'Decathlon' => ['events' => 10, 'outdoor_only' => true, 'men_only' => true],
    ];

    /**
     * Get all record categories for a year
     */
    public static function get_categories(int $year): array {
        return [
            'record_types' => self::RECORD_TYPES,
            'venue_types' => self::VENUE_TYPES,
            'track_events' => self::TRACK_EVENTS,
            'field_events' => self::FIELD_EVENTS,
            'road_events' => self::ROAD_EVENTS,
            'combined_events' => self::COMBINED_EVENTS,
        ];
    }

    /**
     * Get record standards for event/division
     */
    public static function get_record_standards(
        string $event,
        string $division_code,
        string $venue_type,
        int $year
    ): ?array {
        // Check if event exists
        $all_events = array_merge(
            self::TRACK_EVENTS,
            self::FIELD_EVENTS,
            self::ROAD_EVENTS,
            self::COMBINED_EVENTS
        );

        if (!isset($all_events[$event])) {
            return null;
        }

        $event_config = $all_events[$event];

        // Check venue restrictions
        if (($event_config['indoor_only'] ?? false) && $venue_type !== 'indoor') {
            return null;
        }
        if (($event_config['outdoor_only'] ?? false) && $venue_type !== 'outdoor') {
            return null;
        }

        return [
            'event' => $event,
            'division' => $division_code,
            'venue' => $venue_type,
            'config' => $event_config,
            'current_record' => null, // Would be populated from database
            'record_holder' => null,
            'record_date' => null,
        ];
    }

    /**
     * Get all events for a division
     */
    public static function get_events_for_division(string $division_code, int $year): array {
        $division = USATFAgeDivisions::get_division_by_code($division_code, $year);
        if (!$division) {
            return [];
        }

        $gender = $division['gender'];
        $events = [];

        // Track events
        foreach (self::TRACK_EVENTS as $name => $config) {
            if (($config['men_only'] ?? false) && $gender === 'F') continue;
            if (($config['women_only'] ?? false) && $gender === 'M') continue;
            $events['track'][] = $name;
        }

        // Field events
        foreach (self::FIELD_EVENTS as $name => $config) {
            $events['field'][] = $name;
        }

        // Road events
        foreach (self::ROAD_EVENTS as $name => $config) {
            $events['road'][] = $name;
        }

        return $events;
    }

    /**
     * Check if event requires wind reading for records
     */
    public static function requires_wind_reading(string $event): bool {
        $wind_affected = [
            '100m', '200m', '100m Hurdles', '110m Hurdles',
            'Long Jump', 'Triple Jump',
        ];

        return in_array($event, $wind_affected);
    }

    /**
     * Get implement specifications by age/gender
     * Per USATF Rules and WMA standards
     */
    public static function get_implement_weights(string $event, int $age, string $gender): ?array {
        $gender = strtoupper($gender);

        $weights = [
            'Shot Put' => [
                'M' => [
                    'open' => 7.26, // kg
                    'M50' => 6.0,
                    'M60' => 5.0,
                    'M70' => 4.0,
                    'M80' => 3.0,
                ],
                'F' => [
                    'open' => 4.0,
                    'W50' => 3.0,
                    'W60' => 3.0,
                    'W70' => 2.0,
                    'W80' => 2.0,
                ],
            ],
            'Discus' => [
                'M' => [
                    'open' => 2.0,
                    'M50' => 1.5,
                    'M60' => 1.0,
                    'M70' => 1.0,
                    'M80' => 1.0,
                ],
                'F' => [
                    'open' => 1.0,
                    'W50' => 1.0,
                    'W60' => 1.0,
                    'W70' => 0.75,
                    'W80' => 0.75,
                ],
            ],
            'Hammer' => [
                'M' => [
                    'open' => 7.26,
                    'M50' => 6.0,
                    'M60' => 5.0,
                    'M70' => 4.0,
                    'M80' => 3.0,
                ],
                'F' => [
                    'open' => 4.0,
                    'W50' => 3.0,
                    'W60' => 3.0,
                    'W70' => 2.0,
                    'W80' => 2.0,
                ],
            ],
            'Javelin' => [
                'M' => [
                    'open' => 800, // grams
                    'M50' => 700,
                    'M60' => 600,
                    'M70' => 500,
                    'M80' => 400,
                ],
                'F' => [
                    'open' => 600,
                    'W50' => 500,
                    'W60' => 500,
                    'W70' => 400,
                    'W80' => 400,
                ],
            ],
            'Weight Throw' => [
                'M' => [
                    'open' => 15.88, // 35 lb
                    'M50' => 11.34, // 25 lb
                    'M60' => 9.08, // 20 lb
                    'M70' => 7.26, // 16 lb
                    'M80' => 5.45, // 12 lb
                ],
                'F' => [
                    'open' => 9.08, // 20 lb
                    'W50' => 7.26,
                    'W60' => 5.45,
                    'W70' => 4.54,
                    'W80' => 4.0,
                ],
            ],
        ];

        if (!isset($weights[$event][$gender])) {
            return null;
        }

        $age_group = 'open';
        if ($age >= 80) $age_group = $gender . '80';
        elseif ($age >= 70) $age_group = $gender . '70';
        elseif ($age >= 60) $age_group = $gender . '60';
        elseif ($age >= 50) $age_group = $gender . '50';

        $weight = $weights[$event][$gender][$age_group] ?? $weights[$event][$gender]['open'];

        return [
            'event' => $event,
            'weight' => $weight,
            'unit' => $event === 'Javelin' ? 'g' : 'kg',
            'age_group' => $age_group,
        ];
    }

    /**
     * Get hurdle specifications by age/gender
     */
    public static function get_hurdle_specs(string $event, int $age, string $gender): ?array {
        $gender = strtoupper($gender);

        // Heights in meters
        $specs = [
            '100m Hurdles' => [ // Women only
                'height' => 0.838,
                'spacing' => [13.0, 8.5, 10.5], // to first, between, to finish
            ],
            '110m Hurdles' => [ // Men only
                'M' => [
                    'open' => ['height' => 1.067],
                    'M40' => ['height' => 0.991],
                    'M50' => ['height' => 0.914],
                    'M60' => ['height' => 0.838],
                    'M70' => ['height' => 0.762],
                    'M80' => ['height' => 0.686],
                ],
            ],
            '400m Hurdles' => [
                'M' => ['height' => 0.914],
                'F' => ['height' => 0.762],
            ],
        ];

        // Return appropriate specs based on event/age/gender
        if (isset($specs[$event]['M'][$age >= 40 ? 'M' . (floor($age / 10) * 10) : 'open'])) {
            return $specs[$event]['M'][$age >= 40 ? 'M' . (floor($age / 10) * 10) : 'open'];
        }

        if (isset($specs[$event][$gender])) {
            return $specs[$event][$gender];
        }

        return $specs[$event] ?? null;
    }
}
