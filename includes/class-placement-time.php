<?php
/**
 * Which time an event places and awards by: gun, net, or a stated combination.
 *
 * 2026 USATF Competition Rules, Rule 165.16 (p. 72-76): in Masters LDR championships and
 * in LDR non-championship races held partly or entirely outside the stadium, the Games
 * Committee may use net times, gun times or a combination for times, places and awards,
 * provided the entry materials say which. Net time is the finish transponder time minus
 * the start transponder time. The rules set no default for an event that states nothing;
 * RM places such an event by gun time, the time every race records.
 *
 * Official times outside the stadium are converted to the next longer whole second
 * (Rule 165.11(c)); hand times are rounded up the same way (Rule 165.7).
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class PlacementTime {
    /** Event meta holding the basis the entry materials state: gun, net or combination. */
    public const META = '_pausatf_placement_time';

    public const GUN = 'gun';
    public const NET = 'net';
    public const COMBINATION = 'combination';

    /**
     * The event's stated basis. Filter `pausatf_results_placement_time` (basis, event id)
     * can supply it, for example from a series setting. Anything unrecognised is gun time.
     */
    public static function basis(int $event_id): string {
        $stored = $event_id > 0 ? (string) get_post_meta($event_id, self::META, true) : '';
        $basis = apply_filters('pausatf_results_placement_time', $stored, $event_id);
        $basis = is_string($basis) ? strtolower(trim($basis)) : '';
        return in_array($basis, [self::GUN, self::NET, self::COMBINATION], true) ? $basis : self::GUN;
    }

    /**
     * Pick the time used for places and awards.
     *
     * The rules do not define a combination; the event's entry materials do. The filter
     * `pausatf_results_placement_time_combination` (basis, context) resolves it to gun or net
     * for one result; without it, gun time is used.
     *
     * When the chosen time is missing the other one is used and the returned basis says so,
     * so the displayed time matches the stored one. Rule 165.16 leaves a missing start or
     * finish time to the Referee; this only keeps the provider's other time on record.
     *
     * @param array<string, mixed> $context Provider fields for the combination filter.
     * @return array{0: ?float, 1: ?string} Raw seconds and the basis actually used.
     */
    public static function choose(?float $gun, ?float $net, string $basis, array $context = []): array {
        if ($basis === self::COMBINATION) {
            $resolved = apply_filters('pausatf_results_placement_time_combination', self::GUN, $context);
            $basis = $resolved === self::NET ? self::NET : self::GUN;
        }
        $gun = self::valid($gun);
        $net = self::valid($net);
        if ($basis === self::NET) {
            return $net !== null ? [$net, self::NET] : [$gun, $gun !== null ? self::GUN : null];
        }
        return $gun !== null ? [$gun, self::GUN] : [$net, $net !== null ? self::NET : null];
    }

    /**
     * The official time: the raw time converted to the next longer whole second
     * (Rule 165.11(c)). Places are ranked on raw times, so equal raw times tie and
     * times that round to the same second do not.
     */
    public static function official_seconds(?float $seconds): ?int {
        $seconds = self::valid($seconds);
        if ($seconds === null) {
            return null;
        }
        // Absorb float noise such as 1800.0000000002 from millisecond conversion.
        return (int) ceil(round($seconds, 6));
    }

    private static function valid(?float $seconds): ?float {
        return $seconds !== null && is_finite($seconds) && $seconds > 0 ? $seconds : null;
    }
}
