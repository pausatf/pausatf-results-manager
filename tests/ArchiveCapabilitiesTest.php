<?php
/**
 * Archive capability mapping: the producer of a release can never verify it.
 *
 * @package PAUSATF\Results\Tests
 */
declare(strict_types=1);

namespace PAUSATF\Results\Tests;

use PAUSATF\Results\Archive\ArchiveCapabilities;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/archive/class-archive-capabilities.php';

final class ArchiveCapabilitiesTest extends TestCase
{
    private static function producer(int $release): ?int
    {
        return [7 => 10, 8 => 11][$release] ?? null;
    }

    public function testAnotherUserNeedsTheVerifyCapability(): void
    {
        self::assertSame([ArchiveCapabilities::VERIFY], ArchiveCapabilities::verify_release_caps(20, 7, self::producer(...)));
    }

    public function testProducerCannotVerifyTheirOwnRelease(): void
    {
        self::assertSame(['do_not_allow'], ArchiveCapabilities::verify_release_caps(10, 7, self::producer(...)));
        // The same user may verify a release someone else produced.
        self::assertSame([ArchiveCapabilities::VERIFY], ArchiveCapabilities::verify_release_caps(10, 8, self::producer(...)));
    }

    public function testUnknownReleaseOrAnonymousUserIsNeverVerifiable(): void
    {
        self::assertSame(['do_not_allow'], ArchiveCapabilities::verify_release_caps(20, 99, self::producer(...)));
        self::assertSame(['do_not_allow'], ArchiveCapabilities::verify_release_caps(0, 7, self::producer(...)));
        self::assertSame(['do_not_allow'], ArchiveCapabilities::verify_release_caps(20, 0, self::producer(...)));
    }

    public function testOtherCapabilitiesPassThroughUnchanged(): void
    {
        self::assertSame(['edit_posts'], ArchiveCapabilities::filter_meta_cap(['edit_posts'], 'edit_post', 20, [1]));
    }
}
