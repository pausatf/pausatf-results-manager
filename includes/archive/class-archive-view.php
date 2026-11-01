<?php
/**
 * Read-only display of the published archive projection.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

final class ArchiveView {
    public static function render(array $release, int $page = 1): string {
        $entities = $release['entities'];
        $performances = $entities['performances'];
        $names = array_column($performances, 'athlete_name_raw', 'key');
        $events = array_column($entities['competitions'], null, 'key');
        $divisions = array_column($entities['divisions'], null, 'key');
        $age_divisions = $divisions !== [];
        foreach ($divisions as $division) {
            if (!is_string($division['age_band'] ?? null) || trim($division['age_band']) === '') { $age_divisions = false; }
        }
        $division_place_heading = $age_divisions ? 'Age-division place' : 'Division place';
        $rounds = array_column($entities['rounds'], null, 'key');
        $title = count($events) === 1 ? ($entities['competitions'][0]['title'] ?? $release['competition_key']) : $release['competition_key'];
        $out = '<section class="pausatf-archive-context"><h2>' . self::escape($title) . '</h2>';
        $out .= '<p>' . self::escape(__('Source values and separately labelled calculations are retained. Unreported information remains unknown; extraction review does not ratify records.', 'pausatf-results')) . '</p>';
        if ($release['source_url'] !== null) {
            $out .= '<p><a rel="noopener noreferrer" href="' . esc_url($release['source_url']) . '">' . self::escape(__('Original results', 'pausatf-results')) . '</a></p>';
        }
        $standings = $entities['series_standings'] ?? [];
        $association_records = $entities['association_records'] ?? [];
        // Standings or records lists without race results do not need an empty results table.
        $show_performances = $performances !== [] || ($standings === [] && $association_records === []);
        $visible = array_slice($performances, ($page - 1) * 50, 50);
        $visible_keys = array_column($visible, 'key');
        $published_places = [];
        foreach ($entities['rankings'] as $ranking) {
            if (($ranking['origin'] ?? 'published') !== 'published') { continue; }
            $category = match (true) {
                $ranking['scope'] === 'overall' => 'overall_place',
                str_starts_with($ranking['scope'], 'sex:') => 'sex_place',
                str_starts_with($ranking['scope'], 'division:') => 'division_place',
                default => null,
            };
            if ($category !== null) { $published_places[$ranking['performance_key']][$category][] = $ranking['published_place']; }
        }
        foreach ($visible as &$performance) {
            $event = $events[$performance['competition_key'] ?? ''] ?? (count($events) === 1 ? reset($events) : []);
            $performance['event_label'] = $event['title'] ?? $event['key'] ?? null;
            $performance['division_label'] = $divisions[$performance['division_key'] ?? '']['published_label'] ?? null;
            $round = $rounds[$performance['round_key'] ?? ''] ?? [];
            $performance['round_label'] = isset($round['round_kind']) ? $round['round_kind'] . (isset($round['heat']) ? ' / ' . $round['heat'] : '') : null;
            foreach (['overall_place', 'sex_place', 'division_place'] as $category) {
                $places = $published_places[$performance['key']][$category] ?? [];
                // Several source scopes must remain separate in the detailed table.
                $performance[$category] = match (count($places)) {
                    0 => null,
                    1 => $places[0],
                    default => __('See place details', 'pausatf-results'),
                };
            }
        }
        unset($performance);
        $visible_map = array_column($visible, null, 'key');
        $printed_lists = self::standings($standings) . self::association_records($association_records);
        if (!$show_performances) { return $out . $printed_lists . self::context($entities, $names, $events, $divisions) . '</section>'; }
        $out .= self::table(__('Performances', 'pausatf-results'), $visible, [
            'athlete_name_raw' => 'Name as recorded', 'raw_mark' => 'Mark',
            'overall_place' => 'Overall place', 'sex_place' => 'Sex-category place', 'division_place' => $division_place_heading,
            'event_label' => 'Event', 'division_label' => 'Division', 'round_label' => 'Round', 'status_raw' => 'Status',
            'qualifier_raw' => 'Qualifier', 'wind' => 'Wind (m/s)', 'gun_time_raw' => 'Gun time', 'net_time_raw' => 'Net time',
            'seed_mark_raw' => 'Seed mark', 'lane' => 'Lane', 'bib' => 'Bib',
        ]);
        if ($page > 1 || count($performances) > $page * 50) {
            $out .= '<nav aria-label="' . esc_attr__('Archive result pages', 'pausatf-results') . '">';
            if ($page > 1) { $out .= '<a href="' . esc_url(add_query_arg('archive_result_page', $page - 1)) . '">' . self::escape(__('Previous results', 'pausatf-results')) . '</a> '; }
            if (count($performances) > $page * 50) { $out .= '<a href="' . esc_url(add_query_arg('archive_result_page', $page + 1)) . '">' . self::escape(__('Next results', 'pausatf-results')) . '</a>'; }
            $out .= '</nav>';
        }
        $out .= '<p>' . self::escape(__('Overall place is across the reported field; sex-category place is within the source sex category; division place is within the printed division. Age divisions are identified only when the source provides age context. These are source places, not recalculated rankings.', 'pausatf-results')) . '</p>';
        foreach (['rankings' => ['published_place' => 'Place', 'scope_label' => 'Place category', 'origin_label' => 'Origin', 'calculation_version' => 'Calculation version', 'timing_basis' => 'Timing basis', 'age_grade_published' => 'Age grade', 'age_grade_table_version' => 'Age-grade table version'],
            'attempts' => ['attempt_order' => 'Attempt', 'height' => 'Height', 'mark_raw' => 'Mark', 'attempt_status_raw' => 'Attempt status'],
            'record_claims' => ['record_type_as_printed' => 'Record claim as printed', 'claimed_status' => 'Claimed status']] as $kind => $columns) {
            $rows = [];
            foreach ($entities[$kind] as $record) {
                if (in_array($record['performance_key'], $visible_keys, true)) {
                    if ($kind === 'rankings') {
                        $record['origin_label'] = ($record['origin'] ?? 'published') === 'computed' ? 'Computed' : 'Source';
                        $record['scope_label'] = match (true) {
                            $record['scope'] === 'overall' => __('Overall', 'pausatf-results'),
                            str_starts_with($record['scope'], 'sex:') => __('Sex category', 'pausatf-results') . ': ' . substr($record['scope'], 4),
                            str_starts_with($record['scope'], 'division:') => __('Division', 'pausatf-results') . ': ' . substr($record['scope'], 9),
                            default => $record['scope'],
                        };
                    }
                    $record['event_label'] = $visible_map[$record['performance_key']]['event_label'];
                    $record['name'] = $names[$record['performance_key']]; $rows[] = $record;
                }
            }
            if ($rows !== []) {
                if ($kind === 'rankings') { $out .= '<details><summary>' . self::escape(__('Place details and calculation provenance', 'pausatf-results')) . '</summary>'; }
                $out .= self::table(ucfirst(str_replace('_', ' ', $kind)), $rows, ['name' => 'Name as recorded'] + $columns + ['event_label' => 'Event']);
                if ($kind === 'rankings') { $out .= '</details>'; }
            }
        }
        return $out . $printed_lists . self::context($entities, $names, $events, $divisions) . '</section>';
    }

    /**
     * Expandable source context that has no dedicated result table.
     *
     * @param array<string, list<array<string,mixed>>> $entities
     * @param array<string,mixed> $names
     * @param array<string, array<string,mixed>> $events
     * @param array<string, array<string,mixed>> $divisions
     */
    private static function context(array $entities, array $names, array $events, array $divisions): string {
        $out = '';
        foreach (['competitions', 'divisions', 'rounds', 'rulesets', 'teams', 'scores', 'result_releases'] as $kind) {
            if ($entities[$kind] === []) { continue; }
            $out .= '<details><summary>' . self::escape(ucfirst(str_replace('_', ' ', $kind))) . '</summary>';
            foreach ($entities[$kind] as $record) {
                $out .= '<dl>';
                foreach ($record as $field => $value) {
                    if ($field === 'key' || $field === 'source_locator') { continue; }
                    if (in_array($field, ['member_keys', 'scoring_member_keys', 'displacer_keys'], true) && is_array($value)) {
                        $value = array_map(static fn(string $key): string => $names[$key], $value);
                    }
                    if ($field === 'competition_key' && is_string($value)) { $value = $events[$value]['title'] ?? $value; }
                    if ($field === 'division_key' && is_string($value)) { $value = $divisions[$value]['published_label'] ?? $value; }
                    $out .= '<dt>' . self::escape(ucfirst(str_replace('_', ' ', $field))) . '</dt><dd>' . self::escape($value) . '</dd>';
                }
                $out .= '</dl>';
            }
            $out .= '</details>';
        }
        return $out;
    }

    /**
     * Printed series standings, one table per series, kind and division in first-seen order.
     *
     * @param list<array<string,mixed>> $standings
     */
    private static function standings(array $standings): string {
        $groups = [];
        foreach ($standings as $standing) {
            $series = $standing['series_label_as_printed'] ?? null;
            $kind = $standing['standings_kind'];
            $division = $standing['division_label_as_printed'];
            $groups[json_encode([$series, $kind, $division], JSON_THROW_ON_ERROR)][] = $standing;
        }
        $out = '';
        foreach ($groups as $records) {
            $first = $records[0];
            $caption = implode(' — ', array_filter([
                $first['series_label_as_printed'] ?? null,
                $first['division_label_as_printed'],
                $first['standings_kind'] === 'team' ? __('Team standings', 'pausatf-results') : __('Individual standings', 'pausatf-results'),
            ], static fn(mixed $part): bool => is_string($part) && $part !== ''));
            // Race columns follow the first printed appearance of each label within the table.
            $races = [];
            foreach ($records as $record) {
                foreach ((array) ($record['race_points_as_printed'] ?? []) as $label => $points) {
                    $races[(string) $label] ??= 'race_' . count($races);
                }
            }
            $rows = [];
            foreach ($records as $record) {
                $row = [
                    'place' => $record['place_as_printed'] ?? null, 'name' => $record['name_as_printed'],
                    'club' => $record['club_as_printed'] ?? null, 'total' => $record['total_as_printed'] ?? null,
                ];
                foreach ((array) ($record['race_points_as_printed'] ?? []) as $label => $points) { $row[$races[(string) $label]] = $points; }
                $rows[] = $row;
            }
            $columns = ['place' => __('Place', 'pausatf-results'), 'name' => __('Name as printed', 'pausatf-results'), 'club' => __('Club', 'pausatf-results')];
            foreach ($races as $label => $field) { $columns[$field] = $label; }
            $out .= self::table($caption, $rows, $columns + ['total' => __('Total as printed', 'pausatf-results')]);
        }
        if ($out !== '') {
            $out = '<h3>' . self::escape(__('Series standings', 'pausatf-results')) . '</h3>'
                . '<p>' . self::escape(__('Places, points and totals are shown as printed; they are not recalculated.', 'pausatf-results')) . '</p>' . $out;
        }
        return $out;
    }

    /**
     * Association records lists, one table per list and division in first-seen order.
     *
     * @param list<array<string,mixed>> $records
     */
    private static function association_records(array $records): string {
        $groups = [];
        foreach ($records as $record) {
            $list = $record['list_label_as_printed'] ?? null;
            $groups[json_encode([$list, $record['division_as_printed']], JSON_THROW_ON_ERROR)][] = $record;
        }
        $out = '';
        foreach ($groups as $rows) {
            $first = $rows[0];
            $caption = implode(' — ', array_filter([$first['list_label_as_printed'] ?? null, $first['division_as_printed']],
                static fn(mixed $part): bool => is_string($part) && $part !== ''));
            foreach ($rows as &$row) {
                $row['date_label'] = $row['date_as_printed'] ?? $row['date_set'] ?? null;
            }
            unset($row);
            $out .= self::table($caption, $rows, [
                'event_as_printed' => __('Event', 'pausatf-results'), 'mark_as_printed' => __('Mark', 'pausatf-results'),
                'holder_name_as_printed' => __('Holder as printed', 'pausatf-results'), 'club_as_printed' => __('Club', 'pausatf-results'),
                'date_label' => __('Date', 'pausatf-results'), 'meet_as_printed' => __('Meet', 'pausatf-results'),
                'record_status_as_listed' => __('Status as listed', 'pausatf-results'),
            ]);
        }
        if ($out !== '') {
            $out = '<h3>' . self::escape(__('Records lists', 'pausatf-results')) . '</h3>'
                . '<p>' . self::escape(__('Records are shown as listed by the source. Listing here does not ratify a record.', 'pausatf-results')) . '</p>' . $out;
        }
        return $out;
    }

    private static function table(string $caption, array $records, array $columns): string {
        $out = '<div class="pausatf-archive-table" role="region" tabindex="0" aria-label="' . esc_attr($caption) . '"><table><caption>' . self::escape($caption) . '</caption><thead><tr>';
        foreach ($columns as $label) { $out .= '<th scope="col">' . self::escape($label) . '</th>'; }
        $out .= '</tr></thead><tbody>';
        foreach ($records as $record) {
            $out .= '<tr>';
            foreach ($columns as $field => $label) { $out .= '<td>' . self::escape($record[$field] ?? null) . '</td>'; }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private static function escape(mixed $value): string {
        if ($value === null) { $value = __('Not reported', 'pausatf-results'); }
        if (is_array($value)) { $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
