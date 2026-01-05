<?php
/**
 * HTML Table Parser - For clean HTML table results (2008+)
 *
 * @package PAUSATF\Results\Parsers
 */

namespace PAUSATF\Results\Parsers;

use DOMDocument;
use DOMXPath;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for HTML table formatted results
 */
class ParserTable implements ParserInterface {
    private const PRIORITY = 20;
    private const ID = 'table';

    public function get_priority(): int {
        return self::PRIORITY;
    }

    public function get_id(): string {
        return self::ID;
    }

    public function can_parse(string $html): bool {
        // Must have tables and NOT be Word HTML
        $has_tables = preg_match('/<table[^>]*>.*?<tr.*?>.*?<td/is', $html) === 1;
        $is_word = stripos($html, 'urn:schemas-microsoft-com:office') !== false;

        return $has_tables && !$is_word;
    }

    public function parse(string $html, array $context = []): ParsedResults {
        $results = new ParsedResults();

        // Extract event metadata
        $this->parse_event_metadata($html, $results);

        // Parse division sections and results
        $this->parse_divisions_and_results($html, $results, $context);

        return $results;
    }

    /**
     * Extract event name, date, location from HTML
     */
    private function parse_event_metadata(string $html, ParsedResults $results): void {
        // Try to find event name in headers/titles
        if (preg_match('/<(?:h[12]|title|font[^>]*size=["\']?[4-7])[^>]*>([^<]+)/i', $html, $matches)) {
            $results->event_name = trim(strip_tags($matches[1]));
        }

        // Look for date patterns
        $date_patterns = [
            '/(\w+ \d{1,2},?\s*\d{4})/i',                    // November 10, 2024
            '/(\d{1,2}\/\d{1,2}\/\d{2,4})/',                 // 11/10/2024
            '/(\d{4}-\d{2}-\d{2})/',                         // 2024-11-10
            '/(\w+ \d{1,2}(?:st|nd|rd|th)?,?\s*\d{4})/i',   // November 10th, 2024
        ];

        foreach ($date_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp) {
                    $results->event_date = date('Y-m-d', $timestamp);
                    break;
                }
            }
        }

        // Try to find location
        if (preg_match('/(?:in |at |location:|venue:)\s*([A-Z][a-z]+(?:[\s,]+[A-Z][a-z]+)*)/i', $html, $matches)) {
            $results->event_location = trim($matches[1]);
        }
    }

    /**
     * Parse division headers and result tables
     */
    private function parse_divisions_and_results(string $html, ParsedResults $results, array $context): void {
        // Suppress libxml errors
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Find all tables
        $tables = $xpath->query('//table');

        $current_division = 'Open';
        $current_sex = null;

        foreach ($tables as $table) {
            // Check for division header before this table
            $previous = $table->previousSibling;
            while ($previous) {
                if ($previous->nodeType === XML_ELEMENT_NODE) {
                    $text = strtolower(trim($previous->textContent));

                    // Check for division markers
                    if (preg_match('/(open|masters|seniors?|super-seniors?|veterans?|40\+|50\+|60\+|70\+|80\+)/i', $text, $div_match)) {
                        $current_division = $this->normalize_division($div_match[1]);
                    }

                    // Check for sex markers
                    if (preg_match('/\b(men|women|male|female)\b/i', $text, $sex_match)) {
                        $current_sex = strtolower($sex_match[1]) === 'women' || strtolower($sex_match[1]) === 'female' ? 'F' : 'M';
                    }

                    break;
                }
                $previous = $previous->previousSibling;
            }

            // Parse table rows
            $rows = $xpath->query('.//tr', $table);
            $headers = [];
            $is_first_row = true;

            foreach ($rows as $row) {
                $cells = $xpath->query('.//td|.//th', $row);
                $cell_values = [];

                foreach ($cells as $cell) {
                    $cell_values[] = trim(preg_replace('/\s+/', ' ', $cell->textContent));
                }

                // Detect header row
                if ($is_first_row && $this->is_header_row($cell_values)) {
                    $headers = $this->normalize_headers($cell_values);
                    $is_first_row = false;
                    continue;
                }
                $is_first_row = false;

                // Skip empty rows
                if (empty(array_filter($cell_values))) {
                    continue;
                }

                // Parse result row
                $result = $this->parse_result_row($cell_values, $headers, $current_division, $current_sex);
                if ($result) {
                    $results->add_result($result);
                }
            }
        }

        // Extract unique divisions
        $results->divisions = array_unique(array_column($results->results, 'division'));

        libxml_clear_errors();
    }

    /**
     * Check if row contains headers
     */
    private function is_header_row(array $cells): bool {
        $header_keywords = ['place', 'name', 'time', 'points', 'age', 'div', 'bib', 'pace', 'club'];
        $lower_cells = array_map('strtolower', $cells);

        $matches = 0;
        foreach ($lower_cells as $cell) {
            foreach ($header_keywords as $keyword) {
                if (strpos($cell, $keyword) !== false) {
                    $matches++;
                }
            }
        }

        return $matches >= 2;
    }

    /**
     * Normalize header names to standard format
     */
    private function normalize_headers(array $headers): array {
        $normalized = [];
        $mapping = [
            'place' => ['place', 'pos', 'position', 'rank', 'o\'all', 'overall', 'o all'],
            'athlete_name' => ['name', 'athlete', 'runner', 'participant'],
            'athlete_age' => ['age'],
            'time_display' => ['time', 'finish', 'chip', 'gun'],
            'points' => ['points', 'pts', 'score'],
            'division' => ['div', 'division', 'category', 'cat'],
            'division_place' => ['div place', 'divplace', 'cat place'],
            'club' => ['club', 'team', 'affiliation', 'org'],
            'bib' => ['bib', 'number', 'no', '#'],
            'pace' => ['pace', 'min/mi', 'min/km'],
            'sex' => ['sex', 'gender', 'm/f'],
        ];

        foreach ($headers as $index => $header) {
            $lower = strtolower(trim($header));

            foreach ($mapping as $standard => $variants) {
                foreach ($variants as $variant) {
                    if (strpos($lower, $variant) !== false) {
                        $normalized[$index] = $standard;
                        break 2;
                    }
                }
            }

            // Handle combined name/age columns
            if (strpos($lower, 'name') !== false && strpos($lower, 'age') !== false) {
                $normalized[$index] = 'name_age';
            }

            // Default to index-based
            if (!isset($normalized[$index])) {
                $normalized[$index] = 'column_' . $index;
            }
        }

        return $normalized;
    }

    /**
     * Parse a result row into structured data
     */
    private function parse_result_row(array $cells, array $headers, string $division, ?string $sex): ?array {
        if (count($cells) < 2) {
            return null;
        }

        $result = [
            'division' => $division,
            'sex' => $sex,
        ];

        // Map cells to headers
        foreach ($cells as $index => $value) {
            $field = $headers[$index] ?? 'column_' . $index;

            if ($field === 'place') {
                $result['place'] = (int) preg_replace('/\D/', '', $value);
            } elseif ($field === 'athlete_name') {
                $result['athlete_name'] = $value;
            } elseif ($field === 'name_age') {
                // Parse combined name/age like "John Smith, 35"
                if (preg_match('/^(.+?),?\s*(\d{1,3})$/', $value, $matches)) {
                    $result['athlete_name'] = trim($matches[1]);
                    $result['athlete_age'] = (int) $matches[2];
                } else {
                    $result['athlete_name'] = $value;
                }
            } elseif ($field === 'athlete_age') {
                $result['athlete_age'] = (int) preg_replace('/\D/', '', $value);
            } elseif ($field === 'time_display') {
                $result['time_display'] = $value;
                $result['time_seconds'] = $this->time_to_seconds($value);
            } elseif ($field === 'points') {
                // Extract points and payout from "150 ($300)"
                if (preg_match('/^([\d.]+)\s*(?:\(\$?([\d,.]+)\))?/', $value, $matches)) {
                    $result['points'] = (float) $matches[1];
                    if (isset($matches[2])) {
                        $result['payout'] = (float) str_replace(',', '', $matches[2]);
                    }
                }
            } elseif ($field === 'club') {
                $result['club'] = $value;
            } elseif ($field === 'bib') {
                $result['bib'] = $value;
            } elseif ($field === 'pace') {
                $result['pace'] = $value;
            } elseif ($field === 'division') {
                $result['division'] = $this->normalize_division($value);
            } elseif ($field === 'division_place') {
                $result['division_place'] = (int) preg_replace('/\D/', '', $value);
            } elseif ($field === 'sex') {
                $result['sex'] = strtoupper(substr(trim($value), 0, 1));
            }
        }

        // Validate required fields
        if (empty($result['athlete_name']) || $result['athlete_name'] === '') {
            return null;
        }

        return $result;
    }

    /**
     * Convert time string to seconds
     */
    private function time_to_seconds(string $time): ?int {
        $time = trim($time);

        // Handle HH:MM:SS
        if (preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/', $time, $matches)) {
            return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
        }

        // Handle MM:SS
        if (preg_match('/^(\d+):(\d{2})(?:\.\d+)?$/', $time, $matches)) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        // Handle seconds only
        if (preg_match('/^(\d+)(?:\.\d+)?$/', $time, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Normalize division names
     */
    private function normalize_division(string $division): string {
        $lower = strtolower(trim($division));

        $mappings = [
            'open' => 'Open',
            'masters' => 'Masters 40+',
            '40+' => 'Masters 40+',
            '40-49' => 'Masters 40+',
            'seniors' => 'Seniors 50+',
            '50+' => 'Seniors 50+',
            '50-59' => 'Seniors 50+',
            'super-seniors' => 'Super-Seniors 60+',
            'super seniors' => 'Super-Seniors 60+',
            '60+' => 'Super-Seniors 60+',
            '60-69' => 'Super-Seniors 60+',
            'veterans' => 'Veterans 70+',
            '70+' => 'Veterans 70+',
            '70-79' => 'Veterans 70+',
            '80+' => 'Veterans 80+',
            '80-89' => 'Veterans 80+',
        ];

        foreach ($mappings as $pattern => $normalized) {
            if (strpos($lower, $pattern) !== false) {
                return $normalized;
            }
        }

        return ucfirst($division);
    }
}
