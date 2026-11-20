<?php
/**
 * Stand-ins for two PAUSATF Site archive classes, for AthleteIdentity tests. slug() copies
 * the site's Meet_Rules::slug rule; canon() handles only the "Last, First" and
 * "First [Middle] Last" forms the tests use (the site's Athlete_Joins::canon does more).
 */

namespace PAUSATF\SEO\Archive {
    if (!class_exists(Meet_Rules::class)) {
        final class Meet_Rules {
            public static function slug(string $text): string {
                $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-'), 0, 80);
                return $slug === '' ? 'x' : $slug;
            }
        }
    }
    if (!class_exists(Athlete_Joins::class)) {
        final class Athlete_Joins {
            public static function canon(string $name): ?string {
                $name = strtolower(trim($name));
                if (str_contains($name, ',')) {
                    [$last, $first] = array_map('trim', explode(',', $name, 2));
                    $name = $first . ' ' . $last;
                }
                $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                return count($words) >= 2 ? $words[0] . ' ' . end($words) : null;
            }
        }
    }
}
