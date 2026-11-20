<?php
/**
 * Sex as the results list it, and the USATF category it is scored in.
 *
 * Results keep the value exactly as listed (`sex_listed`). The `sex` column holds the
 * scoring category: M, or F for a listed F or W (and the spelled-out forms). Any other
 * value, such as X, NB or blank, has no USATF scoring category and stays null; it is
 * never folded into the men's category, so those results are shown but not scored.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class ListedSex {
    /** Longest listed value kept; matches the sex_listed column. */
    public const MAX_LENGTH = 20;

    /** Results schema revision that added sex_listed. */
    public const SCHEMA_OPTION = 'pausatf_results_schema';
    public const SCHEMA_VERSION = 1;

    private const MEN = ['M', 'MALE', 'MAN', 'MEN', 'BOY', 'BOYS'];
    private const WOMEN = ['F', 'W', 'FEMALE', 'WOMAN', 'WOMEN', 'GIRL', 'GIRLS'];

    /** The scoring category (M or F) for a listed value, or null. */
    public static function category(?string $listed): ?string {
        $value = strtoupper(trim((string) $listed));
        if (in_array($value, self::MEN, true)) {
            return 'M';
        }
        if (in_array($value, self::WOMEN, true)) {
            return 'F';
        }
        return null;
    }

    /** The listed value, trimmed and cut to the column length; null when blank. */
    public static function listed(?string $listed): ?string {
        $value = trim(sanitize_text_field((string) $listed));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, self::MAX_LENGTH);
    }

    /** Whether the results table has the sex_listed column yet (see Plugin::maybe_upgrade()). */
    public static function schema_ready(): bool {
        return (int) get_option(self::SCHEMA_OPTION, 0) >= self::SCHEMA_VERSION;
    }

    /**
     * Columns to write for a listed value.
     *
     * @return array<string, ?string>
     */
    public static function columns(?string $listed): array {
        $columns = ['sex' => self::category($listed)];
        if (self::schema_ready()) {
            $columns['sex_listed'] = self::listed($listed);
        }
        return $columns;
    }
}
