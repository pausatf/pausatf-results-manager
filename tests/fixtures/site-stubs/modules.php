<?php
/**
 * Minimal stand-in for PAUSATF Site's module switches, loaded by ArchiveOwnershipTest.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\SEO;

final class Modules
{
    public static bool $archive = false;

    public static function enabled(string $module): bool
    {
        return $module !== 'archive' || self::$archive;
    }
}
