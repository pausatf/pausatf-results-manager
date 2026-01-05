<?php
/**
 * Hy-Tek Meet Manager Importer
 *
 * Imports results from Hy-Tek Meet Manager exports (HY3, CL2, ZIP formats)
 *
 * @package PAUSATF\Results\Integrations
 */

namespace PAUSATF\Results\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports results from Hy-Tek Meet Manager
 */
class HyTekImporter {
    /**
     * Hy-Tek record types
     */
    private const RECORD_TYPES = [
        'A0' => 'file_description',
        'B1' => 'meet_info',
        'B2' => 'meet_host',
        'C1' => 'team_id',
        'C2' => 'team_entry',
        'D0' => 'individual_event',
        'D1' => 'individual_info',
        'D2' => 'individual_entry',
        'D3' => 'individual_result',
        'E0' => 'relay_event',
        'E1' => 'relay_entry',
        'E2' => 'relay_result',
        'F0' => 'splits',
    ];

    /**
     * Event codes mapping
     */
    private const EVENT_CODES = [
        '1' => '50 Free',
        '2' => '100 Free',
        '3' => '200 Free',
        '4' => '400 Free',
        '5' => '800 Free',
        '6' => '1500 Free',
        // Track events
        '101' => '100m',
        '102' => '200m',
        '103' => '400m',
        '104' => '800m',
        '105' => '1500m',
        '106' => '1 Mile',
        '107' => '3000m',
        '108' => '5000m',
        '109' => '10000m',
        // Field events
        '201' => 'Long Jump',
        '202' => 'Triple Jump',
        '203' => 'High Jump',
        '204' => 'Pole Vault',
        '205' => 'Shot Put',
        '206' => 'Discus',
        '207' => 'Javelin',
        '208' => 'Hammer',
    ];

    private array $meet_info = [];
    private array $teams = [];
    private array $athletes = [];
    private array $results = [];

