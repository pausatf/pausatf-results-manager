<?php
/**
 * Synthetic multi-event context must remain associated with its source rows.
 *
 * @package PAUSATF\Results\Tests
 */
declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PAUSATF\Results\Archive\ArchiveBundle;
use PAUSATF\Results\Archive\ArchiveView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/archive/class-archive-bundle.php';
require_once dirname(__DIR__) . '/includes/archive/class-archive-view.php';

final class ArchiveCompetitionContextTest extends TestCase {
    private string $source;
    private array $bundle;

    protected function setUp(): void {
        $source = file_get_contents(__DIR__ . '/fixtures/archive-context/1996-5ka-excerpt.txt');
        $json = file_get_contents(__DIR__ . '/fixtures/archive-context/1996-5ka-bundle.json');
        self::assertNotFalse($source);
        self::assertNotFalse($json);
        $this->source = $source;
        $this->bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function associatedKinds(): array {
        return ['round' => ['rounds'], 'performance' => ['performances'], 'team' => ['teams']];
    }

    #[DataProvider('associatedKinds')]
    public function testMultiEventRecordsRequireExplicitAssociation(string $kind): void {
        $this->multiEvent();
        unset($this->bundle['entities'][$kind][0]['competition_key']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multi-event records require an explicit competition reference.');
        $this->parse();
    }

    public function testPerformanceCannotReferToAnotherEventsRound(): void {
        $this->multiEvent();
        $this->bundle['entities']['performances'][0]['round_key'] = 'round-b';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Performance and round belong to different competitions.');
        $this->parse();
    }

    public function testTeamCannotIncludeAnotherEventsPerformance(): void {
        $this->multiEvent();
        $this->bundle['entities']['teams'][0]['member_keys'][] = 'p2';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Team member belongs to another competition.');
        $this->parse();
    }

    public function testTwoEventJsonAndRenderedRowsKeepDistinctContexts(): void {
        $this->multiEvent();
        $parsed = $this->parse();
        self::assertSame($this->bundle, $parsed);
        self::assertSame(['event-a', 'event-b'], array_column($parsed['entities']['performances'], 'competition_key'));
        $xpath = $this->render($parsed);
        self::assertSame($this->bundle['competition_key'], $xpath->evaluate('string(//section/h2)'));
        foreach ([1 => ['Synthetic sprint', 'Heat / 1'], 2 => ['Synthetic distance', 'Final / 2']] as $row => [$event, $round]) {
            self::assertSame($event, $this->cell($xpath, $row, 'Event'));
            self::assertSame('Synthetic open division', $this->cell($xpath, $row, 'Division'));
            self::assertSame($round, $this->cell($xpath, $row, 'Round'));
            self::assertSame('14:13', $this->cell($xpath, $row, 'Mark'));
        }
        self::assertSame(2, (int) $xpath->evaluate('count(//table[caption="Performances"]/tbody/tr)'));
    }

    public function testSingleEventImplicitAssociationDoesNotRewriteRawArtifact(): void {
        $this->add('competitions', ['key' => 'single', 'title' => 'Synthetic single event']);
        $this->bundle['entities']['performances'][0]['source_annotation'] = ['as_recorded' => '  Q / q  ', 'unknown' => null];
        $parsed = $this->parse();
        self::assertSame($this->bundle, $parsed);
        self::assertArrayNotHasKey('competition_key', $parsed['entities']['performances'][0]);
        self::assertSame(['as_recorded' => '  Q / q  ', 'unknown' => null], $parsed['entities']['performances'][0]['source_annotation']);
        $xpath = $this->render($parsed);
        self::assertSame('Synthetic single event', $this->cell($xpath, 1, 'Event'));
        self::assertSame('Synthetic single event', $this->cell($xpath, 2, 'Event'));
    }

    private function multiEvent(): void {
        // Added context is synthetic and makes no claim about the historical excerpt.
        $this->add('competitions', ['key' => 'event-a', 'title' => 'Synthetic sprint']);
        $this->add('competitions', ['key' => 'event-b', 'title' => 'Synthetic distance']);
        $this->add('divisions', ['key' => 'open', 'published_label' => 'Synthetic open division']);
        $this->add('rounds', ['key' => 'round-a', 'competition_key' => 'event-a', 'division_key' => 'open', 'round_kind' => 'Heat', 'heat' => '1']);
        $this->add('rounds', ['key' => 'round-b', 'competition_key' => 'event-b', 'division_key' => 'open', 'round_kind' => 'Final', 'heat' => '2']);
        foreach (['a', 'b'] as $index => $suffix) {
            $this->bundle['entities']['performances'][$index] += ['competition_key' => 'event-' . $suffix, 'division_key' => 'open', 'round_key' => 'round-' . $suffix];
        }
        $this->add('teams', ['key' => 'team-a', 'competition_key' => 'event-a', 'member_keys' => ['p1']]);
    }

    private function add(string $kind, array $record): void {
        $this->bundle['entities'][$kind][] = $record + ['source_locator' => ['kind' => 'line', 'line' => 1]];
        $this->bundle['coverage'][0]['records'][] = $kind . ':' . $record['key'];
    }

    private function parse(): array {
        return ArchiveBundle::parse(json_encode($this->bundle, JSON_THROW_ON_ERROR), $this->source);
    }

    private function render(array $bundle): DOMXPath {
        $html = ArchiveView::render(['id' => 1, 'competition_key' => $bundle['competition_key'], 'source_url' => null, 'entities' => $bundle['entities']]);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    private function cell(DOMXPath $xpath, int $row, string $heading): string {
        $headers = $xpath->query('//table[caption="Performances"]/thead/tr/th');
        self::assertNotFalse($headers);
        foreach ($headers as $index => $header) {
            if ($header->textContent === $heading) {
                return $xpath->evaluate('string(//table[caption="Performances"]/tbody/tr[' . $row . ']/td[' . ($index + 1) . '])');
            }
        }
        self::fail('Missing performance heading: ' . $heading);
    }
}
