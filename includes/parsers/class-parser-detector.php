<?php
/**
 * Parser Detector - Auto-detects HTML format and selects appropriate parser
 *
 * @package PAUSATF\Results\Parsers
 */

namespace PAUSATF\Results\Parsers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects HTML format and returns the appropriate parser
 */
class ParserDetector {
    private array $parsers = [];

    public function __construct() {
        $this->register_default_parsers();
    }

    /**
     * Register default parsers in priority order
     */
    private function register_default_parsers(): void {
        $this->register_parser(new ParserWord());  // Priority 10 - handle Word HTML first
        $this->register_parser(new ParserTable()); // Priority 20 - clean HTML tables
        $this->register_parser(new ParserPre());   // Priority 30 - PRE/fixed-width fallback
    }

    /**
     * Register a parser
     */
    public function register_parser(ParserInterface $parser): void {
        $this->parsers[$parser->get_id()] = $parser;

        // Sort by priority
        uasort($this->parsers, function ($a, $b) {
            return $a->get_priority() <=> $b->get_priority();
        });
    }

    /**
     * Detect the HTML format and return the appropriate parser
     *
     * @param string $html Raw HTML content
     * @return ParserInterface|null
     */
    public function detect(string $html): ?ParserInterface {
        foreach ($this->parsers as $parser) {
            if ($parser->can_parse($html)) {
                return $parser;
            }
        }
        return null;
    }

    /**
     * Get format detection details
     *
     * @param string $html Raw HTML content
     * @return array
     */
    public function analyze(string $html): array {
        $analysis = [
            'has_tables' => $this->has_html_tables($html),
            'has_pre' => $this->has_pre_tags($html),
            'is_word_html' => $this->is_word_html($html),
            'has_fixed_width' => $this->has_fixed_width_columns($html),
            'detected_columns' => $this->detect_column_structure($html),
            'estimated_year' => $this->estimate_year($html),
            'selected_parser' => null,
        ];

        $parser = $this->detect($html);
        if ($parser) {
            $analysis['selected_parser'] = $parser->get_id();
        }

        return $analysis;
    }

    /**
     * Check for HTML table structure
     */
    private function has_html_tables(string $html): bool {
        return preg_match('/<table[^>]*>.*?<tr.*?>.*?<td/is', $html) === 1;
    }

    /**
     * Check for PRE tags with content
     */
    private function has_pre_tags(string $html): bool {
        return preg_match('/<pre[^>]*>.{100,}/is', $html) === 1;
    }

    /**
     * Check for Microsoft Word generated HTML
     */
    private function is_word_html(string $html): bool {
        $word_indicators = [
            'urn:schemas-microsoft-com:office',
            'xmlns:w=',
            'xmlns:o=',
            'mso-',
            'Microsoft Word',
            'ProgId content=Word',
        ];

        foreach ($word_indicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check for fixed-width column formatting
     */
    private function has_fixed_width_columns(string $html): bool {
        // Look for consistent spacing patterns in PRE tags
        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $html, $matches)) {
            $lines = explode("\n", $matches[1]);
            $space_patterns = [];

            foreach (array_slice($lines, 0, 20) as $line) {
                if (strlen(trim($line)) > 20) {
                    // Find positions of multiple consecutive spaces
                    preg_match_all('/\s{2,}/', $line, $spaces, PREG_OFFSET_CAPTURE);
                    foreach ($spaces[0] as $space) {
                        $pos = $space[1];
                        $space_patterns[$pos] = ($space_patterns[$pos] ?? 0) + 1;
                    }
                }
            }

            // If we have consistent space positions, it's fixed-width
            $consistent = array_filter($space_patterns, fn($count) => $count >= 3);
            return count($consistent) >= 3;
        }
        return false;
    }

    /**
     * Detect column structure from PRE content
     */
    private function detect_column_structure(string $html): array {
        $columns = [];

        if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $html, $matches)) {
            $lines = array_filter(explode("\n", $matches[1]), fn($l) => strlen(trim($l)) > 10);

            // Look for header line with column names
            foreach (array_slice($lines, 0, 10) as $line) {
                $lower = strtolower($line);
                if (preg_match('/place|name|time|div|age|bib|pace/i', $line)) {
                    // Extract column positions
                    preg_match_all('/(\w+(?:\s+\w+)?)\s+/i', $line, $headers, PREG_OFFSET_CAPTURE);
                    foreach ($headers[1] as $header) {
                        $columns[] = [
                            'name' => trim($header[0]),
                            'position' => $header[1],
                        ];
                    }
                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * Try to estimate the year of the results
     */
    private function estimate_year(string $html): ?int {
        // Look for year in content
        if (preg_match('/\b(19[89]\d|20[0-2]\d)\b/', $html, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Get all registered parsers
     */
    public function get_parsers(): array {
        return $this->parsers;
    }
}
