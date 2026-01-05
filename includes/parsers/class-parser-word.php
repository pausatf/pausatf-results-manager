<?php
/**
 * Microsoft Word HTML Parser - Cleans Word-generated HTML before parsing
 *
 * @package PAUSATF\Results\Parsers
 */

namespace PAUSATF\Results\Parsers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for Microsoft Word generated HTML
 */
class ParserWord implements ParserInterface {
    private const PRIORITY = 10;
    private const ID = 'word';

    private ParserTable $table_parser;

    public function __construct() {
        $this->table_parser = new ParserTable();
    }

    public function get_priority(): int {
        return self::PRIORITY;
    }

    public function get_id(): string {
        return self::ID;
    }

    public function can_parse(string $html): bool {
        $word_indicators = [
            'urn:schemas-microsoft-com:office',
            'xmlns:w=',
            'xmlns:o=',
            'ProgId content=Word',
        ];

        foreach ($word_indicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }

    public function parse(string $html, array $context = []): ParsedResults {
        // Clean Word HTML first
        $cleaned_html = $this->clean_word_html($html);

        // Delegate to table parser or PRE parser
        if ($this->table_parser->can_parse($cleaned_html)) {
            return $this->table_parser->parse($cleaned_html, $context);
        }

        // Fall back to PRE parser
        $pre_parser = new ParserPre();
        if ($pre_parser->can_parse($cleaned_html)) {
            return $pre_parser->parse($cleaned_html, $context);
        }

        // If nothing works, return error
        $results = new ParsedResults();
        $results->add_error('Could not parse Word HTML - no suitable parser found');
        return $results;
    }

    /**
     * Clean Microsoft Word HTML cruft
     */
    private function clean_word_html(string $html): string {
        // Remove XML declarations
        $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html);

        // Remove Word-specific namespaces from opening tags
        $html = preg_replace('/\s+xmlns:[a-z]+="[^"]*"/i', '', $html);

        // Remove conditional comments
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', $html);
        $html = preg_replace('/<!--\[if[^\]]*\]>/i', '', $html);
        $html = preg_replace('/<!\[endif\]-->/i', '', $html);

        // Remove Word-specific elements
        $html = preg_replace('/<o:[^>]*>.*?<\/o:[^>]*>/is', '', $html);
        $html = preg_replace('/<w:[^>]*>.*?<\/w:[^>]*>/is', '', $html);
        $html = preg_replace('/<st1:[^>]*>.*?<\/st1:[^>]*>/is', '', $html);
        $html = preg_replace('/<v:[^>]*>.*?<\/v:[^>]*>/is', '', $html);

        // Remove self-closing Word elements
        $html = preg_replace('/<[owvst1]+:[^>]*\/>/i', '', $html);

        // Remove Word-specific attributes
        $html = preg_replace('/\s+class="?Mso[^"\s>]*"?/i', '', $html);
        $html = preg_replace('/\s+style="[^"]*mso-[^"]*"/i', '', $html);
        $html = preg_replace('/\s+lang="[^"]*"/i', '', $html);

        // Remove empty spans
        $html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);

        // Remove empty paragraphs (but keep line breaks)
        $html = preg_replace('/<p[^>]*>\s*(&nbsp;|\s)*\s*<\/p>/i', '<br>', $html);

        // Clean up excessive whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        // Remove empty style attributes
        $html = preg_replace('/\s+style=""/i', '', $html);

        return trim($html);
    }
}
