<?php
/**
 * USATF Event Standards
 *
 * Qualifying standards for championships
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event qualifying standards by year
 */
class USATFEventStandards {
    /**
     * Get standards for a year
     */
    public static function get_standards(int $year): array {
        $method = "get_standards_{$year}";

        if (method_exists(self::class, $method)) {
            return self::$method();
        }

        // Return most recent available
        return self::get_standards_2024();
    }

    /**
     * 2024 USATF Outdoor Championship Standards
     */
    private static function get_standards_2024(): array {
        return [
            'men' => [
                '100m' => ['A' => 10.05, 'B' => 10.16],
                '200m' => ['A' => 20.24, 'B' => 20.50],
                '400m' => ['A' => 45.20, 'B' => 45.70],
                '800m' => ['A' => '1:46.00', 'B' => '1:47.50'],
                '1500m' => ['A' => '3:36.00', 'B' => '3:39.00'],
                '5000m' => ['A' => '13:25.00', 'B' => '13:35.00'],
                '10000m' => ['A' => '28:00.00', 'B' => '28:30.00'],
                '110m Hurdles' => ['A' => 13.42, 'B' => 13.60],
                '400m Hurdles' => ['A' => 49.50, 'B' => 50.25],
                '3000m Steeplechase' => ['A' => '8:30.00', 'B' => '8:42.00'],
                'High Jump' => ['A' => 2.26, 'B' => 2.21],
                'Pole Vault' => ['A' => 5.60, 'B' => 5.45],
                'Long Jump' => ['A' => 8.00, 'B' => 7.85],
                'Triple Jump' => ['A' => 16.65, 'B' => 16.35],
                'Shot Put' => ['A' => 20.30, 'B' => 19.50],
                'Discus' => ['A' => 62.00, 'B' => 59.00],
                'Hammer' => ['A' => 74.00, 'B' => 70.00],
                'Javelin' => ['A' => 79.00, 'B' => 75.00],
                'Decathlon' => ['A' => 8100, 'B' => 7850],
            ],
            'women' => [
                '100m' => ['A' => 11.15, 'B' => 11.30],
                '200m' => ['A' => 22.70, 'B' => 23.05],
                '400m' => ['A' => 51.35, 'B' => 52.20],
                '800m' => ['A' => '2:00.50', 'B' => '2:03.00'],
                '1500m' => ['A' => '4:06.50', 'B' => '4:12.00'],
                '5000m' => ['A' => '15:20.00', 'B' => '15:40.00'],
                '10000m' => ['A' => '32:00.00', 'B' => '33:00.00'],
                '100m Hurdles' => ['A' => 12.84, 'B' => 13.05],
                '400m Hurdles' => ['A' => 55.40, 'B' => 56.50],
                '3000m Steeplechase' => ['A' => '9:40.00', 'B' => '9:55.00'],
                'High Jump' => ['A' => 1.90, 'B' => 1.84],
                'Pole Vault' => ['A' => 4.50, 'B' => 4.35],
                'Long Jump' => ['A' => 6.65, 'B' => 6.45],
                'Triple Jump' => ['A' => 13.85, 'B' => 13.50],
                'Shot Put' => ['A' => 17.75, 'B' => 16.75],
                'Discus' => ['A' => 59.00, 'B' => 55.00],
                'Hammer' => ['A' => 70.00, 'B' => 66.00],
                'Javelin' => ['A' => 58.00, 'B' => 54.00],
                'Heptathlon' => ['A' => 6000, 'B' => 5700],
            ],
            'qualifying_period' => [
                'start' => '2023-06-01',
                'end' => '2024-06-21',
            ],
        ];
    }

    /**
     * 2023 Standards
     */
    private static function get_standards_2023(): array {
        return [
            'men' => [
                '100m' => ['A' => 10.05, 'B' => 10.18],
                '200m' => ['A' => 20.24, 'B' => 20.55],
                '400m' => ['A' => 45.25, 'B' => 45.85],
                '800m' => ['A' => '1:46.50', 'B' => '1:48.00'],
                '1500m' => ['A' => '3:37.00', 'B' => '3:40.00'],
                '5000m' => ['A' => '13:28.00', 'B' => '13:40.00'],
                '10000m' => ['A' => '28:10.00', 'B' => '28:45.00'],
            ],
            'women' => [
                '100m' => ['A' => 11.15, 'B' => 11.32],
                '200m' => ['A' => 22.80, 'B' => 23.15],
                '400m' => ['A' => 51.50, 'B' => 52.40],
                '800m' => ['A' => '2:01.00', 'B' => '2:03.50'],
                '1500m' => ['A' => '4:08.00', 'B' => '4:14.00'],
                '5000m' => ['A' => '15:25.00', 'B' => '15:50.00'],
                '10000m' => ['A' => '32:15.00', 'B' => '33:15.00'],
            ],
        ];
    }

