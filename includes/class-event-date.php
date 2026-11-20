<?php
/**
 * Competition date of a pausatf_event.
 *
 * PAUSATF Site's Events module owns pausatf_event and stores its start in
 * _pausatf_event_start as a wall-clock 'Y-m-d H:i:s' in the event's own
 * timezone (_pausatf_event_timezone), keeping _pausatf_event_date only as a
 * legacy fallback. Events Results Manager imported before that module carry
 * only _pausatf_event_date. Every date read goes through this class so both
 * kinds of event are found.
 *
 * @package PAUSATF\Results
 */

declare(strict_types=1);

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class EventDate {
    public const START_META = '_pausatf_event_start';
    public const LEGACY_META = '_pausatf_event_date';
    public const TIMEZONE_META = '_pausatf_event_timezone';

    /** WP_Query var that orders events by competition date ('ASC' or 'DESC'). */
    public const ORDER_QUERY_VAR = 'pausatf_event_date_order';

    private const JOIN_ALIAS = 'pausatf_event_day';

    public static function register(): void {
        add_filter('posts_clauses', [self::class, 'posts_clauses'], 10, 2);
    }

    /**
     * Competition date of an event as stored: 'Y-m-d' from the start meta, or the
     * legacy date meta unchanged; '' when neither is set.
     */
    public static function get(int $event_id): string {
        return self::from_meta(
            (string) get_post_meta($event_id, self::START_META, true),
            (string) get_post_meta($event_id, self::LEGACY_META, true),
            (string) get_post_meta($event_id, self::TIMEZONE_META, true)
        );
    }

    /**
     * The start meta is local time in the event's timezone, so its date part is
     * already the competition day and must not be shifted to the site or UTC.
     * Only a start carrying an explicit offset ("2026-03-07T08:00:00Z") is
     * converted, into the event timezone when it is a valid identifier.
     */
    public static function from_meta(string $start, string $legacy, string $timezone = ''): string {
        $start = trim($start);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/', $start, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $start) === 1) {
                try {
                    $instant = new \DateTimeImmutable($start);
                    $zone = in_array($timezone, timezone_identifiers_list(), true) ? new \DateTimeZone($timezone) : null;
                    return ($zone === null ? $instant : $instant->setTimezone($zone))->format('Y-m-d');
                } catch (\Exception $e) {
                    unset($e);
                }
            }
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return trim($legacy);
    }

    /**
     * LEFT JOINs that expose both date keys for the event ID in $event_column,
     * as tables "{$alias}_start" and "{$alias}_legacy". Pair with sql_expr().
     */
    public static function sql_join(string $event_column, string $alias): string {
        global $wpdb;
        return "LEFT JOIN {$wpdb->postmeta} {$alias}_start ON {$alias}_start.post_id = {$event_column} AND {$alias}_start.meta_key = '" . self::START_META . "'"
            . " LEFT JOIN {$wpdb->postmeta} {$alias}_legacy ON {$alias}_legacy.post_id = {$event_column} AND {$alias}_legacy.meta_key = '" . self::LEGACY_META . "'";
    }

    /** SQL expression for the competition date joined by sql_join() under $alias. */
    public static function sql_expr(string $alias): string {
        return "COALESCE(NULLIF(LEFT({$alias}_start.meta_value, 10), ''), {$alias}_legacy.meta_value)";
    }

    /**
     * Query args ordering pausatf_event posts by competition date. get_posts()
     * suppresses filters by default, so this turns them back on for the query.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function order_query(array $args, string $order = 'DESC'): array {
        unset($args['meta_key'], $args['orderby'], $args['order']);
        $args['suppress_filters'] = false;
        $args[self::ORDER_QUERY_VAR] = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        return $args;
    }

    /**
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    public static function posts_clauses(array $clauses, object $query): array {
        $order = method_exists($query, 'get') ? $query->get(self::ORDER_QUERY_VAR) : '';
        if ($order !== 'ASC' && $order !== 'DESC') {
            return $clauses;
        }
        global $wpdb;
        $clauses['join'] = ($clauses['join'] ?? '') . ' ' . self::sql_join("{$wpdb->posts}.ID", self::JOIN_ALIAS);
        $clauses['orderby'] = self::sql_expr(self::JOIN_ALIAS) . " {$order}, {$wpdb->posts}.ID {$order}";
        return $clauses;
    }
}
