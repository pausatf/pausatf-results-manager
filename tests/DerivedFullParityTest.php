<?php
/**
 * Full-archive parity of the derived PHP rules with the static preview's Python.
 *
 * Opt-in: set PAUSATF_DERIVED_PARITY_DIR to a directory written by
 * `python3 -I tests/parity/derived_parity.py capture --meets-root <preview checkout> --out <dir>`.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Archive\Derived\AthleteJoiner;
use PAUSATF\Results\Archive\Derived\ClubGrouper;
use PAUSATF\Results\Archive\Derived\EventNormalizer;
use PHPUnit\Framework\TestCase;

final class DerivedFullParityTest extends TestCase
{
    private static ?array $inputs = null;
    private static ?array $expected = null;

    public static function setUpBeforeClass(): void
    {
        $dir = getenv('PAUSATF_DERIVED_PARITY_DIR');
        if (!is_string($dir) || '' === $dir) {
            return;
        }
        require_once dirname(__DIR__) . '/includes/archive/derived/class-event-normalizer.php';
        require_once dirname(__DIR__) . '/includes/archive/derived/class-club-grouper.php';
        require_once dirname(__DIR__) . '/includes/archive/derived/class-athlete-joiner.php';
        ini_set('memory_limit', '6G');
        self::$inputs = json_decode((string) file_get_contents($dir . '/inputs.json'), true, 512, JSON_THROW_ON_ERROR);
        self::$expected = json_decode((string) file_get_contents($dir . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        if (null === self::$inputs) {
            self::markTestSkipped('Set PAUSATF_DERIVED_PARITY_DIR to a derived_parity.py capture to run full-archive parity.');
        }
    }

    public function testCaptureUsesOnlyCodePointsKnownToBothUnicodeTables(): void
    {
        self::assertSame([], self::$expected['unassigned_code_points']);
    }

    public function testEventsMatch(): void
    {
        $in = self::$inputs['events'];
        $out = self::$expected['events'];
        $bad = [];
        foreach ($in['event_of'] as $i => [$sport, $label, $title]) {
            $got = EventNormalizer::eventOf($sport, $label, $title);
            if ($got !== $out['event_of'][$i]) {
                $bad[] = [$sport, $label, $title, $out['event_of'][$i], $got];
            }
        }
        foreach ($in['value'] as $i => [$event, $mark]) {
            $got = EventNormalizer::value($event, $mark);
            if ($got !== $out['value'][$i]) {
                $bad[] = [$event, $mark, $out['value'][$i], $got];
            }
        }
        self::report('events', count($in['event_of']) + count($in['value']), $bad);
    }

    public function testClubGroupsMatch(): void
    {
        foreach (self::$inputs['clubs'] as $call => $in) {
            $want = self::$expected['clubs'][$call];
            $unstable = self::$expected['order_dependent'][$call];
            $bad = [];
            foreach ($want['base'] as [$name, $core]) {
                if (ClubGrouper::base((string) $name) !== $core) {
                    $bad[] = ['base', $name, $core, ClubGrouper::base((string) $name)];
                }
            }
            [$map, $groups] = ClubGrouper::buildGroups($in['counts'], $in['overrides'], $in['manual'], $in['separate'], $in['rosters']);
            $gotMap = self::pairs($map);
            $unstableGroups = [];
            foreach ($unstable as $alternatives) {
                foreach ($alternatives as $g) {
                    $unstableGroups[$g] = true;
                }
            }
            $wantMap = $want['name_to_group'];
            $stable = static fn(array $names): array => array_values(array_filter($names, static fn($n): bool => !isset($unstable[$n])));
            if ($stable(array_column($wantMap, 0)) !== $stable(array_column($gotMap, 0))) {
                $bad[] = ['name order differs'];
            }
            $stableGroups = static fn(array $keys): array => array_values(array_filter(array_map('strval', $keys), static fn(string $k): bool => !isset($unstableGroups[$k])));
            if ($stableGroups(array_column($want['groups'], 0)) !== $stableGroups(array_keys($groups))) {
                $bad[] = ['group order differs'];
            }
            $gotByName = array_column($gotMap, 1, 0);
            foreach ($wantMap as [$name, $group]) {
                $got = $gotByName[$name] ?? null;
                $ok = isset($unstable[$name]) ? in_array($got, $unstable[$name], true) : $got === $group;
                if (!$ok) {
                    $bad[] = ['group', $name, $group, $got];
                }
            }
            $gotGroups = self::pairs($groups);
            $gotByKey = array_column($gotGroups, 1, 0);
            $compared = 0;
            foreach ($want['groups'] as [$key, $group]) {
                if (isset($unstableGroups[$key])) {
                    continue;
                }
                ++$compared;
                $got = $gotByKey[$key] ?? null;
                if ($got !== $group) {
                    $bad[] = ['group detail', $key, $group, $got];
                }
            }
            self::report("clubs call {$call}: " . count($want['base']) . ' cores, ' . count($wantMap) . ' names, ' . $compared . ' groups (' . count($unstableGroups) . ' order-dependent)', count($want['base']) + count($wantMap) + $compared, $bad);
        }
    }

    public function testAthleteKeysMatch(): void
    {
        foreach (self::$inputs['athletes'] as $call => $in) {
            $want = self::$expected['athletes'][$call];
            $bad = [];
            foreach ($want['canon'] as [$name, $canon]) {
                if (AthleteJoiner::canon((string) $name) !== $canon) {
                    $bad[] = ['canon', $name, $canon, AthleteJoiner::canon((string) $name)];
                }
            }
            [$map, $evidence] = AthleteJoiner::build($in['entries'], $in['team_of'], $in['manual'], $in['separate']);
            $gotMap = self::pairs($map);
            if ($gotMap !== $want['map']) {
                $gotByName = array_column($gotMap, 1, 0);
                foreach ($want['map'] as [$name, $key]) {
                    if (($gotByName[$name] ?? null) !== $key) {
                        $bad[] = ['key', $name, $key, $gotByName[$name] ?? null];
                    }
                }
                if (!$bad) {
                    $bad[] = ['map order differs'];
                }
            }
            if ($evidence !== $want['evidence']) {
                $bad[] = ['evidence', count($want['evidence']), count($evidence)];
            }
            self::report("athletes call {$call}: " . count($want['canon']) . ' names, ' . count($want['map']) . ' keys, ' . count($want['evidence']) . ' evidence joins', count($want['canon']) + count($want['map']) + count($want['evidence']), $bad);
        }
    }

    /**
     * Map entries as [string key, value] pairs, undoing PHP's int keys for numeric names.
     *
     * @return list<array{0:string,1:mixed}>
     */
    private static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[] = [(string) $k, $v];
        }
        return $out;
    }

    private static function report(string $what, int $compared, array $bad): void
    {
        fwrite(STDERR, sprintf("\n[derived parity] %s: %d compared, %d mismatches", $what, $compared, count($bad)));
        self::assertSame([], array_slice($bad, 0, 20), $what . ': ' . count($bad) . ' mismatches');
    }
}
