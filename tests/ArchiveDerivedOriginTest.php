<?php
/**
 * Derived score fields cannot bypass applicability verification through origin defaults.
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

require_once dirname(__DIR__) . '/includes/archive/class-archive-bundle.php';
require_once dirname(__DIR__) . '/includes/archive/class-archive-review.php';

final class ArchiveDerivedOriginTest extends TestCase {
    private string $source;
    private array $bundle;

    protected function setUp(): void {
        $fixtures = __DIR__ . '/fixtures/archive-context/';
        $source = file_get_contents($fixtures . '1996-5ka-excerpt.txt');
        $json = file_get_contents($fixtures . '1996-5ka-bundle.json');
        self::assertNotFalse($source);
        self::assertNotFalse($json);
        $this->source = $source;
        $this->bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function sourceOriginVariants(): array {
        return ['omitted' => [[]], 'published' => [['origin' => 'published']], 'null' => [['origin' => null]]];
    }

    #[DataProvider('sourceOriginVariants')]
    public function testRecomputedPointsCannotUsePublishedOrDefaultOrigin(array $origin): void {
        $this->addScore(['recomputed_points' => '10'] + $origin);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recomputed points must be labelled as computed.');
        $this->parse();
    }

    public function testNullRecomputedFieldCannotHideBehindPublishedOrigin(): void {
        $this->addScore(['recomputed_points' => null]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recomputed points must be labelled as computed.');
        $this->parse();
    }

    public function testPublishedPointsRemainAcceptableWithoutInventedRules(): void {
        $this->addScore(['published_points' => '10']);
        $parsed = $this->parse();
        self::assertSame([], $parsed['entities']['rulesets']);
        $report = $this->report();
        self::assertSame($report, ArchiveReview::validate($parsed, $report['bundle_sha256'], $report, 10, 20));
        self::assertSame('10', $parsed['entities']['scores'][0]['published_points']);
    }

    public function testComputedScoreRequiresRecordedInputs(): void {
        $this->addComputedScore();
        unset($this->bundle['entities']['scores'][0]['inputs']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Derived values require recorded inputs.');
        $this->parse();
    }

    public function testComputedScoreRequiresCalculationVersion(): void {
        $this->addComputedScore();
        unset($this->bundle['entities']['scores'][0]['calculation_version']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid text field: calculation_version.');
        $this->parse();
    }

    public function testComputedScoreRequiresIndependentApplicabilityEvidence(): void {
        $this->addComputedScore();
        $parsed = $this->parse();
        $report = $this->report();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Derived values require a verified applicable ruleset.');
        ArchiveReview::validate($parsed, $report['bundle_sha256'], $report, 10, 20);
    }

    public function testVerifiedDatedComputedScoreRetainsSeparateOriginAndInputs(): void {
        $this->addComputedScore();
        $parsed = $this->parse();
        $report = $this->report(true);
        self::assertSame($report, ArchiveReview::validate($parsed, $report['bundle_sha256'], $report, 10, 20));
        self::assertSame('computed', $parsed['entities']['scores'][0]['origin']);
        self::assertSame('10', $parsed['entities']['scores'][0]['recomputed_points']);
        self::assertSame(['performance_key' => 'p1'], $parsed['entities']['scores'][0]['inputs']);
    }

    public function testUnknownRulesetCannotBeApprovedByAnotherVerifiedRule(): void {
        $this->addComputedScore();
        $this->bundle['entities']['scores'][0]['ruleset_key'] = 'unknown-rule';
        $report = $this->report(true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Derived values require a verified applicable ruleset.');
        ArchiveReview::validate($this->bundle, $report['bundle_sha256'], $report, 10, 20);
    }

    public function testReviewDefenseRejectsMislabelledRecomputedScore(): void {
        $this->addScore(['recomputed_points' => '10', 'origin' => 'published']);
        $report = $this->report();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recomputed points require the derived-value review gate.');
        ArchiveReview::validate($this->bundle, $report['bundle_sha256'], $report, 10, 20);
    }

    private function addScore(array $fields): void {
        $this->bundle['entities']['scores'][] = $fields + [
            'key' => 'score-case', 'performance_key' => 'p1', 'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'scores:score-case';
    }

    private function addComputedScore(): void {
        $location = ['kind' => 'line', 'line' => 1];
        $this->bundle['entities']['rulesets'][] = [
            'key' => 'synthetic-1996', 'edition' => 'Synthetic applicability fixture', 'authority_scope' => 'road',
            'effective_from' => '1996-01-01', 'effective_until' => '1996-12-31', 'source_locator' => $location,
        ];
        $this->bundle['entities']['competitions'][] = [
            'key' => 'fixture-meet', 'meet_date' => '1996-04-24', 'source_locator' => $location,
        ];
        $this->bundle['coverage'][0]['records'][] = 'rulesets:synthetic-1996';
        $this->bundle['coverage'][0]['records'][] = 'competitions:fixture-meet';
        $this->addScore([
            'origin' => 'computed', 'recomputed_points' => '10', 'ruleset_key' => 'synthetic-1996',
            'calculation_version' => 'synthetic-v1', 'inputs' => ['performance_key' => 'p1'],
        ]);
    }

    private function parse(): array {
        return ArchiveBundle::parse(json_encode($this->bundle, JSON_THROW_ON_ERROR), $this->source);
    }

    private function report(bool $verifyRule = false): array {
        $checked = [];
        foreach (ArchiveBundle::ENTITIES as $kind) {
            foreach ($this->bundle['entities'][$kind] as $record) { $checked[] = $kind . ':' . $record['key']; }
        }
        return [
            'bundle_sha256' => hash('sha256', json_encode($this->bundle, JSON_THROW_ON_ERROR)),
            'source_sha256' => $this->bundle['source']['sha256'], 'outcome' => 'pass',
            'evidence' => 'Synthetic gate fixture; no historical score is recomputed or asserted.',
            'checked_records' => $checked, 'findings' => [],
            'verified_rulesets' => $verifyRule ? [['key' => 'synthetic-1996', 'evidence' => 'Synthetic ruleset covers the fixture date.']] : [],
        ];
    }
}
