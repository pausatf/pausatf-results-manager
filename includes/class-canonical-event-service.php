<?php
/**
 * Canonical event import service.
 *
 * Accepts the normalized records produced by the external extraction pipeline
 * and projects them into the existing event and results data model.
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

class CanonicalEventService {
    /** Import one canonical event record and its optional result rows. */
    public function import(array $record, bool $publish = false): array|\WP_Error {
        $uid = sanitize_text_field((string) ($record['event_uid'] ?? $record['id'] ?? ''));
        $name = sanitize_text_field((string) ($record['name'] ?? ''));
        if ($uid === '' || $name === '') {
            return new \WP_Error('invalid_event', 'A canonical event requires event_uid and name.');
        }

        $event_id = $this->find_event($uid);
        $meta = $this->event_meta($record, $uid);
        $post = [
            'post_type' => 'pausatf_event',
            'post_title' => $name,
            'post_content' => wp_kses_post((string) ($record['description'] ?? '')),
            'post_status' => $publish ? 'publish' : 'draft',
            'meta_input' => $meta,
        ];

        if ($event_id) {
            $post['ID'] = $event_id;
        }
        $event_id = $event_id ? wp_update_post($post, true) : wp_insert_post($post, true);
        if (is_wp_error($event_id)) {
            return $event_id;
        }

        $this->set_taxonomies((int) $event_id, $record);

        $result_count = 0;
        if (isset($record['results']) && is_array($record['results'])) {
            $repository = new Storage\ResultsRepository();
            $storage = $repository->store_results((int) $event_id, $this->normalize_results($record['results']));
            $result_count = $storage->inserted_records;
        }

        update_post_meta($event_id, '_pausatf_result_count', $result_count);
        update_post_meta($event_id, '_pausatf_last_synced_at', current_time('mysql', true));

        return [
            'event_id' => (int) $event_id,
            'event_uid' => $uid,
            'results_imported' => $result_count,
            'status' => get_post_status($event_id),
        ];
    }

    private function find_event(string $uid): ?int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_pausatf_event_uid' AND meta_value = %s LIMIT 1",
            $uid
        ));
        return $id ? (int) $id : null;
    }

    private function event_meta(array $record, string $uid): array {
        $location = is_array($record['location'] ?? null) ? $record['location'] : [];
        $address = is_array($location['address'] ?? null) ? $location['address'] : [];
        $source = is_array($record['source'] ?? null) ? $record['source'] : [];
        $fields = [
            '_pausatf_event_uid' => $uid,
            '_pausatf_start_datetime' => $record['startDate'] ?? $record['start_datetime'] ?? '',
            '_pausatf_end_datetime' => $record['endDate'] ?? $record['end_datetime'] ?? '',
            '_pausatf_event_status' => $record['eventStatus'] ?? $record['event_status'] ?? 'scheduled',
            '_pausatf_attendance_mode' => $record['eventAttendanceMode'] ?? $record['attendance_mode'] ?? 'offline',
            '_pausatf_venue_name' => $location['name'] ?? $record['venue_name'] ?? '',
            '_pausatf_event_location' => $location['name'] ?? $record['location_text'] ?? '',
            '_pausatf_address_locality' => $address['addressLocality'] ?? $address['locality'] ?? '',
            '_pausatf_address_region' => $address['addressRegion'] ?? $address['region'] ?? '',
            '_pausatf_postal_code' => $address['postalCode'] ?? $address['postal_code'] ?? '',
            '_pausatf_country' => $address['addressCountry'] ?? $address['country'] ?? 'US',
            '_pausatf_map_url' => $location['hasMap'] ?? $record['map_url'] ?? '',
            '_pausatf_registration_url' => $record['registration_url'] ?? '',
            '_pausatf_registration_status' => $record['registration_status'] ?? '',
            '_pausatf_organizer_name' => is_array($record['organizer'] ?? null) ? ($record['organizer']['name'] ?? '') : ($record['organizer'] ?? ''),
            '_pausatf_organizer_url' => is_array($record['organizer'] ?? null) ? ($record['organizer']['url'] ?? '') : '',
            '_pausatf_source_url' => $source['url'] ?? $record['source_url'] ?? '',
            '_pausatf_source_site' => $source['site'] ?? $record['source_site'] ?? '',
            '_pausatf_source_hash' => $record['source_hash'] ?? md5(wp_json_encode($record)),
            '_pausatf_import_confidence' => (float) ($record['import_confidence'] ?? 1),
            '_pausatf_review_status' => $record['review_status'] ?? 'needs_review',
        ];

        if (!empty($record['date'])) {
            $fields['_pausatf_event_date'] = sanitize_text_field($record['date']);
        } elseif (!empty($fields['_pausatf_start_datetime'])) {
            $fields['_pausatf_event_date'] = gmdate('Y-m-d', strtotime((string) $fields['_pausatf_start_datetime']));
        }

        return array_map(static fn($value) => is_scalar($value) ? sanitize_text_field((string) $value) : '', $fields);
    }

    private function set_taxonomies(int $event_id, array $record): void {
        $taxonomy_map = [
            'pausatf_event_type' => $record['event_type'] ?? $record['eventType'] ?? null,
            'pausatf_discipline' => $record['discipline'] ?? null,
            'pausatf_series' => $record['series'] ?? null,
        ];
        foreach ($taxonomy_map as $taxonomy => $value) {
            if ($value !== null && $value !== '') {
                wp_set_object_terms($event_id, is_array($value) ? $value : [$value], $taxonomy);
            }
        }

        if (!empty($record['season'])) {
            wp_set_object_terms($event_id, (string) $record['season'], 'pausatf_season');
        } elseif (!empty($record['date'])) {
            wp_set_object_terms($event_id, gmdate('Y', strtotime((string) $record['date'])), 'pausatf_season');
        }
        if (!empty($record['divisions']) && is_array($record['divisions'])) {
            wp_set_object_terms($event_id, $record['divisions'], 'pausatf_division');
        }
    }

    /** Convert JSON-LD-friendly athlete objects into the results table contract. */
    private function normalize_results(array $results): array {
        return array_map(static function (array $result): array {
            $athlete = is_array($result['athlete'] ?? null) ? $result['athlete'] : [];
            $normalized = $result;
            $normalized['athlete_name'] = $result['athlete_name'] ?? $athlete['name'] ?? '';
            $normalized['athlete_uid'] = $result['athlete_uid'] ?? $athlete['uid'] ?? $athlete['@id'] ?? '';
            $normalized['athlete_source_id'] = $result['athlete_source_id'] ?? $athlete['source_id'] ?? '';
            return $normalized;
        }, $results);
    }
}
