<?php
/**
 * Independent review gates for immutable archive candidates.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use InvalidArgumentException;
use PAUSATF\Results\Archive\ArchiveBundle;
use PAUSATF\Results\Archive\ArchiveReview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchiveReviewTest extends TestCase
{
    private array $bundle;
    private string $hash;
    private array $report;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/class-archive-bundle.php';
        require_once dirname(__DIR__) . '/includes/archive/class-archive-review.php';
        $fixtures = __DIR__ . '/fixtures/archive-context/';
        $json = file_get_contents($fixtures . '1996-5ka-bundle.json');
        $source = file_get_contents($fixtures . '1996-5ka-excerpt.txt');
        self::assertNotFalse($json);
        self::assertNotFalse($source);
        $this->bundle = ArchiveBundle::parse($json, $source);
        $this->hash = hash('sha256', $json);
        $this->report = [
            'bundle_sha256' => $this->hash,
            'source_sha256' => hash('sha256', $source),
            'outcome' => 'pass',
            'evidence' => 'Compared both original source rows and every candidate field, including printed places and truncated names.',
            'checked_records' => ['performances:p1', 'performances:p2', 'rankings:r1', 'rankings:r2'],
            'findings' => [],
        ];
    }

    private function validate(?array $report = null, int $producer = 10, int $reviewer = 20): array
    {
        return ArchiveReview::validate($this->bundle, $this->hash, $report ?? $this->report, $producer, $reviewer);
    }

    private function reject(array $report): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validate($report);
    }

    private function addRecord(string $kind, array $record): void
    {
        $this->bundle['entities'][$kind][] = $record;
        $reference = $kind . ':' . $record['key'];
        $this->bundle['coverage'][0]['records'][] = $reference;
        $this->report['checked_records'][] = $reference;
    }

    /** Synthetic calculation metadata exercises applicability, not historical rescoring. */
    private function useComputedRanking(string $from = '1996-01-01', string $until = '1996-12-31'): void
    {
        $location = ['kind' => 'line', 'line' => 1];
        $this->addRecord('rulesets', [
            'key' => 'calculation-test', 'edition' => 'Synthetic calculation test',
            'authority_scope' => 'road', 'effective_from' => $from, 'effective_until' => $until,
            'source_locator' => $location,
        ]);
        $this->addRecord('competitions', [
            'key' => 'competition', 'meet_date' => '1996-04-24', 'source_locator' => $location,
        ]);
        $ranking = &$this->bundle['entities']['rankings'][0];
        $ranking['origin'] = 'computed';
        $ranking['ruleset_key'] = 'calculation-test';
        $ranking['calculation_version'] = 'synthetic-test-v1';
        $ranking['inputs'] = ['performance_key' => 'p1'];
        unset($ranking);
        $this->report['verified_rulesets'] = [[
            'key' => 'calculation-test', 'evidence' => 'Synthetic date-interval gate fixture, not a real historical rule determination.',
        ]];
    }

    public function testIndependentCompleteReviewAcceptsRawResultsWithoutInventedRules(): void
    {
        self::assertSame([], $this->bundle['entities']['rulesets']);
        self::assertSame($this->report, $this->validate());
    }

    public static function invalidActors(): array
    {
        return [[10, 10], [0, 20], [10, 0], [-1, 20], [10, -1]];
    }

    #[DataProvider('invalidActors')]
    public function testReviewRequiresDifferentPositiveActors(int $producer, int $reviewer): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validate(null, $producer, $reviewer);
    }

    public function testReviewIsBoundToExactBundleRevision(): void
    {
        $this->report['bundle_sha256'] = str_repeat('0', 64);
        $this->reject($this->report);
    }

    public function testReviewIsBoundToOriginalSourceHash(): void
    {
        $this->report['source_sha256'] = str_repeat('0', 64);
        $this->reject($this->report);
    }

    public function testUnexaminedRecordsBlockPass(): void
    {
        array_pop($this->report['checked_records']);
        $this->reject($this->report);
    }

    public function testDuplicateCheckedRecordDoesNotInflateCoverage(): void
    {
        $this->report['checked_records'][3] = 'rankings:r1';
        $this->reject($this->report);
    }

    public function testForeignCheckedRecordIsRejected(): void
    {
        $this->report['checked_records'][] = 'performances:foreign';
        $this->reject($this->report);
    }

    public function testScalarCheckedRecordIsRejectedBeforeLookup(): void
    {
        $this->report['checked_records'][0] = ['performances:p1'];
        $this->reject($this->report);
    }

    public function testBlockingFindingPreventsPass(): void
    {
        $this->report['findings'][] = [
            'severity' => 'blocking', 'message' => 'The published place was transcribed incorrectly.',
            'record' => 'rankings:r1',
        ];
        $this->reject($this->report);
    }

    public function testWarningRemainsInAcceptedReviewEvidence(): void
    {
        $this->report['findings'][] = [
            'severity' => 'warning', 'message' => 'The printed name is truncated; the candidate preserves that truncation.',
            'record' => 'performances:p1',
        ];
        self::assertSame($this->report, $this->validate());
    }

    public function testFindingCannotReferToAnotherCandidate(): void
    {
        $this->report['findings'][] = [
            'severity' => 'warning', 'message' => 'Other record.', 'record' => 'performances:foreign',
        ];
        $this->reject($this->report);
    }

    public function testPassRequiresIndependentComparisonEvidence(): void
    {
        $this->report['evidence'] = '  ';
        $this->reject($this->report);
    }

    public function testUnknownOutcomeIsNotTreatedAsPass(): void
    {
        $this->report['outcome'] = 'approved';
        $this->reject($this->report);
    }

    public function testFailedReviewMayPreservePartialEvidenceAndBlockingFindings(): void
    {
        $this->report['outcome'] = 'fail';
        $this->report['checked_records'] = ['performances:p1'];
        $this->report['findings'] = [[
            'severity' => 'blocking', 'message' => 'Source comparison failed; stop review.',
            'record' => 'performances:p1',
        ]];
        self::assertSame($this->report, $this->validate());
    }

    public function testUnresolvedSourceCoverageBlocksPass(): void
    {
        $this->bundle['coverage'][] = [
            'key' => 'unresolved-note', 'disposition' => 'needs_review', 'records' => [],
            'reason' => 'Not yet interpreted.',
        ];
        $this->reject($this->report);
    }

    public function testComputedRankingNeedsIndependentRulesetEvidence(): void
    {
        $this->useComputedRanking();
        unset($this->report['verified_rulesets']);
        $this->reject($this->report);
    }

    public function testCurrentRulesCannotValidateComputedHistoricalRankings(): void
    {
        $this->useComputedRanking('2026-01-01', '2026-12-31');
        $this->reject($this->report);
    }

    public function testDatedVerifiedRulesetCanValidateComputedRanking(): void
    {
        $this->useComputedRanking();
        self::assertSame($this->report, $this->validate());
    }

    public function testUndatedRulesCannotValidateComputedRankings(): void
    {
        $this->useComputedRanking();
        unset($this->bundle['entities']['rulesets'][0]['effective_until']);
        $this->reject($this->report);
    }

    public function testUnknownCompetitionDateBlocksComputedRanking(): void
    {
        $this->useComputedRanking();
        unset($this->bundle['entities']['competitions'][0]['meet_date']);
        $this->reject($this->report);
    }

    public function testForeignVerifiedRulesetIsRejected(): void
    {
        $this->report['verified_rulesets'] = [['key' => 'foreign', 'evidence' => 'Unrelated edition.']];
        $this->reject($this->report);
    }

    public function testRulesetVerificationRequiresEvidence(): void
    {
        $this->useComputedRanking();
        $this->report['verified_rulesets'][0]['evidence'] = '';
        $this->reject($this->report);
    }

    public function testVerificationDoesNotRatifyARecordClaim(): void
    {
        $this->addRecord('record_claims', [
            'key' => 'claim', 'performance_key' => 'p1',
            'record_type_as_printed' => 'Synthetic record claim',
            'claimed_status' => 'claimed', 'ratification_evidence' => null,
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ]);
        $original = $this->bundle;
        $result = $this->validate();
        self::assertSame($original, $this->bundle);
        self::assertSame($this->report, $result);
        self::assertArrayNotHasKey('record_ratified', $result);
        self::assertNull($this->bundle['entities']['record_claims'][0]['ratification_evidence']);
    }
}
