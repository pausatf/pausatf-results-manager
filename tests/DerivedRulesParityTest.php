<?php
/**
 * Derived archive rules against results recorded from the static preview's Python.
 *
 * The fixtures in tests/fixtures/derived/ are written by tests/parity/derived_parity.py
 * (`fixtures` command); DerivedFullParityTest covers the whole archive when a capture is present.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Archive\Derived\AthleteJoiner;
use PAUSATF\Results\Archive\Derived\ClubGrouper;
use PAUSATF\Results\Archive\Derived\EventNormalizer;
use PAUSATF\Results\Archive\Derived\PythonText;
use PAUSATF\Results\Archive\Derived\SequenceMatcher;
use PAUSATF\Results\Archive\Derived\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DerivedRulesParityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/derived/class-event-normalizer.php';
        require_once dirname(__DIR__) . '/includes/archive/derived/class-club-grouper.php';
        require_once dirname(__DIR__) . '/includes/archive/derived/class-athlete-joiner.php';
    }

    private static function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__ . "/fixtures/derived/{$name}.json");
        self::assertIsString($json);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEventOf(): void
    {
        $cases = self::fixture('events')['event_of'];
        self::assertGreaterThan(300, count($cases));
        foreach ($cases as [$sport, $label, $title, $want]) {
            self::assertSame($want, EventNormalizer::eventOf($sport, $label, $title), "{$sport} | {$label} | {$title}");
        }
    }

    public function testMarkValues(): void
    {
        $fx = self::fixture('events');
        self::assertGreaterThan(300, count($fx['value']));
        foreach ($fx['value'] as [$event, $mark, $want]) {
            // assertSame keeps Python's int/float distinction (63 vs 63.0).
            self::assertSame($want, EventNormalizer::value($event, $mark), json_encode([$event, $mark]));
        }
        foreach ($fx['secs'] as [$mark, $want]) {
            self::assertSame($want, EventNormalizer::secs($mark), 'secs ' . $mark);
        }
        foreach ($fx['distance'] as [$mark, $want]) {
            self::assertSame($want, EventNormalizer::distance($mark), 'distance ' . $mark);
        }
        foreach ($fx['road_distance'] as [$text, $want]) {
            self::assertSame($want, EventNormalizer::roadDistance($text), 'road ' . $text);
        }
    }

    public function testClubBase(): void
    {
        $cases = self::fixture('clubs')['base'];
        self::assertGreaterThan(300, count($cases));
        foreach ($cases as [$name, $want]) {
            self::assertSame($want, ClubGrouper::base($name), $name);
        }
    }

    public function testSequenceMatcherRatio(): void
    {
        $cases = self::fixture('clubs')['ratio'];
        self::assertGreaterThan(150, count($cases));
        foreach ($cases as [$a, $b, $want]) {
            self::assertSame((float) $want, SequenceMatcher::ratioOf($a, $b), "{$a} / {$b}");
        }
    }

    public function testClubGroups(): void
    {
        $fx = self::fixture('clubs')['build'];
        [$map, $groups] = ClubGrouper::buildGroups($fx['counts'], $fx['overrides'], $fx['manual'], $fx['separate'], $fx['rosters']);
        self::assertSame($fx['name_to_group'], self::pairs($map));
        self::assertSame($fx['groups'], self::pairs($groups));
        self::assertNotEmpty(array_filter($groups, static fn(array $g): bool => [] !== $g['roster_evidence']), 'fixture exercises roster evidence');
    }

    public function testAthleteParts(): void
    {
        foreach (self::fixture('athletes')['parts'] as [$name, $want]) {
            self::assertSame($want, AthleteJoiner::parts($name), $name);
        }
    }

    public function testAthleteJoins(): void
    {
        $fx = self::fixture('athletes')['build'];
        [$map, $evidence] = AthleteJoiner::build($fx['entries'], $fx['team_of'], $fx['manual'], $fx['separate']);
        self::assertSame($fx['map'], self::pairs($map));
        self::assertSame($fx['evidence'], $evidence);
    }

    public function testSlug(): void
    {
        foreach (self::fixture('athletes')['slug'] as [$text, $want]) {
            self::assertSame($want, Slug::of($text), $text);
        }
    }

    /** Python's \b, \s and \w differ from PCRE2's UCP classes; the expected values come from CPython 3.12. */
    #[DataProvider('pythonRegexCases')]
    public function testPythonRegexClasses(string $pattern, string $subject, ?string $want): void
    {
        $m = PythonText::search($pattern, $subject);
        self::assertSame($want, null === $m ? null : $m[0]);
    }

    public static function pythonRegexCases(): array
    {
        return [
            'combining mark ends a word' => ['\\b\\w+\\b', "Jose\u{0301}", 'Jose'],
            'combining mark is not \\w' => ['\\w+', "e\u{0301}x", 'e'],
            'information separator is \\s' => ['a\\sb', "a\u{1f}b", "a\u{1f}b"],
            'mongolian vowel separator is not \\s' => ['a\\sb', "a\u{180e}b", null],
            'letters and numbers are \\w' => ['\\w+', 'Ñandú²', 'Ñandú²'],
        ];
    }

    /** @return list<array{0:string,1:mixed}> */
    private static function pairs(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[] = [(string) $k, $v];
        }
        return $out;
    }
}
