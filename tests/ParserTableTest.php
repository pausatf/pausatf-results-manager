<?php
/**
 * Tests for HTML Table Parser
 */

namespace PAUSATF\Results\Tests;

use PHPUnit\Framework\TestCase;
use PAUSATF\Results\Parsers\ParserTable;

class ParserTableTest extends TestCase
{
    private ParserTable $parser;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-table.php';
        $this->parser = new ParserTable();
    }

    public function testCanParseHtmlTables(): void
    {
        $html = '<html><body><table><tr><td>Test</td></tr></table></body></html>';
        $this->assertTrue($this->parser->can_parse($html));
    }

    public function testCannotParseWordHtml(): void
    {
        $html = '<html xmlns:w="urn:schemas-microsoft-com:office:word"><body><table><tr><td>Test</td></tr></table></body></html>';
        $this->assertFalse($this->parser->can_parse($html));
    }

    public function testCannotParsePlainText(): void
    {
        $html = 'This is just plain text without any tables';
        $this->assertFalse($this->parser->can_parse($html));
    }

    public function testParseSimpleResultsTable(): void
    {
        $html = <<<HTML
<html>
<head><title>5K Results</title></head>
<body>
<h1>Annual 5K Championship</h1>
<p>November 10, 2024</p>
<table>
<tr><th>Place</th><th>Name/Age</th><th>Points</th></tr>
<tr><td>1</td><td>John Smith, 35</td><td>100</td></tr>
<tr><td>2</td><td>Jane Doe, 42</td><td>90</td></tr>
<tr><td>3</td><td>Bob Johnson, 28</td><td>80</td></tr>
</table>
</body>
</html>
HTML;

        $results = $this->parser->parse($html);

        $this->assertFalse($results->has_errors());
        $this->assertEquals(3, $results->get_result_count());
        $this->assertStringContainsString('5K', $results->event_name);

        // Check first result
        $this->assertEquals(1, $results->results[0]['place']);
        $this->assertEquals('John Smith', $results->results[0]['athlete_name']);
        $this->assertEquals(35, $results->results[0]['athlete_age']);
        $this->assertEquals(100, $results->results[0]['points']);
    }

    public function testParseResultsWithPayout(): void
    {
        $html = <<<HTML
<table>
<tr><th>Place</th><th>Name/Age</th><th>Points</th></tr>
<tr><td>1</td><td>Thomas Kloos, 31</td><td><b>150</b> ($300)</td></tr>
<tr><td>2</td><td>Phillip Reid, 22</td><td><b>135</b> ($150)</td></tr>
</table>
HTML;

        $results = $this->parser->parse($html);

        $this->assertEquals(2, $results->get_result_count());
        $this->assertEquals(150, $results->results[0]['points']);
        $this->assertEquals(300, $results->results[0]['payout']);
        $this->assertEquals(135, $results->results[1]['points']);
        $this->assertEquals(150, $results->results[1]['payout']);
    }

    public function testParseResultsWithTime(): void
    {
        $html = <<<HTML
<table>
<tr><th>Place</th><th>Name</th><th>Time</th></tr>
<tr><td>1</td><td>John Smith</td><td>15:30</td></tr>
<tr><td>2</td><td>Jane Doe</td><td>1:02:45</td></tr>
</table>
HTML;

        $results = $this->parser->parse($html);

        $this->assertEquals(2, $results->get_result_count());
        $this->assertEquals('15:30', $results->results[0]['time_display']);
        $this->assertEquals(930, $results->results[0]['time_seconds']); // 15*60 + 30
        $this->assertEquals('1:02:45', $results->results[1]['time_display']);
        $this->assertEquals(3765, $results->results[1]['time_seconds']); // 1*3600 + 2*60 + 45
    }

    public function testParseDivisions(): void
    {
        $html = <<<HTML
<h2>OPEN MEN</h2>
<table>
<tr><th>Place</th><th>Name</th><th>Points</th></tr>
<tr><td>1</td><td>John Smith</td><td>100</td></tr>
</table>
<h2>MASTERS 40+</h2>
<table>
<tr><th>Place</th><th>Name</th><th>Points</th></tr>
<tr><td>1</td><td>Bob Johnson</td><td>100</td></tr>
</table>
HTML;

        $results = $this->parser->parse($html);

        $this->assertEquals(2, $results->get_result_count());
        $this->assertContains('Open', $results->divisions);
    }

    public function testGetPriority(): void
    {
        $this->assertEquals(20, $this->parser->get_priority());
    }

    public function testGetId(): void
    {
        $this->assertEquals('table', $this->parser->get_id());
    }
}