    /**
     * Import from Hy-Tek file
     *
     * @param string $file_path Path to HY3/CL2 file
     * @param array  $options Import options
     * @return array Import result
     */
    public function import(string $file_path, array $options = []): array {
        if (!file_exists($file_path)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // Handle ZIP archives
        if ($extension === 'zip') {
            return $this->import_from_zip($file_path, $options);
        }

        // Read and parse file
        $content = file_get_contents($file_path);
        if ($content === false) {
            return ['success' => false, 'error' => 'Could not read file'];
        }

        return $this->parse_hytek_content($content, $options);
    }

    /**
     * Import from ZIP archive
     */
    private function import_from_zip(string $zip_path, array $options): array {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['success' => false, 'error' => 'Could not open ZIP file'];
        }

        $results = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, ['hy3', 'cl2', 'hyv'])) {
                $content = $zip->getFromIndex($i);
                $result = $this->parse_hytek_content($content, $options);
                $results[] = $result;
            }
        }

        $zip->close();

        $total_imported = array_sum(array_column($results, 'imported'));
        return [
            'success' => true,
            'files_processed' => count($results),
            'imported' => $total_imported,
        ];
    }

    /**
     * Parse Hy-Tek file content
     */
    private function parse_hytek_content(string $content, array $options): array {
        $this->reset();

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $this->parse_line($line);
        }

        // Create event if needed
        $event_id = $options['event_id'] ?? 0;
        if (!$event_id) {
            $event_id = $this->create_event_from_meet_info($options);
        }

        if (!$event_id) {
            return ['success' => false, 'error' => 'Could not create event'];
        }

        // Save results
        $imported = $this->save_results($event_id);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'meet_info' => $this->meet_info,
            'teams_found' => count($this->teams),
            'athletes_found' => count($this->athletes),
        ];
    }

    /**
     * Reset parser state
     */
    private function reset(): void {
        $this->meet_info = [];
        $this->teams = [];
        $this->athletes = [];
        $this->results = [];
    }

    /**
     * Parse a single Hy-Tek line
     */
    private function parse_line(string $line): void {
        $record_type = substr($line, 0, 2);

        switch ($record_type) {
            case 'B1':
                $this->parse_meet_info($line);
                break;
            case 'C1':
                $this->parse_team($line);
                break;
            case 'D1':
                $this->parse_athlete($line);
                break;
            case 'D3':
                $this->parse_result($line);
                break;
        }
    }

    /**
     * Parse meet info record (B1)
     */
    private function parse_meet_info(string $line): void {
        // B1 format: B1<meet_name><facility><start_date><end_date>...
        $this->meet_info = [
            'name' => $this->extract_field($line, 2, 30),
            'facility' => $this->extract_field($line, 32, 30),
            'city' => $this->extract_field($line, 62, 20),
            'state' => $this->extract_field($line, 82, 2),
            'start_date' => $this->parse_hytek_date($this->extract_field($line, 84, 8)),
            'end_date' => $this->parse_hytek_date($this->extract_field($line, 92, 8)),
        ];
    }

    /**
     * Parse team record (C1)
     */
    private function parse_team(string $line): void {
        $team_code = $this->extract_field($line, 2, 6);
        $team_name = $this->extract_field($line, 8, 30);

        $this->teams[$team_code] = [
            'code' => $team_code,
            'name' => $team_name,
            'short_name' => $this->extract_field($line, 38, 16),
        ];
    }

    /**
     * Parse athlete record (D1)
     */
    private function parse_athlete(string $line): void {
        $athlete_id = $this->extract_field($line, 2, 12);

        $this->athletes[$athlete_id] = [
            'id' => $athlete_id,
            'last_name' => $this->extract_field($line, 14, 20),
            'first_name' => $this->extract_field($line, 34, 20),
            'team_code' => $this->extract_field($line, 54, 6),
            'gender' => $this->extract_field($line, 60, 1),
            'birth_date' => $this->parse_hytek_date($this->extract_field($line, 61, 8)),
            'age' => (int) $this->extract_field($line, 69, 3),
        ];
    }

    /**
     * Parse result record (D3)
     */
    private function parse_result(string $line): void {
        $athlete_id = $this->extract_field($line, 2, 12);
        $event_code = $this->extract_field($line, 14, 4);

        $this->results[] = [
            'athlete_id' => $athlete_id,
            'event_code' => $event_code,
            'event_name' => self::EVENT_CODES[$event_code] ?? 'Unknown',
            'time' => $this->parse_hytek_time($this->extract_field($line, 18, 8)),
            'time_display' => $this->extract_field($line, 18, 8),
            'place' => (int) $this->extract_field($line, 26, 4),
            'heat' => (int) $this->extract_field($line, 30, 2),
            'lane' => (int) $this->extract_field($line, 32, 2),
            'points' => (float) $this->extract_field($line, 34, 6),
        ];
    }

    /**
     * Extract field from fixed-width line
     */
    private function extract_field(string $line, int $start, int $length): string {
        return trim(substr($line, $start, $length));
    }

    /**
     * Parse Hy-Tek date format (MMDDYYYY)
     */
    private function parse_hytek_date(string $date): ?string {
        if (strlen($date) !== 8) {
            return null;
        }

        $month = substr($date, 0, 2);
        $day = substr($date, 2, 2);
        $year = substr($date, 4, 4);

        return "{$year}-{$month}-{$day}";
    }

    /**
     * Parse Hy-Tek time format
     */
    private function parse_hytek_time(string $time): ?int {
        $time = trim($time);

        // Format: MM:SS.ss or HH:MM:SS.ss
        if (preg_match('/^(\d+):(\d{2})\.(\d{2})$/', $time, $m)) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        }

        return null;
    }

    /**
     * Create event post from meet info
     */
    private function create_event_from_meet_info(array $options): int {
        $title = $this->meet_info['name'] ?? $options['event_name'] ?? 'Imported Meet';

        $event_id = wp_insert_post([
            'post_type' => 'pausatf_event',
            'post_title' => $title,
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $this->meet_info['start_date'] ?? null,
                '_pausatf_event_location' => trim(($this->meet_info['city'] ?? '') . ', ' . ($this->meet_info['state'] ?? '')),
                '_pausatf_facility' => $this->meet_info['facility'] ?? '',
                '_pausatf_import_source' => 'hytek',
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ]);

        return is_wp_error($event_id) ? 0 : $event_id;
    }

    /**
     * Save parsed results to database
     */
    private function save_results(int $event_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $imported = 0;

        foreach ($this->results as $result) {
            $athlete = $this->athletes[$result['athlete_id']] ?? null;
            $team = $athlete ? ($this->teams[$athlete['team_code']] ?? null) : null;

            $data = [
                'event_id' => $event_id,
                'athlete_name' => $athlete ? trim($athlete['first_name'] . ' ' . $athlete['last_name']) : 'Unknown',
                'athlete_age' => $athlete['age'] ?? null,
                'place' => $result['place'],
                'time_seconds' => $result['time'],
                'time_display' => $result['time_display'],
                'points' => $result['points'] ?: null,
                'club' => $team['name'] ?? null,
                'division' => $this->determine_division($athlete),
                'raw_data' => json_encode($result),
            ];

            if ($wpdb->insert($table, $data)) {
                $imported++;
            }
        }

        // Update event result count
        update_post_meta($event_id, '_pausatf_result_count', $imported);

        return $imported;
    }

    /**
     * Determine division from athlete age/gender
     */
    private function determine_division(?array $athlete): string {
        if (!$athlete) {
            return 'Open';
        }

        $age = $athlete['age'] ?? 0;
        $gender = strtoupper($athlete['gender'] ?? 'M');

        if ($age < 40) {
            return 'Open';
        } elseif ($age < 50) {
            return 'Masters 40+';
        } elseif ($age < 60) {
            return 'Seniors 50+';
        } elseif ($age < 70) {
            return 'Super-Seniors 60+';
        } else {
            return 'Veterans 70+';
        }
    }

    /**
     * Get supported file extensions
     */
    public static function get_supported_extensions(): array {
        return ['hy3', 'cl2', 'hyv', 'zip'];
    }
}
