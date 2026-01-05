<?php
/**
 * Parser Interface
 *
 * @package PAUSATF\Results\Parsers
 */

namespace PAUSATF\Results\Parsers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for HTML result parsers
 */
interface ParserInterface {
    /**
     * Check if this parser can handle the given HTML content
     *
     * @param string $html Raw HTML content
     * @return bool
     */
    public function can_parse(string $html): bool;

    /**
     * Parse HTML content and extract results
     *
     * @param string $html Raw HTML content
     * @param array  $context Additional context (source_url, year, event_type, etc.)
     * @return ParsedResults
     */
    public function parse(string $html, array $context = []): ParsedResults;

    /**
     * Get parser priority (lower = higher priority)
     *
     * @return int
     */
    public function get_priority(): int;

    /**
     * Get parser identifier
     *
     * @return string
     */
    public function get_id(): string;
}

/**
 * Parsed results data structure
 */
class ParsedResults {
    public string $event_name = '';
    public ?string $event_date = null;
    public ?string $event_location = null;
    public string $event_type = '';
    public array $divisions = [];
    public array $results = [];
    public array $metadata = [];
    public array $warnings = [];
    public array $errors = [];

    public function add_result(array $result): void {
        $this->results[] = array_merge([
            'place' => null,
            'athlete_name' => '',
            'athlete_age' => null,
            'time_display' => null,
            'time_seconds' => null,
            'points' => null,
            'payout' => null,
            'division' => null,
            'division_place' => null,
            'club' => null,
            'bib' => null,
            'pace' => null,
            'sex' => null,
        ], $result);
    }

    public function add_warning(string $message): void {
        $this->warnings[] = $message;
    }

    public function add_error(string $message): void {
        $this->errors[] = $message;
    }

    public function has_errors(): bool {
        return !empty($this->errors);
    }

    public function get_result_count(): int {
        return count($this->results);
    }

    public function to_array(): array {
        return [
            'event_name' => $this->event_name,
            'event_date' => $this->event_date,
            'event_location' => $this->event_location,
            'event_type' => $this->event_type,
            'divisions' => $this->divisions,
            'results' => $this->results,
            'metadata' => $this->metadata,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'result_count' => $this->get_result_count(),
        ];
    }
}
