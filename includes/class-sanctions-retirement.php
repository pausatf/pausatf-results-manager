<?php
/**
 * What is left of the retired Sanctions Manager.
 *
 * USATF grants sanctions itself: applications are completed online at
 * usatf.org (2026 USATF Governance Handbook, Regulation 23-C-1). The manager
 * that duplicated that workflow is gone. Its tables (pausatf_sanctions,
 * pausatf_sanction_coi, pausatf_sanction_reports, pausatf_sanction_history),
 * its options and the capabilities it added to roles are deliberately left in
 * place; docs/sanctions-retirement.md explains how to export or remove them.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class SanctionsRetirement {
    /** Shortcodes the manager registered; pages that still contain them get a notice instead of raw text. */
    public const SHORTCODES = ['pausatf_sanction_form', 'pausatf_my_sanctions', 'pausatf_sanctioned_events'];

    /** Daily reminder job the manager scheduled. */
    public const CRON_HOOK = 'pausatf_sanction_reminders';

    /** PA's sanctions page, which links to USATF's online application. */
    public const DEFAULT_URL = 'https://www.pausatf.org/formssanctions/forms-sanctions-links-and-information/';

    public static function register(): void {
        foreach (self::SHORTCODES as $tag) {
            add_shortcode($tag, [self::class, 'render_notice']);
        }
        add_action('init', [self::class, 'unschedule_reminders']);
    }

    /**
     * The reminder job would otherwise keep firing a hook nothing listens to.
     * Clearing a schedule removes no sanction data.
     */
    public static function unschedule_reminders(): void {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /** @param array<string, string>|string $atts */
    public static function render_notice($atts = []): string {
        $url = (string) apply_filters('pausatf_results_sanctions_url', self::DEFAULT_URL);
        return '<p class="pausatf-sanctions-retired">'
            . esc_html__('Event sanctions are applied for and granted through USA Track & Field.', 'pausatf-results')
            . ' <a href="' . esc_url($url) . '">' . esc_html__('Sanction information and links', 'pausatf-results') . '</a></p>';
    }
}
