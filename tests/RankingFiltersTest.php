<?php
/**
 * Ranking event and division filters (MySQL REGEXP patterns, checked here with PCRE on the
 * same subset of syntax).
 */

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\RankingSystem;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-ranking-system.php';

class RankingFiltersTest extends TestCase {
    private static function titleMatches(string $event, string $title): bool {
        [$include, $exclude] = RankingSystem::event_title_filter($event);
        $ok = preg_match('/' . str_replace('/', '\/', $include) . '/', $title) === 1;
        return $ok && ($exclude === null || preg_match('/' . $exclude . '/', $title) !== 1);
    }

    public function test_5k_does_not_match_15k_25k_or_50k(): void {
        self::assertTrue(self::titleMatches('5K', 'Banana Chase 5K'));
        self::assertTrue(self::titleMatches('5K', 'Stow Lake 5 K Run'));
        self::assertFalse(self::titleMatches('5K', 'Presidio 15K'));
        self::assertFalse(self::titleMatches('5K', 'Clarksburg 25K'));
        self::assertFalse(self::titleMatches('5K', 'Firetrails 50K'));
        self::assertFalse(self::titleMatches('5K', 'Zippy 1.5K Kids'));
    }

    public function test_marathon_does_not_match_half_marathon(): void {
        self::assertTrue(self::titleMatches('Marathon', 'California International Marathon'));
        self::assertFalse(self::titleMatches('Marathon', 'Humboldt Redwoods Half Marathon'));
        self::assertTrue(self::titleMatches('Half Marathon', 'Humboldt Redwoods Half Marathon'));
    }

    public function test_miles(): void {
        self::assertTrue(self::titleMatches('10 Miles', 'California 10 Mile'));
        self::assertTrue(self::titleMatches('10 Miles', 'Clarksburg 10-Mile'));
        self::assertFalse(self::titleMatches('10 Miles', 'American River 100 Miles'));
    }

    public function test_division_code_is_a_whole_leading_token(): void {
        $pattern = '/' . RankingSystem::division_filter('M40') . '/';
        self::assertSame(1, preg_match($pattern, 'M40'));
        self::assertSame(1, preg_match($pattern, 'M40-44'));
        self::assertSame(0, preg_match($pattern, 'M45'));
        self::assertSame(0, preg_match($pattern, 'M400'));
        self::assertNull(RankingSystem::division_filter(''));
        self::assertNull(RankingSystem::division_filter('all'));
    }
}
