<?php
/**
 * Decides whether PAUSATF Site's Archive owns the results archive.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

final class ArchiveOwnership {
    private const SITE_MODULE = 'PAUSATF\\SEO\\Archive\\Module';
    private const SITE_MODULES = 'PAUSATF\\SEO\\Modules';

    /**
     * Whether PAUSATF Site's Archive module is active.
     *
     * Call at init or later: PAUSATF Site registers its hooks on plugins_loaded,
     * and its classes are only autoloadable once that plugin has loaded. The
     * pausatf_results_defer_archive_to_site filter can force either answer.
     */
    public static function site_provides(): bool {
        $active = class_exists(self::SITE_MODULE) && function_exists('pausatf_archive_register_hooks');
        if ($active && class_exists(self::SITE_MODULES)) {
            $active = (bool) call_user_func([self::SITE_MODULES, 'enabled'], 'archive');
        }
        return (bool) apply_filters('pausatf_results_defer_archive_to_site', $active);
    }
}
