<?php
/**
 * PRE Tag Parser - For fixed-width column results (1996-2007)
 *
 * @package PAUSATF\Results\Parsers
 */

namespace PAUSATF\Results\Parsers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for PRE/fixed-width formatted results
 */
class ParserPre implements ParserInterface {
    private const PRIORITY = 30;
    private const ID = 'pre';

    private array $column_positions = [];
    private array $column_names = [];

    public function get_priority(): int {
        return self::PRIORITY;
    }

    public function get_id(): string {
        return self::ID;
    }

    public function can_parse(string $html): bool {
        // Must have PRE tags with substantial content
        return preg_match('/<pre[^>]*>.{200,}/is', $html) === 1;
    }

    public function parse(string $html, array $context = []): ParsedResults {
        $results = new ParsedResults();

        // Extract content from PRE tags
        if (!preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $html, $matches)) {
            $results->add_error('No PRE content found');
            return $results;
        }

        $content = $matches[1];

        // Clean content
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = explode("\n", $content);

        // Extract event metadata from top of content
        $this->parse_event_metadata($lines, $results, $html);

        // Detect column structure
        $this->detect_columns($lines);

        // Parse result lines
        $this->parse_result_lines($lines, $results, $context);

        return $results;
    }

    /**
     * Extract event information from beginning of file
     */
    private function parse_event_metadata(array $lines, ParsedResults $results, string $full_html): void {
        // Check HTML title
        if (preg_match('/<title[^>]*>([^<]+)/i', $full_html, $matches)) {
            $title = trim($matches[1]);
            if (strlen($title) > 3 && strtolower($title) !== 'untitled') {
                $results->event_name = $title;
            }
        }

        // Look in first 20 lines for event info
        $header_lines = array_slice($lines, 0, 20);

        foreach ($header_lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                continue;
            }

            // Look for event name in centered/prominent text
            if (strlen($trimmed) < 60 && strlen($trimmed) > 5) {
                // Championships, 5K, 10K, Marathon indicators
                if (preg_match('/(championship|5k|10k|marathon|half|mile|cross\s*country|xc|\d+\s*mile)/i', $trimmed)) {
                    if (empty($results->event_name) || strlen($trimmed) > strlen($results->event_name)) {
                        $results->event_name = $trimmed;
                    }
                }
            }

            // Date patterns
            if (preg_match('/\b(\w+\s+\d{1,2},?\s+\d{4})\b/', $trimmed, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp) {
                    $results->event_date = date('Y-m-d', $timestamp);
                }
            }

            // Location with state abbreviation
            if (preg_match('/([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*),?\s+(?:CA|California)/i', $trimmed, $matches)) {
                $results->event_location = $matches[1] . ', CA';
            }
        }
    }

    /**
     * Detect column positions from header line
     */
    private function detect_columns(array $lines): void {
        $header_keywords = [
            'place' => ['place', 'pos', 'o\'all', 'overall', 'rank'],
            'name' => ['name', 'athlete', 'runner'],
            'bib' => ['bib', 'no', '#'],
            'time' => ['time', 'finish'],
            'sex_place' => ['sex place', 'sexplace', 'sex'],
            'division' => ['div', 'division', 'age group'],
            'division_place' => ['div place', 'divplace'],
            'pace' => ['pace', 'min/mi'],
            'club' => ['club', 'team', 'affil'],
            'city' => ['city', 'location', 'from'],
        ];

        // Find header line (typically has multiple keywords)
        foreach ($lines as $index => $line) {
            $lower = strtolower($line);
            $keyword_count = 0;

            foreach ($header_keywords as $field => $patterns) {
                foreach ($patterns as $pattern) {
                    if (strpos($lower, $pattern) !== false) {
                        $keyword_count++;
                        break;
                    }
                }
            }

            if ($keyword_count >= 3) {
                // This is likely the header line - detect column positions
                $this->parse_header_line($line, $header_keywords);
                break;
            }
        }

        // If no header found, try to infer from data patterns
        if (empty($this->column_positions)) {
            $this->infer_columns_from_data($lines);
        }
    }

    /**
     * Parse header line to get column positions
     */
    private function parse_header_line(string $line, array $keywords): void {
        $this->column_positions = [];
        $this->column_names = [];

        foreach ($keywords as $field => $patterns) {
            foreach ($patterns as $pattern) {
                $pos = stripos($line, $pattern);
                if ($pos !== false) {
                    $this->column_positions[$field] = $pos;
                    $this->column_names[$pos] = $field;
                    break;
                }
            }
        }

        // Sort by position
        ksort($this->column_names);
    }

    /**
     * Infer column structure from data patterns
     */
    private function infer_columns_from_data(array $lines): void {
        // Default column positions for common formats
        $this->column_positions = [
            'place' => 0,
            'name' => 8,
            'bib' => 40,
            'time' => 48,
            'sex_place' => 60,
            'division' => 68,
            'division_place' => 78,
            'pace' => 86,
        ];

        // Try to detect from a data line
        foreach ($lines as $line) {
            if (preg_match('/^\s*\d{1,4}\.?\s+[A-Z][a-z]+/', $line)) {
                // This looks like a data line - refine positions
                $this->refine_columns_from_line($line);
                break;
            }
        }
    }

    /**
     * Refine column positions from actual data line
     */
    private function refine_columns_from_line(string $line): void {
        // Find place number at start
        if (preg_match('/^(\s*)(\d{1,4})\.?\s/', $line, $matches)) {
            $this->column_positions['place'] = strlen($matches[1]);
        }

        // Find time pattern (HH:MM:SS or MM:SS)
        if (preg_match('/\s(\d{1,2}:\d{2}(?::\d{2})?)\s/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->column_positions['time'] = $matches[1][1];
        }

        // Find age group pattern (M25-29, W40-44, etc.)
        if (preg_match('/\s([MWmw]\d{2}-\d{2})\s/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->column_positions['division'] = $matches[1][1];
        }
    }

    /**
     * Parse result lines using detected columns
     */
    private function parse_result_lines(array $lines, ParsedResults $results, array $context): void {
        $in_results = false;
        $current_sex = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                continue;
            }

            // Check for section headers
            if (preg_match('/^-+$/', $trimmed)) {
                $in_results = true;
                continue;
            }

            // Check for sex/division headers
            if (preg_match('/^(?:WOMEN|FEMALE|LADIES)/i', $trimmed)) {
                $current_sex = 'F';
                continue;
            }
            if (preg_match('/^(?:MEN|MALE)/i', $trimmed)) {
                $current_sex = 'M';
                continue;
            }

            // Try to parse as result line
            $result = $this->parse_fixed_width_line($line, $current_sex);
            if ($result) {
                $results->add_result($result);
            }
        }

        // Extract divisions
        $results->divisions = array_unique(array_filter(array_column($results->results, 'division')));
    }

    /**
     * Parse a fixed-width result line
     */
    private function parse_fixed_width_line(string $line, ?string $sex): ?array {
        // Must start with a place number
        if (!preg_match('/^\s*(\d{1,4})\.?\s/', $line, $place_match)) {
            return null;
        }

        $result = [
            'place' => (int) $place_match[1],
            'sex' => $sex,
        ];

        // Extract name (typically after place, before numeric data)
        if (preg_match('/^\s*\d+\.?\s+([A-Za-z][A-Za-z\s,.\'-]+?)(?=\s{2,}|\s+\d|$)/', $line, $name_match)) {
            $name = trim($name_match[1]);
            // Clean up name - remove trailing punctuation and extra spaces
            $name = preg_replace('/[,.\s]+$/', '', $name);
            $name = preg_replace('/\s+/', ' ', $name);

            // Check for city/state suffix
            if (preg_match('/^(.+?),\s*([A-Z]{2}|[A-Z][a-z]+)$/', $name, $parts)) {
                $result['athlete_name'] = trim($parts[1]);
                // $result['city'] = $parts[2];
            } else {
                $result['athlete_name'] = $name;
            }
        }

        // Extract bib number
        if (preg_match('/\s(\d{1,5})\s+\d{1,2}:\d{2}/', $line, $bib_match)) {
            $result['bib'] = $bib_match[1];
        }

        // Extract time (HH:MM:SS or MM:SS format)
        if (preg_match('/\s(\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?)\s/', $line, $time_match)) {
            $result['time_display'] = $time_match[1];
            $result['time_seconds'] = $this->time_to_seconds($time_match[1]);
        }

        // Extract division (M25-29, W40-44, etc.)
        if (preg_match('/\s([MWmw])(\d{2})-?(\d{2})?\s/', $line, $div_match)) {
            $result['sex'] = strtoupper($div_match[1]);
            $age_start = (int) $div_match[2];
            $result['division'] = $this->age_to_division($age_start);
        }

        // Extract pace
        if (preg_match('/\s(\d{1,2}:\d{2})\s*$/', $line, $pace_match)) {
            // Only if it's different from the time
            if (!isset($result['time_display']) || $pace_match[1] !== $result['time_display']) {
                $result['pace'] = $pace_match[1];
            }
        }

        // Validate minimum required fields
        if (empty($result['athlete_name'])) {
            return null;
        }

        return $result;
    }

    /**
     * Convert time string to seconds
     */
    private function time_to_seconds(string $time): ?int {
        $time = trim($time);

        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $time, $matches)) {
            return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
        }

        if (preg_match('/^(\d+):(\d{2})/', $time, $matches)) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        return null;
    }

    /**
     * Convert starting age to division name
     */
    private function age_to_division(int $age): string {
        if ($age < 40) {
            return 'Open';
        } elseif ($age < 50) {
            return 'Masters 40+';
        } elseif ($age < 60) {
            return 'Seniors 50+';
        } elseif ($age < 70) {
            return 'Super-Seniors 60+';
        } elseif ($age < 80) {
            return 'Veterans 70+';
        } else {
            return 'Veterans 80+';
        }
    }
}
