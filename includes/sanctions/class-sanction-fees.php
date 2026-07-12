<?php
/**
 * Sanction Fees Calculator - PHP 8.4 Modern Implementation
 *
 * Calculates sanction fees based on USATF fee schedule.
 * Uses PHP 8.4 features for cleaner constant access.
 *
 * @package PAUSATF\Results\Sanctions
 * @since 3.0.0
 * @requires PHP 8.4
 */

declare(strict_types=1);

namespace PAUSATF\Results\Sanctions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanction Fees class with PHP 8.4 features
 */
final class SanctionFees {
    /**
     * USATF fee tiers based on estimated finishers
     * Using PHP 8.4 typed constants
     *
     * @var array<int, array{max: int, national: int, local: int}>
     */
    private const array FEE_TIERS = [
        ['max' => 50, 'national' => 40, 'local' => 20],
        ['max' => 100, 'national' => 50, 'local' => 25],
        ['max' => 200, 'national' => 65, 'local' => 30],
        ['max' => 300, 'national' => 75, 'local' => 35],
        ['max' => 500, 'national' => 100, 'local' => 50],
        ['max' => 750, 'national' => 125, 'local' => 60],
        ['max' => 1000, 'national' => 150, 'local' => 75],
        ['max' => 1500, 'national' => 200, 'local' => 90],
        ['max' => 2000, 'national' => 250, 'local' => 100],
        ['max' => 2500, 'national' => 300, 'local' => 110],
        ['max' => 3000, 'national' => 350, 'local' => 125],
        ['max' => 4000, 'national' => 400, 'local' => 140],
        ['max' => 5000, 'national' => 450, 'local' => 150],
        ['max' => 7500, 'national' => 525, 'local' => 175],
        ['max' => 10000, 'national' => 600, 'local' => 200],
        ['max' => 15000, 'national' => 750, 'local' => 250],
        ['max' => 20000, 'national' => 900, 'local' => 300],
        ['max' => PHP_INT_MAX, 'national' => 1100, 'local' => 350],
    ];

    /**
     * Late submission fee (within 30 days of event)
     * Public for use in property hooks
     */
    public const int LATE_FEE = 50;

    /**
     * Elite event additional fee
     */
    public const int ELITE_FEE = 100;

    /**
     * Days threshold for late submission
     */
    public const int LATE_THRESHOLD_DAYS = 30;

    /**
     * Calculate fees for a sanction
     *
     * @param int  $estimated_finishers Estimated number of finishers
     * @param bool $is_elite            Whether this is an elite event
     * @param bool $is_late             Whether submission is late (within 30 days)
     * @return array Fee breakdown
     */
    public static function calculate(int $estimated_finishers, bool $is_elite = false, bool $is_late = false): array {
        $tier = self::get_tier($estimated_finishers);

        $national = $tier['national'];
        $local = $tier['local'];

        // Add elite fee
        if ($is_elite) {
            $national += self::ELITE_FEE;
        }

        // Add late fee
        $late_fee = 0;
        if ($is_late) {
            $late_fee = self::LATE_FEE;
        }

        $total = $national + $local + $late_fee;

        return [
            'national' => $national,
            'local' => $local,
            'late_fee' => $late_fee,
            'total' => $total,
            'tier_max' => $tier['max'],
            'is_elite' => $is_elite,
            'is_late' => $is_late,
        ];
    }

    /**
     * Get the fee tier for a given finisher count
     *
     * @param int $finishers Number of finishers
     * @return array Fee tier
     */
    private static function get_tier(int $finishers): array {
        foreach (self::FEE_TIERS as $tier) {
            if ($finishers <= $tier['max']) {
                return $tier;
            }
        }

        // Return highest tier as fallback
        return end(self::FEE_TIERS);
    }

