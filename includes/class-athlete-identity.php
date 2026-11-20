<?php
/**
 * One identity key per athlete, preferring PAUSATF Site's archive.
 *
 * The site's Archive is the canonical record of who is who: it joins differently printed
 * names, applies the owner's merge decisions, and splits people who share a name. When
 * that module is active (ArchiveOwnership::site_provides()), a name resolves to the
 * athlete page key of the archive's current build. The key is derived as the site
 * derives it (Meet_Rules::slug of Athlete_Joins::canon), and archive aliases are followed.
 * A key whose page lists several people is ambiguous and is not used.
 *
 * Otherwise RM falls back to its own athlete post ID, then to a normalized name, which
 * is flagged as unverified. Keys are not stored; they are resolved per request.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

use PAUSATF\Results\Archive\ArchiveOwnership;

if (!defined('ABSPATH')) {
    exit;
}

final class AthleteIdentity {
    public const SOURCE_ARCHIVE = 'archive';
    public const SOURCE_RM = 'rm';
    public const SOURCE_NAME = 'name';

    private const SITE_SLUG = ['PAUSATF\\SEO\\Archive\\Meet_Rules', 'slug'];
    private const SITE_CANON = ['PAUSATF\\SEO\\Archive\\Athlete_Joins', 'canon'];
    private const SITE_MODULE = 'PAUSATF\\SEO\\Archive\\Module';

    /** @var (\Closure(string): ?array{athlete_key: string, people: list<mixed>})|null|false false = not built yet */
    private static \Closure|null|false $lookup = false;

    /** @var array<string, array{key: string, source: string, verified: bool, ambiguous: bool}> */
    private static array $cache = [];

    /**
     * @return array{key: string, source: string, verified: bool, ambiguous: bool}
     *         key is "archive:<page key>", "rm:<post id>" or "name:<normalized name>".
     */
    public static function resolve(string $name, ?int $athlete_id = null): array {
        $cache_key = $name . "\0" . (string) $athlete_id;
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $ambiguous = false;
        $lookup = self::lookup();
        if ($lookup !== null) {
            foreach (self::archive_keys($name) as $candidate) {
                $row = $lookup($candidate);
                if ($row === null) {
                    continue;
                }
                if ($row['people'] !== []) {
                    $ambiguous = true;
                    break;
                }
                return self::$cache[$cache_key] = ['key' => 'archive:' . $row['athlete_key'], 'source' => self::SOURCE_ARCHIVE, 'verified' => true, 'ambiguous' => false];
            }
        }

        if ($athlete_id !== null && $athlete_id > 0) {
            $identity = ['key' => 'rm:' . $athlete_id, 'source' => self::SOURCE_RM, 'verified' => false, 'ambiguous' => $ambiguous];
        } else {
            $identity = ['key' => 'name:' . self::normalize($name), 'source' => self::SOURCE_NAME, 'verified' => false, 'ambiguous' => $ambiguous];
        }
        return self::$cache[$cache_key] = $identity;
    }

    /**
     * Archive page keys to try for a printed name: the joined form first, then the plain one.
     *
     * @return list<string>
     */
    public static function archive_keys(string $name): array {
        if (!is_callable(self::SITE_SLUG) || !is_callable(self::SITE_CANON)) {
            return [];
        }
        try {
            $canon = call_user_func(self::SITE_CANON, $name);
        } catch (\Throwable) {
            $canon = null;
        }
        $keys = [];
        foreach ([is_string($canon) ? $canon : null, $name] as $form) {
            if ($form !== null) {
                $keys[] = (string) call_user_func(self::SITE_SLUG, $form);
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * Replace the archive lookup (tests, or a site with its own source). Null disables it.
     *
     * @param (callable(string): ?array{athlete_key: string, people: list<mixed>})|null $lookup
     */
    public static function set_lookup(?callable $lookup): void {
        self::$lookup = $lookup === null ? null : \Closure::fromCallable($lookup);
        self::$cache = [];
    }

    /** Forget the lookup and cached keys. */
    public static function reset(): void {
        self::$lookup = false;
        self::$cache = [];
    }

    public static function normalize(string $name): string {
        $name = function_exists('remove_accents') ? remove_accents($name) : $name;
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)));
    }

    /** @return (\Closure(string): ?array{athlete_key: string, people: list<mixed>})|null */
    private static function lookup(): ?\Closure {
        if (self::$lookup === false) {
            self::$lookup = self::site_lookup();
        }
        return self::$lookup;
    }

    /**
     * Reads the site archive's current build. Any failure (module off, tables missing,
     * no current build) means no archive lookup for this request.
     */
    private static function site_lookup(): ?\Closure {
        $database = [self::SITE_MODULE, 'database'];
        if (!class_exists(ArchiveOwnership::class) || !ArchiveOwnership::site_provides() || !is_callable($database)) {
            return null;
        }
        try {
            $db = $database();
            $builds = $db->rows('SELECT id FROM ' . $db->table('archive_builds') . " WHERE state = 'current' ORDER BY id DESC LIMIT 1");
            $build = isset($builds[0]['id']) ? (int) $builds[0]['id'] : 0;
        } catch (\Throwable) {
            return null;
        }
        if ($build === 0) {
            return null;
        }

        return static function (string $key) use ($db, $build): ?array {
            try {
                $alias = $db->rows(
                    'SELECT new_key FROM ' . $db->table('archive_aliases') . ' WHERE build_id = %d AND kind = %s AND old_key = %s',
                    [$build, 'athlete', $key]
                );
                $key = (string) ($alias[0]['new_key'] ?? '') ?: $key;
                $rows = $db->rows(
                    'SELECT athlete_key, people FROM ' . $db->table('archive_athletes') . ' WHERE build_id = %d AND athlete_key = %s',
                    [$build, $key]
                );
            } catch (\Throwable) {
                return null;
            }
            if (!isset($rows[0]['athlete_key'])) {
                return null;
            }
            $people = json_decode((string) ($rows[0]['people'] ?? ''), true);
            return ['athlete_key' => (string) $rows[0]['athlete_key'], 'people' => is_array($people) ? array_values($people) : []];
        };
    }
}
