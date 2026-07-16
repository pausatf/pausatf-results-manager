<?php
/** Emit event structured data from canonical event metadata. */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

final class EventJsonLd {
    public function __construct() {
        add_action('wp_head', [$this, 'render'], 20);
    }

    public function render(): void {
        if (!is_singular('pausatf_event')) {
            return;
        }

        $event_id = get_queried_object_id();
        $start = (string) get_post_meta($event_id, '_pausatf_start_datetime', true);
        $date = (string) get_post_meta($event_id, '_pausatf_event_date', true);
        $location_name = (string) get_post_meta($event_id, '_pausatf_venue_name', true);
        if ($location_name === '') {
            $location_name = (string) get_post_meta($event_id, '_pausatf_event_location', true);
        }

        $status = strtolower((string) (get_post_meta($event_id, '_pausatf_event_status', true) ?: 'scheduled'));
        $status_map = [
            'scheduled' => 'EventScheduled',
            'postponed' => 'EventPostponed',
            'cancelled' => 'EventCancelled',
            'canceled' => 'EventCancelled',
            'rescheduled' => 'EventRescheduled',
        ];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'SportsEvent',
            '@id' => get_permalink($event_id) . '#event',
            'name' => get_the_title($event_id),
            'url' => get_permalink($event_id),
            'eventStatus' => 'https://schema.org/' . ($status_map[$status] ?? 'EventScheduled'),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'sport' => 'Track and field',
        ];

        if ($start !== '') {
            $data['startDate'] = $start;
        } elseif ($date !== '') {
            $data['startDate'] = $date;
        }
        $end = get_post_meta($event_id, '_pausatf_end_datetime', true);
        if ($end !== '') {
            $data['endDate'] = $end;
        }
        if ($location_name !== '') {
            $data['location'] = [
                '@type' => 'Place',
                'name' => $location_name,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => get_post_meta($event_id, '_pausatf_address_locality', true),
                    'addressRegion' => get_post_meta($event_id, '_pausatf_address_region', true),
                    'postalCode' => get_post_meta($event_id, '_pausatf_postal_code', true),
                    'addressCountry' => get_post_meta($event_id, '_pausatf_country', true),
                ]),
            ];
        }

        $organizer = get_post_meta($event_id, '_pausatf_organizer_name', true);
        if ($organizer !== '') {
            $data['organizer'] = [
                '@type' => 'Organization',
                'name' => $organizer,
                'url' => get_post_meta($event_id, '_pausatf_organizer_url', true) ?: home_url('/'),
            ];
        }

        $registration = get_post_meta($event_id, '_pausatf_registration_url', true);
        if ($registration !== '') {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => $registration,
                'availability' => 'https://schema.org/InStock',
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}

new EventJsonLd();
