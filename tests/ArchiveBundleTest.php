<?php
/**
 * Archive candidate validation against independently annotated source evidence.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use InvalidArgumentException;
use PAUSATF\Results\Archive\ArchiveBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchiveBundleTest extends TestCase
{
    private string $source;
    private array $bundle;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/class-archive-bundle.php';
        $fixtures = __DIR__ . '/fixtures/archive-context/';
        $source = file_get_contents($fixtures . '1996-5ka-excerpt.txt');
        $json = file_get_contents($fixtures . '1996-5ka-bundle.json');
        self::assertNotFalse($source);
        self::assertNotFalse($json);
        $this->source = $source;
        $this->bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function parse(?array $bundle = null, ?string $source = null): array
    {
        return ArchiveBundle::parse(
            json_encode($bundle ?? $this->bundle, JSON_THROW_ON_ERROR),
            $source ?? $this->source
        );
    }

    private function reject(array $bundle, ?string $source = null): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parse($bundle, $source);
    }

    public function testSourceAnnotationIsPreservedExactly(): void
    {
        self::assertSame(hash('sha256', $this->source), $this->bundle['source']['sha256']);
        self::assertSame(strlen($this->source), $this->bundle['source']['bytes']);
        self::assertSame($this->bundle, $this->parse());
    }

    public function testEqualDisplayedTimesDoNotCreateATieOrReorderPlaces(): void
    {
        $parsed = $this->parse();
        self::assertSame(['14:13', '14:13'], array_column($parsed['entities']['performances'], 'raw_mark'));
        self::assertSame(['1', '2'], array_column($parsed['entities']['rankings'], 'published_place'));
        self::assertSame('Christophe Impens, Albuqu', $parsed['entities']['performances'][0]['athlete_name_raw']);
        self::assertSame('Matt Guisto, Albuquerque', $parsed['entities']['performances'][1]['athlete_name_raw']);
    }

    public function testUnknownStatusDivisionAndWindAreNotInvented(): void
    {
        $parsed = $this->parse();
        foreach ($parsed['entities']['performances'] as $performance) {
            self::assertArrayNotHasKey('status_raw', $performance);
            self::assertArrayNotHasKey('division_key', $performance);
            self::assertArrayNotHasKey('wind', $performance);
        }
        self::assertSame([], $parsed['entities']['rulesets']);
    }

    public function testQualificationCaseAndMissingVersusZeroWindRemainDistinct(): void
    {
        $this->bundle['entities']['performances'][0]['qualifier_raw'] = 'Q';
        $this->bundle['entities']['performances'][1]['qualifier_raw'] = 'q';
        $this->bundle['entities']['performances'][0]['wind'] = null;
        $this->bundle['entities']['performances'][1]['wind'] = '0.0';
        $performances = $this->parse()['entities']['performances'];
        self::assertSame('Q', $performances[0]['qualifier_raw']);
        self::assertSame('q', $performances[1]['qualifier_raw']);
        self::assertNull($performances[0]['wind']);
        self::assertSame('0.0', $performances[1]['wind']);
    }

    public function testFractionalGunAndNetTimesRemainSeparateStrings(): void
    {
        $performance = &$this->bundle['entities']['performances'][0];
        $performance['gun_time_raw'] = '14:13.127';
        $performance['net_time_raw'] = '14:12.983';
        $performance['normalized_value'] = '853.127';
        $performance['unit'] = 'seconds';
        unset($performance);
        $parsed = $this->parse()['entities']['performances'][0];
        self::assertSame('14:13.127', $parsed['gun_time_raw']);
        self::assertSame('14:12.983', $parsed['net_time_raw']);
        self::assertSame('853.127', $parsed['normalized_value']);
    }

    public static function statusCodes(): array
    {
        return [['DNS'], ['DNF'], ['DQ'], ['NM'], ['Unrecognized source notation']];
    }

    #[DataProvider('statusCodes')]
    public function testPublishedStatusRemainsVerbatim(string $status): void
    {
        $this->bundle['entities']['performances'][0]['status_raw'] = $status;
        self::assertSame($status, $this->parse()['entities']['performances'][0]['status_raw']);
    }

    public function testAdditionalRawEvidenceIsNotSilentlyDropped(): void
    {
        $this->bundle['entities']['performances'][0]['publisher_annotation'] = [
            'original' => '  disputed mark <not HTML>  ',
            'unknown' => null,
        ];
        self::assertSame($this->bundle, $this->parse());
    }

    public function testInvalidJsonProducesControlledValidationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArchiveBundle::parse('{"schema_version":', $this->source);
    }

    public function testTopLevelListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArchiveBundle::parse('[]', $this->source);
    }

    public function testUnsupportedSchemaVersionIsRejected(): void
    {
        $this->bundle['schema_version'] = 2;
        $this->reject($this->bundle);
    }

    public function testScalarSourceIsRejectedBeforeIndexAccess(): void
    {
        $this->bundle['source'] = 'not an object';
        $this->reject($this->bundle);
    }

    public function testScalarEntityListIsRejected(): void
    {
        $this->bundle['entities']['performances'] = 'not an array';
        $this->reject($this->bundle);
    }

    public function testMissingEntityListIsRejected(): void
    {
        unset($this->bundle['entities']['attempts']);
        $this->reject($this->bundle);
    }

    public function testChangedSourceHashIsRejected(): void
    {
        $this->bundle['source']['sha256'] = str_repeat('0', 64);
        $this->reject($this->bundle);
    }

    public function testChangedSourceLengthIsRejected(): void
    {
        $this->bundle['source']['bytes']++;
        $this->reject($this->bundle);
    }

    public function testDuplicateRecordKeyIsRejected(): void
    {
        $this->bundle['entities']['performances'][1]['key'] = 'p1';
        $this->reject($this->bundle);
    }

    public function testUnknownPerformanceReferenceIsRejected(): void
    {
        $this->bundle['entities']['rankings'][0]['performance_key'] = 'absent';
        $this->reject($this->bundle);
    }

    public function testAbsentSourceLocatorIsRejected(): void
    {
        unset($this->bundle['entities']['performances'][0]['source_locator']);
        $this->reject($this->bundle);
    }

    public function testNonpositiveLineLocatorIsRejected(): void
    {
        $this->bundle['entities']['performances'][0]['source_locator']['line'] = 0;
        $this->reject($this->bundle);
    }

    public function testOutOfBoundsByteLocatorIsRejected(): void
    {
        $this->bundle['entities']['performances'][0]['source_locator'] = [
            'kind' => 'byte_range', 'start' => 0, 'end' => strlen($this->source) + 1,
        ];
        $this->reject($this->bundle);
    }

    public function testDecimalFloatIsRejectedInsteadOfLosingPrecision(): void
    {
        $this->bundle['entities']['performances'][0]['normalized_value'] = 853.127;
        $this->reject($this->bundle);
    }

    public function testCoverageCannotOmitARecord(): void
    {
        array_pop($this->bundle['coverage'][0]['records']);
        $this->reject($this->bundle);
    }

    public function testCoverageCannotClaimTheSameRecordTwice(): void
    {
        $this->bundle['coverage'][1]['records'][] = 'performances:p1';
        $this->reject($this->bundle);
    }

    public function testCoverageCannotReferenceAnAbsentRecord(): void
    {
        $this->bundle['coverage'][0]['records'][] = 'performances:absent';
        $this->reject($this->bundle);
    }

    public function testUnresolvedSourceEvidenceIsRetainedForReview(): void
    {
        $this->bundle['coverage'][] = [
            'key' => 'unresolved-note', 'disposition' => 'needs_review',
            'records' => [], 'reason' => 'The source note has not been interpreted.',
        ];
        self::assertSame($this->bundle, $this->parse());
    }

    public function testComputedRankingWithoutReproducibleInputsIsRejected(): void
    {
        $this->bundle['entities']['rankings'][0]['origin'] = 'computed';
        $this->reject($this->bundle);
    }

    public function testPublishedPlaceNotationIsNotStrippedToAnInteger(): void
    {
        $this->bundle['entities']['rankings'][0]['published_place'] = '=2';
        $this->bundle['entities']['rankings'][1]['published_place'] = '2T';
        self::assertSame(['=2', '2T'], array_column($this->parse()['entities']['rankings'], 'published_place'));
    }

    public function testBinarySignatureOverridesHtmlExtensionAndDeclaredFormat(): void
    {
        $source = hex2bin('d0cf11e0a1b11ae1') . str_repeat("\0", 48);
        $this->bundle['source']['format'] = 'html';
        $this->bundle['source']['path'] = 'misleading.html';
        $this->bundle['source']['sha256'] = hash('sha256', $source);
        $this->bundle['source']['bytes'] = strlen($source);
        $this->reject($this->bundle, $source);
    }

    public function testCaseDistinctRecordKeysRemainDistinct(): void
    {
        $this->bundle['entities']['performances'][1]['key'] = 'P1';
        $this->bundle['entities']['rankings'][1]['performance_key'] = 'P1';
        $this->bundle['coverage'][1]['records'][0] = 'performances:P1';
        self::assertSame(['p1', 'P1'], array_column($this->parse()['entities']['performances'], 'key'));
    }

    public function testDuplicateCoverageKeyIsRejected(): void
    {
        $this->bundle['coverage'][1]['key'] = 'line1';
        $this->reject($this->bundle);
    }

    public function testMissingSourceDispositionReasonIsRejected(): void
    {
        $this->bundle['coverage'][] = [
            'key' => 'omitted', 'disposition' => 'excluded', 'records' => [],
        ];
        $this->reject($this->bundle);
    }

    public function testRulesetCannotContainAnInvalidCalendarDate(): void
    {
        $this->bundle['entities']['rulesets'][] = [
            'key' => 'rules', 'edition' => 'Unverified test edition',
            'authority_scope' => 'road', 'effective_from' => '2026-02-30',
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'rulesets:rules';
        $this->reject($this->bundle);
    }

    public function testScoreCannotHaveBothTeamAndIndividualSubjects(): void
    {
        $this->bundle['entities']['scores'][] = [
            'key' => 'score', 'team_key' => 'team', 'performance_key' => 'p1',
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'scores:score';
        $this->reject($this->bundle);
    }

    public function testTeamRolesMustReferToDeclaredMembers(): void
    {
        $this->bundle['entities']['teams'][] = [
            'key' => 'team', 'member_keys' => ['p1'], 'scoring_member_keys' => ['p2'],
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'teams:team';
        $this->reject($this->bundle);
    }

    public function testSourceUrlCannotContainCredentials(): void
    {
        $this->bundle['source']['url'] = 'https://operator:example@example.invalid/result.html';
        $this->reject($this->bundle);
    }

    public function testExecutableSourceUrlIsRejected(): void
    {
        $this->bundle['source']['url'] = 'javascript:alert(1)';
        $this->reject($this->bundle);
    }

    public function testBlankRawCellsRemainDistinctFromMissingAndNull(): void
    {
        $performance = &$this->bundle['entities']['performances'][0];
        $performance['raw_mark'] = '';
        $performance['status_raw'] = '  ';
        $performance['qualifier_raw'] = null;
        $performance['gun_time_raw'] = '';
        $performance['net_time_raw'] = "\t";
        unset($performance);
        self::assertSame($this->bundle, $this->parse());
        self::assertArrayNotHasKey('status_raw', $this->parse()['entities']['performances'][1]);
    }

    public function testPublishedScoresKeepExactDecimalStrings(): void
    {
        $score = [
            'key' => 'score', 'performance_key' => 'p1',
            'published_points' => '00100.2500', 'event_multiplier' => '1.500',
            'origin' => 'published', 'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['entities']['scores'][] = $score;
        $this->bundle['coverage'][0]['records'][] = 'scores:score';
        self::assertSame($score, $this->parse()['entities']['scores'][0]);
    }

    public function testFloatingPointPublishedScoreIsRejected(): void
    {
        $this->bundle['entities']['scores'][] = [
            'key' => 'score', 'performance_key' => 'p1', 'published_points' => 100.25,
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'scores:score';
        $this->reject($this->bundle);
    }

    public function testFloatingPointAgeGradeIsRejected(): void
    {
        $this->bundle['entities']['rankings'][0]['age_grade_published'] = 98.75;
        $this->reject($this->bundle);
    }

    public function testFloatingPointEventMultiplierIsRejected(): void
    {
        $this->bundle['entities']['scores'][] = [
            'key' => 'score', 'performance_key' => 'p1', 'event_multiplier' => 1.5,
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'scores:score';
        $this->reject($this->bundle);
    }

    public static function declaredBinaryFormats(): array
    {
        return [['ole'], ['pdf'], ['zip'], ['elf']];
    }

    #[DataProvider('declaredBinaryFormats')]
    public function testDeclaredBinaryFormatNeedsItsActualSignature(string $format): void
    {
        $this->bundle['source']['format'] = $format;
        $this->reject($this->bundle);
    }

    public static function opaqueSources(): array
    {
        return [
            ['ole', hex2bin('d0cf11e0a1b11ae1') . str_repeat("\0", 48)],
            ['pdf', "%PDF-1.4\nopaque document"],
            ['zip', "PK\x03\x04opaque document"],
            ['elf', "\x7fELFopaque executable"],
        ];
    }

    #[DataProvider('opaqueSources')]
    public function testOpaqueSourceCannotMasqueradeAsStructuredResults(string $format, string $source): void
    {
        $this->bundle['source']['format'] = $format;
        $this->bundle['source']['sha256'] = hash('sha256', $source);
        $this->bundle['source']['bytes'] = strlen($source);
        $this->reject($this->bundle, $source);
    }

    #[DataProvider('opaqueSources')]
    public function testOpaqueDocumentMetadataCanBePreservedWithoutFabricatedResults(string $format, string $source): void
    {
        $this->bundle['source']['format'] = $format;
        $this->bundle['source']['sha256'] = hash('sha256', $source);
        $this->bundle['source']['bytes'] = strlen($source);
        foreach ($this->bundle['entities'] as &$records) {
            $records = [];
        }
        unset($records);
        $this->bundle['coverage'] = [[
            'key' => 'opaque-document', 'disposition' => 'needs_review',
            'records' => [], 'reason' => 'Controlled conversion has not been performed.',
        ]];
        self::assertSame($this->bundle, $this->parse(null, $source));
    }
}
