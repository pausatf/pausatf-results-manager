<?php
/**
 * USATF Rules Engine
 *
 * Contains all USATF competition rules organized by year and category.
 * Based on the USATF Competition Rules book.
 *
 * @package PAUSATF\Results\Rules
 */

namespace PAUSATF\Results\Rules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * USATF Rules Engine - Main class for rule lookups and validation
 */
class USATFRulesEngine {
    /**
     * Current rules year
     */
    private int $rules_year;

    /**
     * Singleton instance
     */
    private static ?USATFRulesEngine $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance(): USATFRulesEngine {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(int $year = null) {
        $this->rules_year = $year ?? (int) date('Y');
    }

    /**
     * Set the rules year for lookups
     */
    public function set_year(int $year): self {
        $this->rules_year = $year;
        return $this;
    }

    /**
     * Get age divisions configuration
     */
    public function get_age_divisions(): array {
        return USATFAgeDivisions::get_divisions($this->rules_year);
    }

    /**
     * Get age division for a given age
     */
    public function get_division_for_age(int $age, string $gender = 'M'): ?array {
        return USATFAgeDivisions::get_division_for_age($age, $gender, $this->rules_year);
    }

    /**
     * Get all record categories
     */
    public function get_record_categories(): array {
        return USATFRecordCategories::get_categories($this->rules_year);
    }

    /**
     * Get event standards
     */
    public function get_event_standards(): array {
        return USATFEventStandards::get_standards($this->rules_year);
    }

    /**
     * Get championship rules
     */
    public function get_championship_rules(string $championship_type): array {
        return USATFChampionshipRules::get_rules($championship_type, $this->rules_year);
    }

    /**
     * Calculate competition age per USATF Rule 141
     * Age as of December 31 of the competition year
     */
    public function calculate_competition_age(\DateTime $birth_date, int $competition_year = null): int {
        $competition_year = $competition_year ?? $this->rules_year;
        $dec31 = new \DateTime("{$competition_year}-12-31");

        return $birth_date->diff($dec31)->y;
    }

    /**
     * Validate if athlete is eligible for division
     */
    public function validate_division_eligibility(
        int $athlete_age,
        string $division_code,
        string $gender
    ): array {
        $division = USATFAgeDivisions::get_division_by_code($division_code, $this->rules_year);

        if (!$division) {
            return ['eligible' => false, 'reason' => 'Invalid division code'];
        }

        // Check gender
        if ($division['gender'] !== 'X' && $division['gender'] !== $gender) {
            return ['eligible' => false, 'reason' => 'Gender mismatch'];
        }

        // Check age bounds
        if ($athlete_age < $division['min_age']) {
            return ['eligible' => false, 'reason' => 'Below minimum age'];
        }

        if ($division['max_age'] !== null && $athlete_age > $division['max_age']) {
            return ['eligible' => false, 'reason' => 'Above maximum age'];
        }

        return ['eligible' => true];
    }

    /**
     * Get all applicable divisions for an athlete
     */
    public function get_eligible_divisions(int $age, string $gender): array {
        return USATFAgeDivisions::get_eligible_divisions($age, $gender, $this->rules_year);
    }

    /**
     * Check if a performance meets record standards
     */
    public function check_record_eligibility(
        string $event,
        float $performance,
        string $division_code,
        string $venue_type = 'outdoor'
    ): array {
        $standards = USATFRecordCategories::get_record_standards(
            $event,
            $division_code,
            $venue_type,
            $this->rules_year
        );

        if (!$standards) {
            return ['eligible' => false, 'reason' => 'No record category for this event/division'];
        }

        $current_record = $standards['current_record'] ?? null;

        return [
            'eligible' => true,
            'is_record' => $current_record === null || $this->is_better_performance($event, $performance, $current_record),
            'current_record' => $current_record,
            'record_holder' => $standards['record_holder'] ?? null,
            'record_date' => $standards['record_date'] ?? null,
        ];
    }

    /**
     * Compare performances (handles time vs distance events)
     */
    private function is_better_performance(string $event, float $performance, float $current_record): bool {
        // Field events (higher is better)
        $field_events = ['Long Jump', 'Triple Jump', 'High Jump', 'Pole Vault',
                         'Shot Put', 'Discus', 'Javelin', 'Hammer', 'Weight Throw'];

        if (in_array($event, $field_events)) {
            return $performance > $current_record;
        }

        // Track/road events (lower is better)
        return $performance < $current_record;
    }

    /**
     * Get wind assistance rules
     */
    public function get_wind_rules(string $event): array {
        return USATFCompetitionRules::get_wind_rules($event, $this->rules_year);
    }

    /**
     * Validate wind reading for records
     */
    public function is_wind_legal(string $event, float $wind_speed): bool {
        $rules = $this->get_wind_rules($event);
        return $wind_speed <= ($rules['max_legal'] ?? 2.0);
    }

    /**
     * Get available rules years
     */
    public static function get_available_years(): array {
        return range(2020, (int) date('Y'));
    }
}
