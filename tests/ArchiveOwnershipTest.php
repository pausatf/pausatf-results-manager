<?php
/**
 * The archive module stands down when PAUSATF Site's Archive is active.
 *
 * @package PAUSATF\Results\Tests
 */

declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Archive\ArchiveOwnership;
use PHPUnit\Framework\TestCase;

final class ArchiveOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/includes/archive/class-archive-ownership.php';
        unset($GLOBALS['pausatf_test_filters']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pausatf_test_filters']);
    }

    public function testSiteArchiveAbsentKeepsTheBundledArchive(): void
    {
        // Stubs are process-wide, so this must run before the site stubs exist.
        self::assertFalse(class_exists('PAUSATF\\SEO\\Archive\\Module', false));
        self::assertFalse(ArchiveOwnership::site_provides());
    }

    /** @depends testSiteArchiveAbsentKeepsTheBundledArchive */
    public function testSiteArchiveActiveDefersTheBundledArchive(): void
    {
        eval('namespace PAUSATF\\SEO\\Archive { final class Module {} } namespace { function pausatf_archive_register_hooks(): void {} }');
        self::assertTrue(ArchiveOwnership::site_provides());
    }

    /** @depends testSiteArchiveActiveDefersTheBundledArchive */
    public function testSiteModuleSwitchedOffKeepsTheBundledArchive(): void
    {
        eval('namespace PAUSATF\\SEO { final class Modules { public static bool $archive = false; public static function enabled(string $module): bool { return $module !== "archive" || self::$archive; } } }');
        self::assertFalse(ArchiveOwnership::site_provides());
        \PAUSATF\SEO\Modules::$archive = true;
        self::assertTrue(ArchiveOwnership::site_provides());
    }

    /** @depends testSiteModuleSwitchedOffKeepsTheBundledArchive */
    public function testFilterForcesEitherAnswer(): void
    {
        $GLOBALS['pausatf_test_filters']['pausatf_results_defer_archive_to_site'] = static fn($active) => false;
        self::assertFalse(ArchiveOwnership::site_provides());
        $GLOBALS['pausatf_test_filters']['pausatf_results_defer_archive_to_site'] = static fn($active) => true;
        self::assertTrue(ArchiveOwnership::site_provides());
    }
}
