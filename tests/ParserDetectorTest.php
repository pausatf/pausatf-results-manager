<?php
/**
 * Tests for Parser Detector
 */

namespace PAUSATF\Results\Tests;

use PHPUnit\Framework\TestCase;
use PAUSATF\Results\Parsers\ParserDetector;

class ParserDetectorTest extends TestCase
{
    private ParserDetector $detector;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-detector.php';
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-table.php';
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-pre.php';
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-word.php';

        $this->detector = new ParserDetector();
    }

    public function testDetectTableParser(): void
    {
        $html = '<html><body><table><tr><td>Test</td></tr></table></body></html>';
        $parser = $this->detector->detect($html);

        $this->assertNotNull($parser);
        $this->assertEquals('table', $parser->get_id());
    }

    public function testDetectPreParser(): void
    {
        $html = '<html><body><pre>' . str_repeat('Result data here ', 50) . '</pre></body></html>';
        $parser = $this->detector->detect($html);

        $this->assertNotNull($parser);
        $this->assertEquals('pre', $parser->get_id());
    }

    public function testDetectWordParser(): void
    {
        $html = '<html xmlns:w="urn:schemas-microsoft-com:office:word"><body><table><tr><td>Test</td></tr></table></body></html>';
        $parser = $this->detector->detect($html);

        $this->assertNotNull($parser);
        $this->assertEquals('word', $parser->get_id());
    }

    public function testWordParserHasHighestPriority(): void
    {
        // Word HTML with tables should be detected as Word, not Table
        $html = <<<HTML
<html xmlns:w="urn:schemas-microsoft-com:office:word" xmlns:o="urn:schemas-microsoft-com:office:office">
<head><meta name="ProgId" content="Word.Document"></head>
<body>
<table><tr><td>Test</td></tr></table>
</body>
</html>
HTML;

        $parser = $this->detector->detect($html);
        $this->assertEquals('word', $parser->get_id());
    }

    public function testAnalyzeReturnsDetails(): void
    {
        $html = '<html><body><table><tr><td>Test</td></tr></table></body></html>';
        $analysis = $this->detector->analyze($html);

        $this->assertArrayHasKey('has_tables', $analysis);
        $this->assertArrayHasKey('has_pre', $analysis);
        $this->assertArrayHasKey('is_word_html', $analysis);
        $this->assertArrayHasKey('selected_parser', $analysis);

        $this->assertTrue($analysis['has_tables']);
        $this->assertFalse($analysis['has_pre']);
        $this->assertFalse($analysis['is_word_html']);
        $this->assertEquals('table', $analysis['selected_parser']);
    }

    public function testDetectReturnsNullForUnparseable(): void
    {
        $html = 'This is just plain text with no structure';
        $parser = $this->detector->detect($html);

        $this->assertNull($parser);
    }

    public function testEstimateYear(): void
    {
        $html = '<html><body><h1>2024 Championships</h1><table><tr><td>Test</td></tr></table></body></html>';
        $analysis = $this->detector->analyze($html);

        $this->assertEquals(2024, $analysis['estimated_year']);
    }
}
