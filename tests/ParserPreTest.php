<?php
/**
 * Tests for PRE/Fixed-Width Parser
 */

namespace PAUSATF\Results\Tests;

use PHPUnit\Framework\TestCase;
use PAUSATF\Results\Parsers\ParserPre;

class ParserPreTest extends TestCase
{
    private ParserPre $parser;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/parsers/class-parser-pre.php';
        $this->parser = new ParserPre();
    }

    public function testCanParsePreTags(): void
    {
        $html = '<html><body><pre>' . str_repeat('x', 250) . '</pre></body></html>';
        $this->assertTrue($this->parser->can_parse($html));
    }

    public function testCannotParseShortPre(): void
    {
        $html = '<html><body><pre>Short content</pre></body></html>';
        $this->assertFalse($this->parser->can_parse($html));
    }

    public function testCannotParseWithoutPre(): void
    {
        $html = '<html><body><p>No pre tags here</p></body></html>';
        $this->assertFalse($this->parser->can_parse($html));
    }

    public function testParseFixedWidthResults(): void
    {
        $html = <<<HTML
<html>
<head><title>Gimme Shelter 5K</title></head>
<body>
<pre>
Pacific Association/USATF 5K Championship
San Francisco, CA
April 24, 1996

Place   Name                        Bib   Time
    1. Christophe Impens, Albuqu     6    14:13
    2. Matt Guisto, Albuquerque      5    14:13
    3. Gino Van Geyte, Albuquerq     9    14:32
</pre>
</body>
</html>
HTML;

        $results = $this->parser->parse($html);

        $this->assertFalse($results->has_errors());
        $this->assertGreaterThanOrEqual(3, $results->get_result_count());

        // Check first result
        $first = $results->results[0];
        $this->assertEquals(1, $first['place']);
        $this->assertStringContainsString('Christophe', $first['athlete_name']);
    }

    public function testParseWithDivisionMarkers(): void
    {
        $html = <<<HTML
<pre>
Place   Name                     Time   Div
----- ------------------------- -------- -------
    1 Gary Stolz                 29:39  M19-29
    2 Mike Dudley                30:10  M30-34
    3 Jane Smith                 31:45  W19-29
</pre>
HTML;

        $results = $this->parser->parse($html);

        $this->assertGreaterThanOrEqual(1, $results->get_result_count());
    }

    public function testExtractEventMetadata(): void
    {
        $html = <<<HTML
<html>
<head><title>Pacific Sun 10K</title></head>
<body>
<pre>
Pacific Sun 10K Championship
San Francisco, CA
September 4, 2000

Place   Name                     Time
    1. John Smith                29:39
</pre>
</body>
</html>
HTML;

        $results = $this->parser->parse($html);

        $this->assertStringContainsString('10K', $results->event_name);
        $this->assertEquals('2000-09-04', $results->event_date);
        $this->assertStringContainsString('San Francisco', $results->event_location);
    }

    public function testGetPriority(): void
    {
        $this->assertEquals(30, $this->parser->get_priority());
    }

    public function testGetId(): void
    {
        $this->assertEquals('pre', $this->parser->get_id());
    }
}
