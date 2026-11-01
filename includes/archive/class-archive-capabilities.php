<?php
/**
 * WordPress capabilities for the archive workflow.
 *
 * Producers stage candidates, verifiers submit independent reviews, publishers publish and
 * withdraw. Administrators hold all three, so existing sites keep working. The rule that the
 * verifier must not be the producer is a meta capability resolved per release, so WordPress's
 * own permission checks enforce it before the workflow's validation does.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

final class ArchiveCapabilities {
    public const PRODUCE = 'pausatf_archive_produce';
    public const VERIFY = 'pausatf_archive_verify';
    public const PUBLISH = 'pausatf_archive_publish';
    /** Meta capability: verify one release; args: [release id]. */
    public const VERIFY_RELEASE = 'pausatf_archive_verify_release';
    public const ROLE_PRODUCER = 'pausatf_archive_producer';
    public const ROLE_VERIFIER = 'pausatf_archive_verifier';
    public const VERSION = 1;
    private const OPTION = 'pausatf_archive_caps_version';

    public static function register(): void {
        add_filter('map_meta_cap', [self::class, 'filter_meta_cap'], 10, 4);
        // The plugin registers its components during `init`; a callback added to the running hook at the same
        // priority would never run, so install immediately in that case.
        if (did_action('init')) { self::maybe_install(); } else { add_action('init', [self::class, 'maybe_install']); }
    }

    /** Idempotent: adds roles and grants administrators every archive capability. */
    public static function maybe_install(): void {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) { return; }
        add_role(self::ROLE_PRODUCER, __('Archive producer', 'pausatf-results'), ['read' => true, self::PRODUCE => true]);
        add_role(self::ROLE_VERIFIER, __('Archive verifier', 'pausatf-results'), ['read' => true, self::VERIFY => true]);
        $admin = get_role('administrator');
        if ($admin !== null) {
            foreach ([self::PRODUCE, self::VERIFY, self::PUBLISH] as $cap) { $admin->add_cap($cap); }
        }
        update_option(self::OPTION, self::VERSION, false);
    }

    /**
     * @param array<int,string> $caps
     * @param array<int,mixed> $args
     * @return array<int,string>
     */
    public static function filter_meta_cap(array $caps, string $cap, int $user_id, array $args): array {
        if ($cap !== self::VERIFY_RELEASE) { return $caps; }
        $release = isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : 0;
        return self::verify_release_caps($user_id, $release, static fn(int $id): ?int => self::producer_of($id));
    }

    /**
     * Pure mapping, unit-tested: the producer of a release can never verify it; anyone else
     * needs the verify capability. An unknown release is never verifiable.
     *
     * @param callable(int): ?int $producer_of
     * @return list<string>
     */
    public static function verify_release_caps(int $user_id, int $release, callable $producer_of): array {
        if ($user_id < 1 || $release < 1) { return ['do_not_allow']; }
        $producer = $producer_of($release);
        if ($producer === null || $producer === $user_id) { return ['do_not_allow']; }
        return [self::VERIFY];
    }

    private static function producer_of(int $release): ?int {
        try {
            $row = ArchiveModule::workflow()->inspect($release);
        } catch (\Throwable) {
            return null;
        }
        return $row === null ? null : (int) $row['producer_user_id'];
    }
}