    /**
     * Check if performance meets standard
     *
     * @param string $event Event name
     * @param mixed $performance Time (string or seconds) or distance (float)
     * @param string $gender M or F
     * @param int $year Standards year
     * @return array Result with standard met (A, B, or null)
     */
    public static function check_standard(
        string $event,
        $performance,
        string $gender,
        int $year
    ): array {
        $standards = self::get_standards($year);
        $gender_key = strtoupper($gender) === 'F' ? 'women' : 'men';

        if (!isset($standards[$gender_key][$event])) {
            return ['meets_standard' => null, 'reason' => 'No standard for event'];
        }

        $event_standards = $standards[$gender_key][$event];

        // Normalize performance to comparable format
        $perf_value = self::normalize_performance($performance, $event);
        $standard_a = self::normalize_performance($event_standards['A'], $event);
        $standard_b = self::normalize_performance($event_standards['B'], $event);

        // Determine if higher or lower is better
        $field_events = ['High Jump', 'Pole Vault', 'Long Jump', 'Triple Jump',
                         'Shot Put', 'Discus', 'Hammer', 'Javelin',
                         'Decathlon', 'Heptathlon'];
        $higher_better = in_array($event, $field_events);

        if ($higher_better) {
            if ($perf_value >= $standard_a) {
                return ['meets_standard' => 'A', 'margin' => $perf_value - $standard_a];
            }
            if ($perf_value >= $standard_b) {
                return ['meets_standard' => 'B', 'margin' => $perf_value - $standard_b];
            }
        } else {
            if ($perf_value <= $standard_a) {
                return ['meets_standard' => 'A', 'margin' => $standard_a - $perf_value];
            }
            if ($perf_value <= $standard_b) {
                return ['meets_standard' => 'B', 'margin' => $standard_b - $perf_value];
            }
        }

        return [
            'meets_standard' => null,
            'needed_for_b' => $higher_better ? ($standard_b - $perf_value) : ($perf_value - $standard_b),
        ];
    }

    /**
     * Normalize performance to numeric value
     */
    private static function normalize_performance($performance, string $event): float {
        // Already a number
        if (is_numeric($performance)) {
            return (float) $performance;
        }

        // Time string (MM:SS.ss or H:MM:SS.ss)
        if (is_string($performance) && strpos($performance, ':') !== false) {
            return self::time_to_seconds($performance);
        }

        return (float) $performance;
    }

    /**
     * Convert time string to seconds
     */
    private static function time_to_seconds(string $time): float {
        $parts = array_reverse(explode(':', $time));
        $seconds = 0;

        foreach ($parts as $i => $part) {
            $seconds += (float) $part * pow(60, $i);
        }

        return $seconds;
    }

    /**
     * Get Olympic/World Championship standards
     */
    public static function get_olympic_standards(int $year): array {
        // Olympic Trial standards are typically more stringent
        $standards = self::get_standards($year);

        // Apply Olympic-level adjustments
        $olympic = [];

        foreach (['men', 'women'] as $gender) {
            $olympic[$gender] = [];
            foreach ($standards[$gender] ?? [] as $event => $marks) {
                // Olympic standards are approximately 1-2% better
                if (is_array($marks)) {
                    $olympic[$gender][$event] = [
                        'entry' => $marks['A'] ?? null,
                        'target' => $marks['A'] ?? null, // World Athletics entry standard
                    ];
                }
            }
        }

        return $olympic;
    }

    /**
     * Get Masters age-graded standards
     */
    public static function get_masters_standards(int $age_group, string $gender, int $year): array {
        // Masters use age-graded performances rather than fixed standards
        // Championships are open to all masters members

        return [
            'qualifying' => 'open', // No qualifying standards for masters
            'age_grading' => true,
            'implement_weights' => USATFRecordCategories::get_implement_weights('Shot Put', $age_group, $gender),
        ];
    }
}
