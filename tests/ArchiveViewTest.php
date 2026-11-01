<?php
/**
 * Published archive rendering contracts exercised through the generated DOM.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use DOMDocument;
use DOMNodeList;
use DOMXPath;
use PAUSATF\Results\Archive\ArchiveView;
use PHPUnit\Framework\TestCase;

final class ArchiveViewTest extends TestCase
{
    private array $release;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/class-archive-view.php';
        $json = file_get_contents(__DIR__ . '/fixtures/archive-context/1996-5ka-bundle.json');
        self::assertNotFalse($json);
        $bundle = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $this->release = [
            'id' => 1, 'competition_key' => $bundle['competition_key'],
            'source_url' => null, 'entities' => $bundle['entities'],
            'record_ratification' => 'not_determined_by_extraction_review',
        ];
    }

    private function render(): DOMXPath
    {
        $html = ArchiveView::render($this->release);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
            self::assertTrue($loaded);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    private function nodes(DOMXPath $xpath, string $query): DOMNodeList
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        return $nodes;
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $nodes = $this->nodes($xpath, $query);
        self::assertSame(1, $nodes->length, $query);
        return $nodes->item(0)->textContent;
    }

    private function column(DOMXPath $xpath, string $caption, string $heading): int
    {
        $headers = $this->nodes($xpath, '//table[caption="' . $caption . '"]/thead/tr/th');
        $matches = [];
        foreach ($headers as $index => $header) {
            if ($header->textContent === $heading) {
                self::assertSame('col', $header->getAttribute('scope'));
                $matches[] = $index + 1;
            }
        }
        self::assertCount(1, $matches, $caption . ': ' . $heading);
        return $matches[0];
    }

    private function cell(DOMXPath $xpath, string $caption, int $row, string $heading): string
    {
        $column = $this->column($xpath, $caption, $heading);
        return $this->text($xpath, '//table[caption="' . $caption . '"]/tbody/tr[' . $row . ']/td[' . $column . ']');
    }

    private function header(DOMXPath $xpath, string $caption, string $heading): string
    {
        $column = $this->column($xpath, $caption, $heading);
        return $this->text($xpath, '//table[caption="' . $caption . '"]/thead/tr/th[' . $column . ']');
    }

    public function testPerformanceTableHasAccessibleHeadersAndOriginalRows(): void
    {
        $xpath = $this->render();
        self::assertSame(1, $this->nodes($xpath, '//section/h2')->length);
        self::assertSame('gimme-shelter-5k-1996', $this->text($xpath, '//section/h2'));
        self::assertSame(16, $this->nodes($xpath, '//table[caption="Performances"]/thead/tr/th[@scope="col"]')->length);
        self::assertSame(2, $this->nodes($xpath, '//table[caption="Performances"]/tbody/tr')->length);
        self::assertSame('Christophe Impens, Albuqu', $this->cell($xpath, 'Performances', 1, 'Name as recorded'));
        self::assertSame('Matt Guisto, Albuquerque', $this->cell($xpath, 'Performances', 2, 'Name as recorded'));
        self::assertSame('14:13', $this->cell($xpath, 'Performances', 1, 'Mark'));
        self::assertSame('14:13', $this->cell($xpath, 'Performances', 2, 'Mark'));
    }

    public function testDisplayedEqualTimesKeepTheirDistinctPublishedPlaces(): void
    {
        $xpath = $this->render();
        self::assertSame('1', $this->cell($xpath, 'Rankings', 1, 'Place'));
        self::assertSame('2', $this->cell($xpath, 'Rankings', 2, 'Place'));
        self::assertSame('Source', $this->cell($xpath, 'Rankings', 1, 'Origin'));
        self::assertSame('1', $this->cell($xpath, 'Performances', 1, 'Overall place'));
        self::assertSame('2', $this->cell($xpath, 'Performances', 2, 'Overall place'));
        self::assertSame('Overall', $this->cell($xpath, 'Rankings', 1, 'Place category'));
    }

    public function testPrimaryResultsLeadTheirTablesAndScrollingRegionsAreKeyboardReachable(): void
    {
        $xpath = $this->render();
        foreach (['Performances' => ['Name as recorded', 'Mark'], 'Rankings' => ['Name as recorded', 'Place']] as $caption => $headings) {
            foreach ($headings as $index => $heading) {
                self::assertSame($heading, $this->text($xpath, '//table[caption="' . $caption . '"]/thead/tr/th[' . ($index + 1) . ']'));
            }
            $regions = $this->nodes($xpath, '//table[caption="' . $caption . '"]/parent::div[@role="region" and @tabindex="0"]');
            self::assertSame(1, $regions->length);
            self::assertSame($caption, $regions->item(0)->getAttribute('aria-label'));
        }
    }

    public function testPublishedPlaceCategoriesAreSeparateAndHumanReadable(): void
    {
        $this->release['entities']['divisions'][] = ['key' => 'age-division', 'published_label' => 'M25-29', 'age_band' => '25-29'];
        $this->release['entities']['performances'][0]['division_key'] = 'age-division';
        $this->release['entities']['rankings'][] = ['key' => 'sex', 'performance_key' => 'p1', 'scope' => 'sex:M', 'published_place' => '3'];
        $this->release['entities']['rankings'][] = ['key' => 'division', 'performance_key' => 'p1', 'scope' => 'division:M25-29', 'published_place' => '2'];
        $xpath = $this->render();
        self::assertSame('1', $this->cell($xpath, 'Performances', 1, 'Overall place'));
        self::assertSame('3', $this->cell($xpath, 'Performances', 1, 'Sex-category place'));
        self::assertSame('2', $this->cell($xpath, 'Performances', 1, 'Age-division place'));
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 2, 'Sex-category place'));
        self::assertSame('Sex category: M', $this->cell($xpath, 'Rankings', 3, 'Place category'));
        self::assertSame('Division: M25-29', $this->cell($xpath, 'Rankings', 4, 'Place category'));
    }

    public function testAnOpenDivisionIsNotPresentedAsAnAgeDivision(): void
    {
        $this->release['entities']['divisions'][] = ['key' => 'open', 'published_label' => 'Open'];
        $this->release['entities']['performances'][0]['division_key'] = 'open';
        $this->release['entities']['rankings'][] = ['key' => 'open-place', 'performance_key' => 'p1', 'scope' => 'division:Open', 'published_place' => '2'];
        $xpath = $this->render();
        self::assertSame('2', $this->cell($xpath, 'Performances', 1, 'Division place'));
        self::assertSame('Division: Open', $this->cell($xpath, 'Rankings', 3, 'Place category'));
        self::assertSame(0, $this->nodes($xpath, '//th[text()="Age-division place"]')->length);
    }

    public function testComputedPlacesDoNotReplacePublishedSummaryAndAmbiguousScopesStayDetailed(): void
    {
        $this->release['entities']['rankings'][] = ['key' => 'computed', 'performance_key' => 'p1', 'scope' => 'overall', 'published_place' => '99', 'origin' => 'computed'];
        $this->release['entities']['rankings'][] = ['key' => 'sex-one', 'performance_key' => 'p1', 'scope' => 'sex:M', 'published_place' => '3'];
        $this->release['entities']['rankings'][] = ['key' => 'sex-two', 'performance_key' => 'p1', 'scope' => 'sex:Open', 'published_place' => '4'];
        $xpath = $this->render();
        self::assertSame('1', $this->cell($xpath, 'Performances', 1, 'Overall place'));
        self::assertSame('See place details', $this->cell($xpath, 'Performances', 1, 'Sex-category place'));
        self::assertSame('99', $this->cell($xpath, 'Rankings', 3, 'Place'));
        self::assertSame('Computed', $this->cell($xpath, 'Rankings', 3, 'Origin'));
        self::assertSame('3', $this->cell($xpath, 'Rankings', 4, 'Place'));
        self::assertSame('4', $this->cell($xpath, 'Rankings', 5, 'Place'));
    }

    public function testQualificationCaseAndUnknownVersusZeroWindRemainVisible(): void
    {
        $this->release['entities']['performances'][0]['qualifier_raw'] = 'Q';
        $this->release['entities']['performances'][1]['qualifier_raw'] = 'q';
        $this->release['entities']['performances'][0]['wind'] = null;
        $this->release['entities']['performances'][1]['wind'] = '0.0';
        $xpath = $this->render();
        self::assertSame('Q', $this->cell($xpath, 'Performances', 1, 'Qualifier'));
        self::assertSame('q', $this->cell($xpath, 'Performances', 2, 'Qualifier'));
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 1, 'Wind (m/s)'));
        self::assertSame('0.0', $this->cell($xpath, 'Performances', 2, 'Wind (m/s)'));
    }

    public function testBlankRawStatusIsNotReplacedByUnknownOrFinished(): void
    {
        $this->release['entities']['performances'][0]['status_raw'] = '';
        $this->release['entities']['performances'][1]['status_raw'] = null;
        $xpath = $this->render();
        self::assertSame('', $this->cell($xpath, 'Performances', 1, 'Status'));
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 2, 'Status'));
    }

    public function testUntrustedNamesMarksAndTitlesRenderAsText(): void
    {
        $name = '<img src=x onerror="alert(1)">';
        $mark = '</td><script>alert(1)</script>';
        $title = '<svg onload="alert(1)">Synthetic title</svg>';
        $this->release['entities']['performances'][0]['athlete_name_raw'] = $name;
        $this->release['entities']['performances'][0]['raw_mark'] = $mark;
        $this->release['entities']['competitions'][] = ['key' => 'meet', 'title' => $title];
        $xpath = $this->render();
        self::assertSame($title, $this->text($xpath, '//section/h2'));
        self::assertSame($name, $this->cell($xpath, 'Performances', 1, 'Name as recorded'));
        self::assertSame($mark, $this->cell($xpath, 'Performances', 1, 'Mark'));
        self::assertSame(0, $this->nodes($xpath, '//script|//img|//svg|//*[@onerror or @onload]')->length);
        self::assertSame(2, $this->nodes($xpath, '//table[caption="Performances"]/tbody/tr')->length);
    }

    public function testSyntheticCalculatedRankingIsClearlySeparateFromSourceRankings(): void
    {
        $this->release['entities']['rankings'][] = [
            'key' => 'synthetic-calculation', 'performance_key' => 'p1', 'scope' => 'age-graded',
            'published_place' => '1', 'origin' => 'computed',
            'calculation_version' => 'synthetic-age-grade-v1', 'timing_basis' => 'net',
            'age_grade_published' => '90.1234', 'age_grade_table_version' => 'synthetic-2025-table',
        ];
        $xpath = $this->render();
        self::assertSame('Source', $this->cell($xpath, 'Rankings', 1, 'Origin'));
        self::assertSame('Computed', $this->cell($xpath, 'Rankings', 3, 'Origin'));
        self::assertSame('synthetic-age-grade-v1', $this->cell($xpath, 'Rankings', 3, 'Calculation version'));
        self::assertSame('net', $this->cell($xpath, 'Rankings', 3, 'Timing basis'));
        self::assertSame('90.1234', $this->cell($xpath, 'Rankings', 3, 'Age grade'));
        self::assertSame('synthetic-2025-table', $this->cell($xpath, 'Rankings', 3, 'Age-grade table version'));
        self::assertStringContainsString('separately labelled calculations', $this->text($xpath, '//section/p[1]'));
    }

    public function testGunAndNetTimesRemainSeparateDisplayColumns(): void
    {
        $this->release['entities']['performances'][0]['gun_time_raw'] = '14:13.127';
        $this->release['entities']['performances'][0]['net_time_raw'] = '14:12.983';
        $xpath = $this->render();
        self::assertSame('Gun time', $this->header($xpath, 'Performances', 'Gun time'));
        self::assertSame('Net time', $this->header($xpath, 'Performances', 'Net time'));
        self::assertSame('14:13.127', $this->cell($xpath, 'Performances', 1, 'Gun time'));
        self::assertSame('14:12.983', $this->cell($xpath, 'Performances', 1, 'Net time'));
    }

    public function testRecordClaimsRemainClaimsWithExplicitRatificationDisclaimer(): void
    {
        $this->release['entities']['record_claims'][] = [
            'key' => 'claim', 'performance_key' => 'p1',
            'record_type_as_printed' => 'Synthetic record claim', 'claimed_status' => 'unverified',
        ];
        $xpath = $this->render();
        self::assertSame('Record claim as printed', $this->header($xpath, 'Record claims', 'Record claim as printed'));
        self::assertSame('unverified', $this->cell($xpath, 'Record claims', 1, 'Claimed status'));
        self::assertStringContainsString('extraction review does not ratify records', $this->text($xpath, '//section/p[1]'));
    }

    public function testMissingEventDivisionAndRoundRemainUnreported(): void
    {
        $xpath = $this->render();
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 1, 'Event'));
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 1, 'Division'));
        self::assertSame('Not reported', $this->cell($xpath, 'Performances', 1, 'Round'));
    }

    public function testSyntheticSeedLaneAndBibStaySeparateFromResultMark(): void
    {
        $this->release['entities']['performances'][0]['seed_mark_raw'] = '14:10.001';
        $this->release['entities']['performances'][0]['lane'] = '2';
        $this->release['entities']['performances'][0]['bib'] = '006';
        $xpath = $this->render();
        self::assertSame('14:13', $this->cell($xpath, 'Performances', 1, 'Mark'));
        self::assertSame('14:10.001', $this->cell($xpath, 'Performances', 1, 'Seed mark'));
        self::assertSame('2', $this->cell($xpath, 'Performances', 1, 'Lane'));
        self::assertSame('006', $this->cell($xpath, 'Performances', 1, 'Bib'));
    }

    public function testTeamMembershipDisplaysRecordedNamesInsteadOfInternalKeys(): void
    {
        $this->release['entities']['teams'][] = [
            'key' => 'team', 'club_as_reported' => '<Test Club>', 'member_keys' => ['p1', 'p2'],
            'scoring_member_keys' => ['p1'], 'displacer_keys' => ['p2'],
        ];
        $xpath = $this->render();
        self::assertSame('<Test Club>', $this->text($xpath, '//details[summary="Teams"]/dl/dd[1]'));
        self::assertStringContainsString('Christophe Impens, Albuqu', $this->text($xpath, '//details[summary="Teams"]/dl/dd[2]'));
        self::assertStringContainsString('Matt Guisto, Albuquerque', $this->text($xpath, '//details[summary="Teams"]/dl/dd[2]'));
        self::assertSame(0, $this->nodes($xpath, '//test')->length);
    }
}
