<?php
/**
 * Import the existing 2026 MUT and XC schedule sources into pausatf_event.
 *
 * Usage: wp --require=tools/import-2026-calendar-events.php pausatf import-2026-calendars
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('pausatf import-2026-calendars', static function (): void {
        $service = new \PAUSATF\Results\CanonicalEventService();
        $records = array_merge(pausatf_calendar_mut_records(), pausatf_calendar_xc_records());
        $imported = 0;
        $failed = 0;

        foreach ($records as $record) {
            $result = $service->import($record, true);
            if (is_wp_error($result)) {
                $failed++;
                WP_CLI::warning($record['name'] . ': ' . $result->get_error_message());
                continue;
            }
            $imported++;
            WP_CLI::log($result['event_uid'] . ' → #' . $result['event_id']);
        }

        WP_CLI::success(sprintf('Imported %d calendar events; %d failed.', $imported, $failed));
    });
}

function pausatf_calendar_mut_records(): array {
    $html = do_shortcode('[table id=213 /]');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $records = [];

    foreach ($xpath->query('//table//tbody/tr') as $row) {
        $cells = $xpath->query('./td', $row);
        if ($cells->length < 8) {
            continue;
        }
        $date_text = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
        if (!preg_match('/^([A-Za-z]+\.?)\s+(\d+)/', $date_text, $parts)) {
            continue;
        }
        $date = date_create($parts[1] . ' ' . $parts[2] . ' 2026');
        if (!$date) {
            continue;
        }
        $link = $xpath->query('.//a[@href]', $cells->item(2))->item(0);
        $name = trim(preg_replace('/\s+/', ' ', $cells->item(2)->textContent));
        $series = trim(preg_replace('/\s+/', ' ', $cells->item(4)->textContent));
        $series_key = stripos($series, 'short') !== false ? 'Short' : (stripos($series, 'long') !== false ? 'Long' : 'Other');
        $records[] = [
            'event_uid' => '2026-mut-' . sanitize_title($name),
            'name' => $name,
            'date' => $date->format('Y-m-d'),
            'startDate' => $date->format('Y-m-d') . 'T09:00:00-07:00',
            'discipline' => 'Mountain, Ultra, and Trail',
            'event_type' => 'Mountain/Ultra/Trail',
            'series' => $series_key,
            'season' => 2026,
            'location_text' => trim(preg_replace('/\s+/', ' ', $cells->item(3)->textContent)),
            'organizer' => trim(preg_replace('/\s+/', ' ', $cells->item(7)->textContent)),
            'registration_url' => $link ? esc_url_raw($link->getAttribute('href')) : '',
            'source' => ['url' => 'https://stage.pausatf.org/mut-running/usatf-pacific-mut-grand-prix-schedule/', 'site' => 'USATF Pacific'],
            'review_status' => 'reviewed',
            'description' => '2026 USATF Pacific Mountain, Ultra, and Trail Grand Prix race. Series scoring: ' . $series . '. Short-series scoring: ' . trim(preg_replace('/\s+/', ' ', $cells->item(5)->textContent)) . '. Long-series scoring: ' . trim(preg_replace('/\s+/', ' ', $cells->item(6)->textContent)) . '.',
        ];
    }
    return $records;
}

function pausatf_calendar_xc_records(): array {
    $source_url = 'https://www.pausatf.org/wp-content/uploads/2026/06/2026-detailed-xc-schedule.htm';
    $response = wp_remote_get($source_url, ['timeout' => 30, 'sslverify' => true]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return [];
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . wp_remote_retrieve_body($response));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        return [];
    }
    $rows = $xpath->query('.//tr', $table);
    $headers = $xpath->query('./th|./td', $rows->item(1));
    $events = [];
    for ($i = 1; $i < $headers->length; $i++) {
        $events[$i] = ['name' => trim(preg_replace('/\s+/', ' ', $headers->item($i)->textContent)), 'details' => []];
    }
    for ($r = 2; $r < $rows->length; $r++) {
        $cells = $xpath->query('./th|./td', $rows->item($r));
        if ($cells->length < 2) {
            continue;
        }
        $label = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
        for ($i = 1; $i < $cells->length; $i++) {
            if (isset($events[$i])) {
                $value = trim(preg_replace('/\s+/', ' ', $cells->item($i)->textContent));
                if ($value !== '' && $value !== '&nbsp;') {
                    $events[$i]['details'][] = [$label, $value];
                }
            }
        }
    }

    $records = [];
    foreach ($events as $event) {
        $details = [];
        $date = '';
        foreach ($event['details'] as [$label, $value]) {
            $details[$label] = $value;
            if ($label === 'Race Date' && preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $value, $parts)) {
                $year = (int) $parts[3] < 100 ? 2000 + (int) $parts[3] : (int) $parts[3];
                $parsed = date_create(sprintf('%d-%02d-%02d', $year, $parts[1], $parts[2]));
                $date = $parsed ? $parsed->format('Y-m-d') : '';
            }
        }
        if ($date === '' || $event['name'] === '') {
            continue;
        }
        $records[] = [
            'event_uid' => '2026-xc-' . sanitize_title($event['name']),
            'name' => $event['name'],
            'date' => $date,
            'startDate' => $date . 'T09:00:00-07:00',
            'discipline' => 'Cross Country',
            'event_type' => 'Cross Country',
            'series' => 'Cross Country Grand Prix',
            'season' => 2026,
            'location_text' => $details['Location'] ?? '',
            'registration_url' => $details['Online Registration'] ?? '',
            'source' => ['url' => $source_url, 'site' => 'USATF Pacific'],
            'review_status' => 'reviewed',
            'description' => wp_json_encode($details),
        ];
    }
    return $records;
}
