<?php
/**
 * Printed series standings and association records lists in archive bundles.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PAUSATF\Results\Archive\ArchiveBundle;
use PAUSATF\Results\Archive\ArchiveReview;
use PAUSATF\Results\Archive\ArchiveView;
use PAUSATF\Results\Archive\ArchiveWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ArchivePrintedListsTest extends TestCase
{
    /** Synthetic text; it does not reproduce historical standings or records. */
    private const SOURCE = "Synthetic Grand Prix standings and records fixture; not historical.\n"
        . "Men Open: 1 Runner A, Club X, R1 10, R2 8, Total 18; 2 Runner B, R2 9, Total 9.\n"
        . "Men Open teams: 1 Club X 20.\n"
        . "Synthetic youth list. Girls 11-12: 1500m 4:59.9 Runner C, Club Y, 6/5/99, Meet Z.\n"
        . "Girls 11-12: 4x100 relay vacant.\n";

    private array $bundle;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/class-archive-bundle.php';
        require_once dirname(__DIR__) . '/includes/archive/class-archive-review.php';
        require_once dirname(__DIR__) . '/includes/archive/class-archive-view.php';
        require_once dirname(__DIR__) . '/includes/archive/class-archive-workflow.php';
        $entities = array_fill_keys(array_diff(ArchiveBundle::ENTITIES, ArchiveBundle::OPTIONAL_ENTITIES), []);
        $entities['series_standings'] = [
            [
                'key' => 'gp-a', 'source_locator' => ['kind' => 'line', 'line' => 2], 'standings_kind' => 'individual',
                'series_label_as_printed' => 'Synthetic Grand Prix', 'division_label_as_printed' => 'Men Open',
                'place_as_printed' => '1', 'name_as_printed' => 'Runner A', 'club_as_printed' => 'Club X',
                'race_points_as_printed' => ['R1' => '10', 'R2' => '8'], 'total_as_printed' => '18', 'origin' => 'published',
            ],
            [
                'key' => 'gp-b', 'source_locator' => ['kind' => 'line', 'line' => 2], 'standings_kind' => 'individual',
                'series_label_as_printed' => 'Synthetic Grand Prix', 'division_label_as_printed' => 'Men Open',
                'place_as_printed' => '2', 'name_as_printed' => 'Runner B',
                'race_points_as_printed' => ['R2' => '9'], 'total_as_printed' => '9',
            ],
            [
                'key' => 'gp-team', 'source_locator' => ['kind' => 'line', 'line' => 3], 'standings_kind' => 'team',
                'series_label_as_printed' => 'Synthetic Grand Prix', 'division_label_as_printed' => 'Men Open',
                'place_as_printed' => '1', 'name_as_printed' => 'Club X',
                'race_points_as_printed' => new stdClass(), 'total_as_printed' => '20',
            ],
        ];
        $entities['association_records'] = [
            [
                'key' => 'rec-1500', 'source_locator' => ['kind' => 'line', 'line' => 4],
                'list_label_as_printed' => 'Synthetic youth list', 'division_as_printed' => 'Girls 11-12',
                'event_as_printed' => '1500m', 'holder_name_as_printed' => 'Runner C', 'club_as_printed' => 'Club Y',
                'mark_as_printed' => '4:59.9', 'date_as_printed' => '6/5/99', 'date_set' => '1999-06-05',
                'meet_as_printed' => 'Meet Z', 'record_status_as_listed' => 'PA list as published', 'is_relay' => false,
            ],
            [
                'key' => 'rec-relay', 'source_locator' => ['kind' => 'line', 'line' => 5],
                'list_label_as_printed' => 'Synthetic youth list', 'division_as_printed' => 'Girls 11-12',
                'event_as_printed' => '4x100 relay', 'record_status_as_listed' => 'vacant as listed', 'is_relay' => true,
            ],
        ];
        $this->bundle = [
            'schema_version' => 1,
            'competition_key' => 'synthetic-grand-prix',
            'source' => [
                'sha256' => hash('sha256', self::SOURCE), 'bytes' => strlen(self::SOURCE),
                'path' => 'synthetic/grand-prix.txt', 'format' => 'text', 'encoding' => 'ASCII',
            ],
            'entities' => $entities,
            'coverage' => [
                ['key' => 'heading', 'disposition' => 'excluded', 'records' => [], 'reason' => 'Fixture heading.'],
                ['key' => 'standings', 'disposition' => 'record', 'records' => ['series_standings:gp-a', 'series_standings:gp-b', 'series_standings:gp-team']],
                ['key' => 'records', 'disposition' => 'record', 'records' => ['association_records:rec-1500', 'association_records:rec-relay']],
            ],
        ];
    }

    private function json(?array $bundle = null): string
    {
        return json_encode($bundle ?? $this->bundle, JSON_THROW_ON_ERROR);
    }

    private function parse(?array $bundle = null, string $source = self::SOURCE): array
    {
        return ArchiveBundle::parse($this->json($bundle), $source);
    }

    private function reject(?array $bundle = null, string $source = self::SOURCE): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parse($bundle, $source);
    }

    private function report(array $checked): array
    {
        return [
            'bundle_sha256' => hash('sha256', $this->json()), 'source_sha256' => hash('sha256', self::SOURCE),
            'outcome' => 'pass', 'evidence' => 'Compared every printed standings and records row with the source.',
            'checked_records' => $checked, 'findings' => [],
        ];
    }

    private function allReferences(array $bundle): array
    {
        $references = [];
        foreach (ArchiveBundle::ENTITIES as $kind) {
            foreach (ArchiveBundle::records($bundle, $kind) as $record) { $references[] = $kind . ':' . $record['key']; }
        }
        return $references;
    }

    private function legacyFixture(): array
    {
        $fixtures = __DIR__ . '/fixtures/archive-context/';
        $json = file_get_contents($fixtures . '1996-5ka-bundle.json');
        $source = file_get_contents($fixtures . '1996-5ka-excerpt.txt');
        self::assertNotFalse($json);
        self::assertNotFalse($source);
        return [$json, $source];
    }

    private function render(array $entities): DOMXPath
    {
        $html = ArchiveView::render([
            'id' => 1, 'competition_key' => 'synthetic-grand-prix', 'source_url' => null, 'entities' => $entities,
            'record_ratification' => 'not_determined_by_extraction_review',
        ]);
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

    /** @return list<list<string>> */
    private function table(DOMXPath $xpath, string $caption): array
    {
        $tables = $xpath->query('//table[caption="' . $caption . '"]');
        self::assertNotFalse($tables);
        self::assertSame(1, $tables->length, $caption);
        $rows = [];
        foreach ($xpath->query('.//tr', $tables->item(0)) as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) as $cell) { $cells[] = $cell->textContent; }
            $rows[] = $cells;
        }
        return $rows;
    }

    public function testPrintedStandingsAndRecordsParseUnchanged(): void
    {
        $parsed = $this->parse();
        self::assertSame(json_decode($this->json(), true, 64, JSON_THROW_ON_ERROR), $parsed);
        self::assertSame(['R1' => '10', 'R2' => '8'], $parsed['entities']['series_standings'][0]['race_points_as_printed']);
        self::assertSame(1, $parsed['schema_version']);
    }

    public function testBundleWithoutNewCollectionsStillParsesAsEmpty(): void
    {
        [$json, $source] = $this->legacyFixture();
        $parsed = ArchiveBundle::parse($json, $source);
        self::assertArrayNotHasKey('series_standings', $parsed['entities']);
        self::assertArrayNotHasKey('association_records', $parsed['entities']);
        self::assertSame([], ArchiveBundle::records($parsed, 'series_standings'));
        self::assertSame([], ArchiveBundle::records($parsed, 'association_records'));
    }

    public function testOriginalCollectionsRemainRequired(): void
    {
        unset($this->bundle['entities']['record_claims']);
        $this->reject();
    }

    public function testUnknownCollectionIsStillRejected(): void
    {
        $this->bundle['entities']['season_rankings'] = [];
        $this->reject();
    }

    public static function nonListCollections(): array
    {
        return [[null], ['standings'], [['gp-a' => []]], [new stdClass()]];
    }

    #[DataProvider('nonListCollections')]
    public function testIncludedStandingsCollectionMustBeAList(mixed $value): void
    {
        $this->bundle['entities']['series_standings'] = $value;
        $this->bundle['coverage'][1]['records'] = [];
        $this->bundle['coverage'][1]['disposition'] = 'excluded';
        $this->bundle['coverage'][1]['reason'] = 'Collection removed.';
        $this->reject();
    }

    #[DataProvider('nonListCollections')]
    public function testIncludedRecordsCollectionMustBeAList(mixed $value): void
    {
        $this->bundle['entities']['association_records'] = $value;
        $this->bundle['coverage'][2]['records'] = [];
        $this->bundle['coverage'][2]['disposition'] = 'excluded';
        $this->bundle['coverage'][2]['reason'] = 'Collection removed.';
        $this->reject();
    }

    public static function requiredStandingFields(): array
    {
        return [['key'], ['source_locator'], ['standings_kind'], ['division_label_as_printed'], ['name_as_printed'], ['race_points_as_printed']];
    }

    #[DataProvider('requiredStandingFields')]
    public function testStandingRequiresContractField(string $field): void
    {
        unset($this->bundle['entities']['series_standings'][0][$field]);
        $this->reject();
    }

    public static function requiredRecordFields(): array
    {
        return [['key'], ['source_locator'], ['division_as_printed'], ['event_as_printed'], ['record_status_as_listed']];
    }

    #[DataProvider('requiredRecordFields')]
    public function testAssociationRecordRequiresContractField(string $field): void
    {
        unset($this->bundle['entities']['association_records'][0][$field]);
        $this->reject();
    }

    public static function invalidStandingValues(): array
    {
        return [
            'unknown kind' => ['standings_kind', 'relay'],
            'blank division' => ['division_label_as_printed', '  '],
            'long division' => ['division_label_as_printed', str_repeat('d', 256)],
            'blank name' => ['name_as_printed', ''],
            'long name' => ['name_as_printed', str_repeat('n', 256)],
            'long series' => ['series_label_as_printed', str_repeat('s', 256)],
            'long place' => ['place_as_printed', str_repeat('1', 65)],
            'long club' => ['club_as_printed', str_repeat('c', 256)],
            'long total' => ['total_as_printed', str_repeat('9', 65)],
            'numeric total' => ['total_as_printed', 18],
            'numeric place' => ['place_as_printed', 1],
            'NUL in name' => ['name_as_printed', "Runner\0A"],
        ];
    }

    #[DataProvider('invalidStandingValues')]
    public function testStandingFieldsAreTypedAndBounded(string $field, mixed $value): void
    {
        $this->bundle['entities']['series_standings'][0][$field] = $value;
        $this->reject();
    }

    public function testStandingFieldsAcceptMultibyteTextAtTheirLimits(): void
    {
        $standing = &$this->bundle['entities']['series_standings'][0];
        $standing['division_label_as_printed'] = str_repeat('é', 255);
        $standing['name_as_printed'] = str_repeat('漢', 255);
        $standing['place_as_printed'] = str_repeat('=', 64);
        $standing['total_as_printed'] = '';
        $standing['club_as_printed'] = null;
        unset($standing);
        self::assertSame(str_repeat('漢', 255), $this->parse()['entities']['series_standings'][0]['name_as_printed']);
    }

    public static function invalidRacePoints(): array
    {
        return [
            'list' => [['10', '8']],
            'empty list' => [[]],
            'string' => ['10, 8'],
            'integer points' => [['R1' => 10]],
            'decimal points' => [['R1' => 10.5]],
            'null points' => [['R1' => null]],
            'nested points' => [['R1' => ['10']]],
            'long points' => [['R1' => str_repeat('1', 65)]],
            'long label' => [[str_repeat('R', 97) => '10']],
            'blank label' => [[' ' => '10']],
            'too many races' => [array_combine(array_map(static fn(int $i): string => 'R' . $i, range(1, 61)), array_fill(0, 61, '1'))],
        ];
    }

    #[DataProvider('invalidRacePoints')]
    public function testRacePointsMustBeABoundedObjectOfPrintedStrings(mixed $points): void
    {
        $this->bundle['entities']['series_standings'][0]['race_points_as_printed'] = $points;
        $this->reject();
    }

    public function testRacePointsAcceptMaximumColumnsNumericLabelsAndBlankCells(): void
    {
        $points = [];
        foreach (range(1, 60) as $race) { $points[(string) $race] = $race === 3 ? '' : (string) $race; }
        $points['1'] = str_repeat('9', 64);
        $this->bundle['entities']['series_standings'][0]['race_points_as_printed'] = $points;
        $this->bundle['entities']['series_standings'][1]['race_points_as_printed'] = [str_repeat('R', 96) => 'dnr'];
        $parsed = $this->parse()['entities']['series_standings'];
        self::assertCount(60, $parsed[0]['race_points_as_printed']);
        self::assertSame('', $parsed[0]['race_points_as_printed'][3]);
        self::assertSame([], $parsed[2]['race_points_as_printed']);
    }

    public function testEmptyRacePointsMustStillBeAnObjectInTheOriginalJson(): void
    {
        $json = $this->json();
        $list_form = str_replace('"race_points_as_printed":{}', '"race_points_as_printed":[]', $json);
        self::assertNotSame($json, $list_form);
        $this->expectException(InvalidArgumentException::class);
        ArchiveBundle::parse($list_form, self::SOURCE);
    }

    public static function invalidRecordValues(): array
    {
        return [
            'blank division' => ['division_as_printed', ''],
            'long division' => ['division_as_printed', str_repeat('d', 256)],
            'long event' => ['event_as_printed', str_repeat('e', 256)],
            'long status' => ['record_status_as_listed', str_repeat('s', 97)],
            'blank status' => ['record_status_as_listed', ' '],
            'long list' => ['list_label_as_printed', str_repeat('l', 256)],
            'long holder' => ['holder_name_as_printed', str_repeat('h', 256)],
            'long club' => ['club_as_printed', str_repeat('c', 256)],
            'long mark' => ['mark_as_printed', str_repeat('4', 256)],
            'numeric mark' => ['mark_as_printed', 4.59],
            'long printed date' => ['date_as_printed', str_repeat('9', 65)],
            'long meet' => ['meet_as_printed', str_repeat('m', 256)],
            'impossible date' => ['date_set', '1999-02-30'],
            'printed date as date_set' => ['date_set', '6/5/99'],
            'string relay flag' => ['is_relay', 'yes'],
            'integer relay flag' => ['is_relay', 1],
        ];
    }

    #[DataProvider('invalidRecordValues')]
    public function testAssociationRecordFieldsAreTypedAndBounded(string $field, mixed $value): void
    {
        $this->bundle['entities']['association_records'][0][$field] = $value;
        $this->reject();
    }

    public function testVacantRecordAndBlankPrintedCellsRemainDistinct(): void
    {
        $this->bundle['entities']['association_records'][0]['mark_as_printed'] = '';
        $records = $this->parse()['entities']['association_records'];
        self::assertSame('', $records[0]['mark_as_printed']);
        self::assertArrayNotHasKey('mark_as_printed', $records[1]);
        self::assertArrayNotHasKey('holder_name_as_printed', $records[1]);
        self::assertTrue($records[1]['is_relay']);
    }

    public static function newKinds(): array
    {
        return [['series_standings'], ['association_records']];
    }

    #[DataProvider('newKinds')]
    public function testComputedOriginIsRejected(string $kind): void
    {
        $record = &$this->bundle['entities'][$kind][0];
        $record['origin'] = 'computed';
        $record['ruleset_key'] = 'rules';
        $record['calculation_version'] = 'synthetic-v1';
        $record['inputs'] = ['race' => 'R1'];
        unset($record);
        $this->bundle['entities']['rulesets'][] = [
            'key' => 'rules', 'edition' => 'Synthetic', 'authority_scope' => 'road', 'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0] = ['key' => 'heading', 'disposition' => 'record', 'records' => ['rulesets:rules']];
        $this->reject();
    }

    #[DataProvider('newKinds')]
    public function testRecomputedPointsAreRejected(string $kind): void
    {
        $this->bundle['entities'][$kind][0]['recomputed_points'] = '18';
        $this->reject();
    }

    #[DataProvider('newKinds')]
    public function testUnknownOriginIsRejected(string $kind): void
    {
        $this->bundle['entities'][$kind][0]['origin'] = 'estimated';
        $this->reject();
    }

    #[DataProvider('newKinds')]
    public function testBinarySourceCannotCarryStructuredStandingsOrRecords(string $kind): void
    {
        $source = "%PDF-1.4\nopaque standings document";
        $this->bundle['source']['format'] = 'pdf';
        $this->bundle['source']['sha256'] = hash('sha256', $source);
        $this->bundle['source']['bytes'] = strlen($source);
        $other = $kind === 'series_standings' ? 'association_records' : 'series_standings';
        $this->bundle['entities'][$other] = [];
        $this->bundle['entities'][$kind] = [$this->bundle['entities'][$kind][0]];
        $this->bundle['entities'][$kind][0]['source_locator'] = ['kind' => 'pdf_region', 'page' => 1];
        $this->bundle['coverage'] = [['key' => 'page', 'disposition' => 'record', 'records' => [$kind . ':' . $this->bundle['entities'][$kind][0]['key']]]];
        $this->reject(null, $source);
    }

    public function testBinarySourceStillAcceptsEmptyNewCollections(): void
    {
        $source = "%PDF-1.4\nopaque standings document";
        $this->bundle['source']['format'] = 'pdf';
        $this->bundle['source']['sha256'] = hash('sha256', $source);
        $this->bundle['source']['bytes'] = strlen($source);
        $this->bundle['entities']['series_standings'] = [];
        $this->bundle['entities']['association_records'] = [];
        $this->bundle['coverage'] = [['key' => 'page', 'disposition' => 'needs_review', 'records' => [], 'reason' => 'Conversion pending.']];
        self::assertSame([], $this->parse(null, $source)['entities']['series_standings']);
    }

    #[DataProvider('newKinds')]
    public function testCoverageMustAccountForEveryNewRecord(string $kind): void
    {
        $index = $kind === 'series_standings' ? 1 : 2;
        array_pop($this->bundle['coverage'][$index]['records']);
        $this->reject();
    }

    #[DataProvider('newKinds')]
    public function testDuplicateNewRecordKeyIsRejected(string $kind): void
    {
        $this->bundle['entities'][$kind][1]['key'] = $this->bundle['entities'][$kind][0]['key'];
        $this->reject();
    }

    public function testCoverageCannotReferenceAnAbsentNewRecord(): void
    {
        $this->bundle['coverage'][1]['records'][] = 'series_standings:absent';
        $this->reject();
    }

    public function testExtraFieldsAreRetainedInTheCandidate(): void
    {
        $this->bundle['entities']['series_standings'][0]['transcriber_note'] = 'Column header partly torn.';
        $this->bundle['entities']['association_records'][0]['ratification_reference'] = 'Private lookup.';
        $parsed = $this->parse();
        self::assertSame('Column header partly torn.', $parsed['entities']['series_standings'][0]['transcriber_note']);
        self::assertSame('Private lookup.', $parsed['entities']['association_records'][0]['ratification_reference']);
    }

    public function testCompleteReviewOfPrintedListsPasses(): void
    {
        $bundle = $this->parse();
        $report = $this->report($this->allReferences($bundle));
        self::assertSame($report, ArchiveReview::validate($bundle, hash('sha256', $this->json()), $report, 10, 20));
    }

    #[DataProvider('newKinds')]
    public function testUncheckedNewRecordBlocksPass(string $kind): void
    {
        $bundle = $this->parse();
        $checked = array_values(array_diff($this->allReferences($bundle), [$kind . ':' . $bundle['entities'][$kind][0]['key']]));
        $this->expectException(InvalidArgumentException::class);
        ArchiveReview::validate($bundle, hash('sha256', $this->json()), $this->report($checked), 10, 20);
    }

    public function testReviewFindingMayReferToANewRecord(): void
    {
        $bundle = $this->parse();
        $report = $this->report($this->allReferences($bundle));
        $report['outcome'] = 'fail';
        $report['findings'] = [['severity' => 'blocking', 'message' => 'Race column misread.', 'record' => 'series_standings:gp-b']];
        self::assertSame('fail', ArchiveReview::validate($bundle, hash('sha256', $this->json()), $report, 10, 20)['outcome']);
    }

    public function testLegacyReviewWithoutNewCollectionsStillPasses(): void
    {
        [$json, $source] = $this->legacyFixture();
        $bundle = ArchiveBundle::parse($json, $source);
        $report = [
            'bundle_sha256' => hash('sha256', $json), 'source_sha256' => hash('sha256', $source), 'outcome' => 'pass',
            'evidence' => 'Compared both source rows.', 'findings' => [],
            'checked_records' => ['performances:p1', 'performances:p2', 'rankings:r1', 'rankings:r2'],
        ];
        self::assertSame('pass', ArchiveReview::validate($bundle, hash('sha256', $json), $report, 10, 20)['outcome']);
    }

    public function testPublicProjectionExposesExactlyTheContractFields(): void
    {
        $this->bundle['entities']['series_standings'][0]['transcriber_note'] = 'Private note.';
        $this->bundle['entities']['series_standings'][0]['source_locator']['private_annotation'] = 'Private evidence.';
        $this->bundle['entities']['association_records'][0]['ratification_reference'] = 'Private lookup.';
        $public = ArchiveWorkflow::public_entities($this->parse());
        self::assertSame([
            'key', 'source_locator', 'standings_kind', 'series_label_as_printed', 'division_label_as_printed',
            'place_as_printed', 'name_as_printed', 'club_as_printed', 'race_points_as_printed', 'total_as_printed', 'origin',
        ], array_keys($public['series_standings'][0]));
        self::assertSame(['kind' => 'line', 'line' => 2], $public['series_standings'][0]['source_locator']);
        self::assertSame([
            'key', 'source_locator', 'list_label_as_printed', 'division_as_printed', 'event_as_printed',
            'holder_name_as_printed', 'club_as_printed', 'mark_as_printed', 'date_as_printed', 'date_set',
            'meet_as_printed', 'record_status_as_listed', 'is_relay',
        ], array_keys($public['association_records'][0]));
        $encoded = json_encode($public, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private', $encoded);
        self::assertStringContainsString('"race_points_as_printed":{"R1":"10","R2":"8"}', $encoded);
        self::assertStringContainsString('"race_points_as_printed":{}', $encoded);
    }

    public function testNumericRaceLabelsProjectAsAnOrderedObject(): void
    {
        $this->bundle['entities']['series_standings'][0]['race_points_as_printed'] = ['0' => '5', '2' => '7', '1' => '6'];
        $encoded = json_encode(ArchiveWorkflow::public_entities($this->parse())['series_standings'][0], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"race_points_as_printed":{"0":"5","2":"7","1":"6"}', $encoded);
    }

    public function testLegacyBundleProjectsNewCollectionsAsEmpty(): void
    {
        [$json, $source] = $this->legacyFixture();
        $public = ArchiveWorkflow::public_entities(ArchiveBundle::parse($json, $source));
        self::assertSame(ArchiveBundle::ENTITIES, array_keys($public));
        self::assertSame([], $public['series_standings']);
        self::assertSame([], $public['association_records']);
        self::assertCount(2, $public['performances']);
    }

    public function testStandingsRenderByDivisionWithRaceColumnsInFirstSeenOrder(): void
    {
        $this->bundle['entities']['series_standings'][1]['race_points_as_printed'] = ['R2' => '9', 'R3' => '4'];
        $xpath = $this->render(ArchiveWorkflow::public_entities($this->parse()));
        self::assertSame([
            ['Place', 'Name as printed', 'Club', 'R1', 'R2', 'R3', 'Total as printed'],
            ['1', 'Runner A', 'Club X', '10', '8', 'Not reported', '18'],
            ['2', 'Runner B', 'Not reported', 'Not reported', '9', '4', '9'],
        ], $this->table($xpath, 'Synthetic Grand Prix — Men Open — Individual standings'));
        self::assertSame([
            ['Place', 'Name as printed', 'Club', 'Total as printed'],
            ['1', 'Club X', 'Not reported', '20'],
        ], $this->table($xpath, 'Synthetic Grand Prix — Men Open — Team standings'));
        $region = $xpath->query('//table[caption="Synthetic Grand Prix — Men Open — Individual standings"]/parent::div[@role="region" and @tabindex="0"]');
        self::assertSame(1, $region->length);
        self::assertSame('Synthetic Grand Prix — Men Open — Individual standings', $region->item(0)->getAttribute('aria-label'));
        self::assertSame(0, $xpath->query('//table[caption="Performances"]')->length, 'Standings-only releases need no empty results table.');
    }

    public function testDivisionsKeepSeparateTablesInFirstSeenOrder(): void
    {
        $women = $this->bundle['entities']['series_standings'][0];
        $women['key'] = 'gp-w';
        $women['division_label_as_printed'] = 'Women Open';
        $women['name_as_printed'] = 'Runner W';
        array_unshift($this->bundle['entities']['series_standings'], $women);
        $this->bundle['coverage'][1]['records'][] = 'series_standings:gp-w';
        $xpath = $this->render(ArchiveWorkflow::public_entities($this->parse()));
        $captions = [];
        foreach ($xpath->query('//table/caption') as $caption) { $captions[] = $caption->textContent; }
        self::assertSame([
            'Synthetic Grand Prix — Women Open — Individual standings',
            'Synthetic Grand Prix — Men Open — Individual standings',
            'Synthetic Grand Prix — Men Open — Team standings',
            'Synthetic youth list — Girls 11-12',
        ], $captions);
    }

    public function testRecordsRenderByDivisionWithoutImplyingRatification(): void
    {
        $xpath = $this->render(ArchiveWorkflow::public_entities($this->parse()));
        self::assertSame([
            ['Event', 'Mark', 'Holder as printed', 'Club', 'Date', 'Meet', 'Status as listed'],
            ['1500m', '4:59.9', 'Runner C', 'Club Y', '6/5/99', 'Meet Z', 'PA list as published'],
            ['4x100 relay', 'Not reported', 'Not reported', 'Not reported', 'Not reported', 'Not reported', 'vacant as listed'],
        ], $this->table($xpath, 'Synthetic youth list — Girls 11-12'));
        $text = $xpath->query('//section')->item(0)->textContent;
        self::assertStringContainsString('does not ratify a record', $text);
    }

    public function testUntrustedPrintedValuesRenderAsText(): void
    {
        $attack = '<img src=x onerror="alert(1)">';
        $standing = &$this->bundle['entities']['series_standings'][0];
        $standing['name_as_printed'] = $attack;
        $standing['division_label_as_printed'] = '<script>alert(1)</script>';
        $standing['race_points_as_printed'] = ['<svg onload="alert(1)">' => '</td><b>10</b>'];
        unset($standing);
        $this->bundle['entities']['association_records'][0]['mark_as_printed'] = '"><iframe src=x>';
        $xpath = $this->render(ArchiveWorkflow::public_entities($this->parse()));
        $rows = $this->table($xpath, 'Synthetic Grand Prix — <script>alert(1)</script> — Individual standings');
        self::assertSame('<svg onload="alert(1)">', $rows[0][3]);
        self::assertSame($attack, $rows[1][1]);
        self::assertSame('</td><b>10</b>', $rows[1][3]);
        self::assertSame('"><iframe src=x>', $this->table($xpath, 'Synthetic youth list — Girls 11-12')[1][1]);
        self::assertSame(0, $xpath->query('//script|//img|//svg|//iframe|//b|//*[@onerror or @onload]')->length);
    }
}
