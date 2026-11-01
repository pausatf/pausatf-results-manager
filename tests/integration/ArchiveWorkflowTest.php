<?php
/**
 * Native MySQL contracts for archive staging, verification and publication.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests\Integration;

use InvalidArgumentException;
use mysqli;
use mysqli_driver;
use PAUSATF\Results\Archive\ArchiveBundle;
use PAUSATF\Results\Archive\ArchiveDatabase;
use PAUSATF\Results\Archive\ArchiveReview;
use PAUSATF\Results\Archive\ArchiveSchema;
use PAUSATF\Results\Archive\ArchiveWorkflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-bundle.php';
require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-review.php';
require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-database.php';
require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-schema.php';
require_once dirname(__DIR__, 2) . '/includes/archive/class-archive-workflow.php';

final class ArchiveWorkflowTest extends TestCase
{
    private const TABLES = [
        'archive_rulesets', 'archive_competitions', 'archive_divisions', 'archive_rounds',
        'archive_performances', 'archive_attempts', 'archive_rankings', 'archive_teams',
        'archive_scores', 'archive_result_releases', 'archive_record_claims',
        'archive_reviews', 'archive_releases', 'archive_sources', 'archive_schema',
    ];

    private ?mysqli $connection = null;
    private ?ArchiveDatabase $database = null;
    private ?ArchiveWorkflow $workflow = null;
    private ?int $reportMode = null;
    private string $prefix = '';
    private string $source;
    private string $json;
    private array $bundle;

    protected function setUp(): void
    {
        if (getenv('PAUSATF_ARCHIVE_TEST_MYSQL_HOST') === false) {
            self::markTestSkipped('Set PAUSATF_ARCHIVE_TEST_MYSQL_HOST to run native archive workflow tests.');
        }
        self::assertTrue(extension_loaded('mysqli'));
        $host = (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_HOST');
        self::assertContains($host, ['127.0.0.1', 'localhost', '::1']);
        $name = (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_DATABASE');
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_]*test[A-Za-z0-9_]*\z/', $name);
        $this->reportMode = (new mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $this->connection = new mysqli(
                $host,
                (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_USER'),
                (string) getenv('PAUSATF_ARCHIVE_TEST_MYSQL_PASSWORD'),
                $name,
                (int) (getenv('PAUSATF_ARCHIVE_TEST_MYSQL_PORT') ?: 3306)
            );
            $this->connection->set_charset('utf8mb4');
        } catch (Throwable) {
            mysqli_report($this->reportMode);
            self::fail('Unable to connect to the explicitly configured disposable MySQL.');
        }
        self::assertStringStartsWith('8.4.', (string) $this->scalar('SELECT VERSION()'));
        $this->prefix = 'arw_' . bin2hex(random_bytes(6)) . '_';
        $this->database = new ArchiveDatabase($this->connection, $this->prefix);
        $this->workflow = new ArchiveWorkflow($this->database);
        $this->workflow->install();
        $fixtures = dirname(__DIR__) . '/fixtures/archive-context/';
        $source = file_get_contents($fixtures . '1996-5ka-excerpt.txt');
        $json = file_get_contents($fixtures . '1996-5ka-bundle.json');
        self::assertNotFalse($source);
        self::assertNotFalse($json);
        $this->source = $source;
        $this->json = $json;
        $this->bundle = ArchiveBundle::parse($json, $source);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null) {
                $this->connection->rollback();
                $this->connection->autocommit(true);
                if ($this->database !== null) {
                    $this->connection->query('SET FOREIGN_KEY_CHECKS=0');
                    try {
                        foreach (self::TABLES as $suffix) {
                            $table = $this->database->table($suffix);
                            self::assertStringStartsWith($this->prefix . 'pausatf_', $table);
                            $this->connection->query('DROP TABLE IF EXISTS ' . $table);
                        }
                    } finally {
                        $this->connection->query('SET FOREIGN_KEY_CHECKS=1');
                    }
                }
                $this->connection->close();
            }
        } finally {
            if ($this->reportMode !== null) {
                mysqli_report($this->reportMode);
            }
        }
    }

    private function scalar(string $query): mixed
    {
        $result = $this->connection->query($query);
        try {
            return $result->fetch_row()[0];
        } finally {
            $result->free();
        }
    }

    private function encoded(): string
    {
        return json_encode($this->bundle, JSON_THROW_ON_ERROR);
    }

    private function report(string $json, string $outcome = 'pass'): string
    {
        $bundle = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $checked = [];
        foreach (ArchiveBundle::ENTITIES as $kind) {
            foreach ($bundle['entities'][$kind] as $record) {
                $checked[] = $kind . ':' . $record['key'];
            }
        }
        return json_encode([
            'bundle_sha256' => hash('sha256', $json),
            'source_sha256' => $bundle['source']['sha256'],
            'outcome' => $outcome,
            'evidence' => 'Independent source comparison of the exact artifact and every printed field.',
            'checked_records' => $checked,
            'findings' => $outcome === 'fail' ? [[
                'severity' => 'blocking', 'message' => 'Transcription requires correction.',
                'record' => 'performances:p1',
            ]] : [],
        ], JSON_THROW_ON_ERROR);
    }

    private function accept(string $json): int
    {
        $id = $this->workflow->stage($json, $this->source, 10);
        $this->workflow->review($id, $this->report($json), $this->source, 20);
        $this->workflow->publish($id);
        return $id;
    }

    private function orderedObjectKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$entry) {
            $entry = $this->orderedObjectKeys($entry);
        }
        unset($entry);
        return $value;
    }

    private function rejectOperation(callable $operation): void
    {
        $failed = false;
        try {
            $operation();
        } catch (InvalidArgumentException | RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed, 'The invalid operation must fail explicitly.');
    }

    public function testCandidateRerunIsIdempotentAndPrivate(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        self::assertGreaterThan(0, $id);
        self::assertSame($id, $this->workflow->stage($this->json, $this->source, 30));
        self::assertNull($this->workflow->published($id));
        $release = $this->workflow->inspect($id);
        self::assertSame('candidate', $release['state']);
        self::assertSame(10, (int) $release['producer_user_id']);
        self::assertSame($this->json, $release['bundle_json']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_sources')));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_releases')));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_performances')));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_rankings')));
        self::assertSame($id, (int) $this->workflow->recent()[0]['id']);
    }

    public function testProducerCannotVerifyAndUnverifiedArtifactCannotPublish(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        $this->rejectOperation(fn() => $this->workflow->review($id, $this->report($this->json), $this->source, 10));
        $this->rejectOperation(fn() => $this->workflow->publish($id));
        self::assertSame('candidate', $this->workflow->inspect($id)['state']);
        self::assertNull($this->workflow->inspect($id)['review']);
        self::assertNull($this->workflow->published($id));
    }

    public function testReviewRejectsSwappedSourceAndStaleArtifactHashWithoutWrites(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        $this->rejectOperation(fn() => $this->workflow->review($id, $this->report($this->json), $this->source . 'changed', 20));
        $report = json_decode($this->report($this->json), true, 64, JSON_THROW_ON_ERROR);
        $report['bundle_sha256'] = str_repeat('0', 64);
        $this->rejectOperation(fn() => $this->workflow->review($id, json_encode($report, JSON_THROW_ON_ERROR), $this->source, 20));
        self::assertNull($this->workflow->inspect($id)['review']);
        self::assertSame('candidate', $this->workflow->inspect($id)['state']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_reviews')));
    }

    public function testMalformedReviewIsValidationFailureWithoutWrites(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        foreach (['{', "{\"evidence\":\"\xFF\"}", str_repeat('[', 65) . str_repeat(']', 65)] as $report) {
            try {
                $this->workflow->review($id, $report, $this->source, 20);
                self::fail('Malformed review must fail validation.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Review report must be valid UTF-8 JSON.', $error->getMessage());
            }
            self::assertSame('candidate', $this->workflow->inspect($id)['state']);
            self::assertNull($this->workflow->inspect($id)['review']);
        }
    }

    public function testVerifiedReleaseIsStillPrivateUntilExplicitPublication(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        $this->workflow->review($id, $this->report($this->json), $this->source, 20);
        self::assertSame('verified', $this->workflow->inspect($id)['state']);
        self::assertNull($this->workflow->published($id));
        $this->workflow->publish($id);
        self::assertNotNull($this->workflow->published($id));
        $this->workflow->publish($id);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_reviews')));
    }

    public function testPublishedContextPreservesTimesCodesWindAndRankings(): void
    {
        $p = &$this->bundle['entities']['performances'];
        $p[0]['qualifier_raw'] = 'Q';
        $p[1]['qualifier_raw'] = 'q';
        $p[0]['wind'] = null;
        $p[1]['wind'] = '0.0';
        $p[0]['gun_time_raw'] = '14:13.127';
        $p[0]['net_time_raw'] = '14:12.983';
        $p[0]['normalized_value'] = '853.127';
        unset($p);
        $extra = $this->bundle['entities']['rankings'][0];
        $extra['key'] = 'division-rank';
        $extra['scope'] = 'division';
        $extra['published_place'] = '1';
        $this->bundle['entities']['rankings'][] = $extra;
        $this->bundle['coverage'][0]['records'][] = 'rankings:division-rank';
        $id = $this->accept($this->encoded());
        $public = $this->workflow->published($id);
        self::assertSame(['Q', 'q'], array_column($public['entities']['performances'], 'qualifier_raw'));
        self::assertNull($public['entities']['performances'][0]['wind']);
        self::assertSame('0.0', $public['entities']['performances'][1]['wind']);
        self::assertSame('14:13.127', $public['entities']['performances'][0]['gun_time_raw']);
        self::assertSame('14:12.983', $public['entities']['performances'][0]['net_time_raw']);
        self::assertSame('853.127', $public['entities']['performances'][0]['normalized_value']);
        self::assertSame(['1', '2', '1'], array_column($public['entities']['rankings'], 'published_place'));
        $rows = $this->database->rows('SELECT payload FROM ' . $this->database->table('archive_performances') . ' WHERE release_id=? ORDER BY ordinal', [$id]);
        self::assertSame('853.127', json_decode($rows[0]['payload'], true, 64, JSON_THROW_ON_ERROR)['normalized_value']);
    }

    public function testEveryContextFamilyPersistsAndProjectsWithoutFlattening(): void
    {
        // Synthetic, explicitly labelled records exercise persistence, not historical rules.
        $this->source = "Synthetic context fixture; no historical results.\n"
            . "Runner A 14:13 Q; Runner B 14:13 q.\n"
            . "Division D; heat 1; club Test Team; points 100.2500; attempt mark 14:13.\n"
            . "Synthetic edition 2026, dated 2026-07-12; preliminary claim.\n";
        $this->bundle['competition_key'] = 'synthetic-context-roundtrip';
        $this->bundle['source']['path'] = 'synthetic-context.txt';
        $this->bundle['source']['sha256'] = hash('sha256', $this->source);
        $this->bundle['source']['bytes'] = strlen($this->source);
        $location = ['kind' => 'line', 'line' => 4];
        $entities = &$this->bundle['entities'];
        $entities['rulesets'] = [[
            'key' => 'rules', 'edition' => 'Synthetic edition 2026',
            'authority_scope' => 'synthetic-tests', 'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31', 'source_locator' => $location,
        ]];
        $entities['competitions'] = [[
            'key' => 'meet', 'title' => 'Synthetic context fixture', 'meet_date' => '2026-07-12',
            'discipline' => 'track', 'source_locator' => $location,
        ]];
        $entities['divisions'] = [[
            'key' => 'division', 'published_label' => 'Division D',
            'age_reference_basis' => 'unknown', 'source_locator' => ['kind' => 'line', 'line' => 3],
        ]];
        $entities['rounds'] = [[
            'key' => 'heat', 'division_key' => 'division', 'round_kind' => 'heat', 'heat' => '1',
            'source_locator' => ['kind' => 'line', 'line' => 3],
        ]];
        foreach ($entities['performances'] as $index => &$performance) {
            $performance['athlete_name_raw'] = $index === 0 ? 'Runner A' : 'Runner B';
            $performance['source_locator'] = ['kind' => 'line', 'line' => 2];
            $performance['division_key'] = 'division';
            $performance['round_key'] = 'heat';
        }
        unset($performance);
        $entities['attempts'] = [[
            'key' => 'attempt', 'performance_key' => 'p1', 'attempt_order' => 1,
            'mark_raw' => '14:13', 'source_locator' => ['kind' => 'line', 'line' => 3],
        ]];
        $entities['teams'] = [[
            'key' => 'team', 'club_as_reported' => 'Test Team', 'division_key' => 'division',
            'member_keys' => ['p1', 'p2'], 'scoring_member_keys' => ['p1'], 'displacer_keys' => ['p2'],
            'source_locator' => ['kind' => 'line', 'line' => 3],
        ]];
        $entities['scores'] = [[
            'key' => 'score', 'team_key' => 'team', 'published_points' => '100.2500',
            'origin' => 'published', 'source_locator' => ['kind' => 'line', 'line' => 3],
        ]];
        $entities['result_releases'] = [[
            'key' => 'release', 'status_as_reported' => 'preliminary', 'source_locator' => $location,
        ]];
        $entities['record_claims'] = [[
            'key' => 'claim', 'performance_key' => 'p1', 'record_type_as_printed' => 'Synthetic claim',
            'claimed_status' => 'unverified', 'source_locator' => $location,
        ]];
        unset($entities);
        $references = [];
        foreach ($this->bundle['entities'] as $kind => $records) {
            foreach ($records as $record) {
                $references[] = $kind . ':' . $record['key'];
            }
        }
        $this->bundle['coverage'] = [[
            'key' => 'synthetic-records', 'disposition' => 'record', 'records' => $references,
        ]];
        $id = $this->accept($this->encoded());
        $public = $this->workflow->published($id);
        foreach (ArchiveBundle::TABLES as $kind => $suffix) {
            $rows = $this->database->rows('SELECT record_key, payload FROM ' . $this->database->table($suffix) . ' WHERE release_id=? ORDER BY ordinal', [$id]);
            self::assertCount(count($this->bundle['entities'][$kind]), $rows, $kind);
            foreach ($rows as $index => $row) {
                $actual = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
                self::assertSame($this->orderedObjectKeys($this->bundle['entities'][$kind][$index]), $this->orderedObjectKeys($actual), $kind);
            }
            self::assertCount(count($rows), $public['entities'][$kind], $kind);
        }
        self::assertSame(['p1'], $public['entities']['teams'][0]['scoring_member_keys']);
        self::assertSame(['p2'], $public['entities']['teams'][0]['displacer_keys']);
        self::assertSame('100.2500', $public['entities']['scores'][0]['published_points']);
        self::assertSame('preliminary', $public['entities']['result_releases'][0]['status_as_reported']);
        self::assertSame('unverified', $public['entities']['record_claims'][0]['claimed_status']);
        self::assertSame('not_determined_by_extraction_review', $public['record_ratification']);
    }

    public function testFailedReviewIsImmutableAndDuplicateCannotResetRejection(): void
    {
        $id = $this->workflow->stage($this->json, $this->source, 10);
        $this->workflow->review($id, $this->report($this->json, 'fail'), $this->source, 20);
        self::assertSame('rejected', $this->workflow->inspect($id)['state']);
        self::assertSame($id, $this->workflow->stage($this->json, $this->source, 30));
        $this->rejectOperation(fn() => $this->workflow->review($id, $this->report($this->json), $this->source, 30));
        $this->rejectOperation(fn() => $this->workflow->publish($id));
        self::assertSame('rejected', $this->workflow->inspect($id)['state']);
        self::assertSame('fail', json_decode($this->workflow->inspect($id)['review']['report_json'], true, 64, JSON_THROW_ON_ERROR)['outcome']);
    }

    public function testCorrectedArtifactDoesNotInheritPriorVerification(): void
    {
        $original = $this->accept($this->json);
        $this->bundle['supersedes_sha256'] = hash('sha256', $this->json);
        $this->bundle['entities']['performances'][0]['publisher_annotation'] = 'Corrected transcription version.';
        $changed = $this->encoded();
        $next = $this->workflow->stage($changed, $this->source, 10);
        self::assertNotSame($original, $next);
        self::assertSame($original, (int) $this->workflow->inspect($next)['supersedes_id']);
        self::assertSame('candidate', $this->workflow->inspect($next)['state']);
        self::assertNull($this->workflow->inspect($next)['review']);
        self::assertNull($this->workflow->published($next));
        $this->rejectOperation(fn() => $this->workflow->publish($next));
        self::assertNotNull($this->workflow->published($original));
    }

    public function testFutureRulesetCannotVerifyHistoricalComputedRankings(): void
    {
        $location = ['kind' => 'line', 'line' => 1];
        $this->bundle['entities']['rulesets'][] = [
            'key' => 'future-rules', 'edition' => 'Synthetic 2026 rules', 'authority_scope' => 'road',
            'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31', 'source_locator' => $location,
        ];
        $this->bundle['entities']['competitions'][] = [
            'key' => 'meet', 'meet_date' => '1996-04-24', 'source_locator' => $location,
        ];
        $ranking = &$this->bundle['entities']['rankings'][0];
        $ranking['origin'] = 'computed';
        $ranking['ruleset_key'] = 'future-rules';
        $ranking['calculation_version'] = 'synthetic-test';
        $ranking['inputs'] = ['performance_key' => 'p1'];
        unset($ranking);
        $this->bundle['coverage'][0]['records'][] = 'rulesets:future-rules';
        $this->bundle['coverage'][0]['records'][] = 'competitions:meet';
        $json = $this->encoded();
        $report = json_decode($this->report($json), true, 64, JSON_THROW_ON_ERROR);
        $report['verified_rulesets'] = [['key' => 'future-rules', 'evidence' => 'Wrong-year test fixture.']];
        $id = $this->workflow->stage($json, $this->source, 10);
        $this->rejectOperation(fn() => $this->workflow->review($id, json_encode($report, JSON_THROW_ON_ERROR), $this->source, 20));
        self::assertSame('candidate', $this->workflow->inspect($id)['state']);
        self::assertNull($this->workflow->inspect($id)['review']);
    }

    public function testLateEntityFailureRollsBackWholeNewBatchAndKeepsAcceptedRelease(): void
    {
        $accepted = $this->accept($this->json);
        $before = $this->workflow->published($accepted);
        $newSource = $this->source . "Synthetic new-source version.\n";
        $this->bundle['source']['sha256'] = hash('sha256', $newSource);
        $this->bundle['source']['bytes'] = strlen($newSource);
        $json = $this->encoded();
        $table = $this->database->table('archive_rankings');
        $trigger = $this->prefix . 'fail_rankings';
        $this->connection->query('SET @pausatf_injected_ranking_failure = 0');
        $this->connection->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN SET @pausatf_injected_ranking_failure = 1; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected late ranking failure'; END");
        try {
            $this->rejectOperation(fn() => $this->workflow->stage($json, $newSource, 10));
        } finally {
            $this->connection->query('DROP TRIGGER ' . $trigger);
        }
        self::assertSame(1, (int) $this->scalar('SELECT @pausatf_injected_ranking_failure'), 'Failure injection must reach the later ranking INSERT.');
        self::assertSame($before, $this->workflow->published($accepted));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_sources')));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_releases')));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->database->table('archive_performances')));
        $new = $this->workflow->stage($json, $newSource, 10);
        self::assertNotSame($accepted, $new);
        self::assertSame('candidate', $this->workflow->inspect($new)['state']);
    }

    public function testPublicProjectionOmitsPrivateAnnotationsAndDoesNotRatifyClaims(): void
    {
        $this->bundle['internal_notes'] = 'Private operator note.';
        $this->bundle['entities']['performances'][0]['private_annotation'] = 'Private transcription note.';
        $this->bundle['entities']['performances'][0]['source_locator']['private_annotation'] = 'Private nested evidence.';
        $this->bundle['entities']['record_claims'][] = [
            'key' => 'claim', 'performance_key' => 'p1', 'record_type_as_printed' => 'Synthetic record claim',
            'claimed_status' => 'claimed', 'ratification_evidence' => 'Private ratification investigation.',
            'source_locator' => ['kind' => 'line', 'line' => 1],
        ];
        $this->bundle['coverage'][0]['records'][] = 'record_claims:claim';
        $id = $this->accept($this->encoded());
        $public = $this->workflow->published($id);
        self::assertArrayNotHasKey('producer_user_id', $public);
        self::assertArrayNotHasKey('review', $public);
        self::assertArrayNotHasKey('bundle_json', $public);
        self::assertArrayNotHasKey('internal_notes', $public);
        self::assertArrayNotHasKey('private_annotation', $public['entities']['performances'][0]);
        self::assertArrayNotHasKey('private_annotation', $public['entities']['performances'][0]['source_locator']);
        self::assertArrayNotHasKey('ratification_evidence', $public['entities']['record_claims'][0]);
        self::assertSame('claimed', $public['entities']['record_claims'][0]['claimed_status']);
        self::assertSame('not_determined_by_extraction_review', $public['record_ratification']);
        self::assertStringNotContainsString('Private', json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function testWithdrawalHidesOnlyThatPublishedVersion(): void
    {
        $id = $this->accept($this->json);
        self::assertNotNull($this->workflow->published($id));
        $this->workflow->withdraw($id);
        self::assertNull($this->workflow->published($id));
        self::assertSame('withdrawn', $this->workflow->inspect($id)['state']);
        $this->workflow->withdraw($id);
        $this->rejectOperation(fn() => $this->workflow->publish($id));
    }

    public function testPublishedArtifactTamperingIsDetected(): void
    {
        $id = $this->accept($this->json);
        $table = $this->database->table('archive_releases');
        $this->database->execute("UPDATE {$table} SET bundle_json=? WHERE id=?", [$this->json . ' ', $id]);
        $this->rejectOperation(fn() => $this->workflow->published($id));
    }
}