    /**
     * Get the full fee schedule
     *
     * @return array Fee schedule with all tiers
     */
    public static function get_fee_schedule(): array {
        $schedule = [];

        foreach (self::FEE_TIERS as $tier) {
            $schedule[] = [
                'max_finishers' => $tier['max'] === PHP_INT_MAX ? '20000+' : $tier['max'],
                'national_fee' => $tier['national'],
                'local_fee' => $tier['local'],
                'total' => $tier['national'] + $tier['local'],
            ];
        }

        return [
            'tiers' => $schedule,
            'late_fee' => self::LATE_FEE,
            'elite_fee' => self::ELITE_FEE,
            'notes' => [
                __('Fees are based on estimated number of finishers.', 'pausatf-results'),
                __('A late fee applies for applications submitted within 30 days of the event.', 'pausatf-results'),
                __('Elite events with prize money over $500 per individual incur an additional fee.', 'pausatf-results'),
                __('Actual fees may be adjusted based on final finisher count.', 'pausatf-results'),
            ],
        ];
    }

    /**
     * Calculate fee adjustment based on actual vs estimated finishers
     *
     * @param int   $estimated Estimated finishers
     * @param int   $actual    Actual finishers
     * @param float $paid_fee  Fee already paid
     * @return array Adjustment details
     */
    public static function calculate_adjustment(int $estimated, int $actual, float $paid_fee): array {
        $estimated_fees = self::calculate($estimated);
        $actual_fees = self::calculate($actual);

        $difference = $actual_fees['total'] - $estimated_fees['total'];

        return [
            'estimated_fee' => $estimated_fees['total'],
            'actual_fee' => $actual_fees['total'],
            'difference' => $difference,
            'action' => $difference > 0 ? 'charge' : ($difference < 0 ? 'refund' : 'none'),
            'amount' => abs($difference),
            'new_tier' => $actual_fees['tier_max'],
            'old_tier' => $estimated_fees['tier_max'],
        ];
    }

    /**
     * Check if submission would be considered late
     *
     * @param string $event_date Event date (Y-m-d format)
     * @return bool Whether submission is late
     */
    public static function is_late_submission(string $event_date): bool {
        $event_timestamp = strtotime($event_date);
        $days_until_event = ($event_timestamp - time()) / DAY_IN_SECONDS;

        return $days_until_event < self::LATE_THRESHOLD_DAYS;
    }

    /**
     * Get fee estimate for display
     *
     * @param int    $finishers  Estimated finishers
     * @param string $event_date Event date
     * @param bool   $is_elite   Whether elite event
     * @return array Formatted fee estimate
     */
    public static function get_estimate(int $finishers, string $event_date = '', bool $is_elite = false): array {
        $is_late = $event_date ? self::is_late_submission($event_date) : false;
        $fees = self::calculate($finishers, $is_elite, $is_late);

        return [
            'national_fee' => number_format($fees['national'], 2),
            'local_fee' => number_format($fees['local'], 2),
            'late_fee' => $is_late ? number_format($fees['late_fee'], 2) : '0.00',
            'total' => number_format($fees['total'], 2),
            'is_late' => $is_late,
            'is_elite' => $is_elite,
            'tier_label' => self::get_tier_label($finishers),
        ];
    }

    /**
     * Get human-readable tier label
     *
     * @param int $finishers Number of finishers
     * @return string Tier label
     */
    private static function get_tier_label(int $finishers): string {
        $prev_max = 0;
        foreach (self::FEE_TIERS as $tier) {
            if ($finishers <= $tier['max']) {
                if ($tier['max'] === PHP_INT_MAX) {
                    return sprintf(__('%d+ finishers', 'pausatf-results'), $prev_max + 1);
                }
                if ($prev_max === 0) {
                    return sprintf(__('1-%d finishers', 'pausatf-results'), $tier['max']);
                }
                return sprintf(__('%d-%d finishers', 'pausatf-results'), $prev_max + 1, $tier['max']);
            }
            $prev_max = $tier['max'];
        }

        return __('Unknown tier', 'pausatf-results');
    }
}
