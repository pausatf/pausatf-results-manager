<?php
/**
 * CSV/Excel Importer - Import results from spreadsheets
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports results from CSV and Excel files
 */
class CSVImporter {
    /**
     * Expected column mappings
     */
    private const COLUMN_MAPPINGS = [
        'place' => ['place', 'position', 'pos', 'rank', 'overall', 'o\'all'],
        'athlete_name' => ['name', 'athlete', 'runner', 'participant', 'full name', 'fullname'],
        'athlete_age' => ['age', 'athlete age'],
        'time_display' => ['time', 'finish', 'finish time', 'chip time', 'gun time', 'net time'],
        'division' => ['division', 'div', 'category', 'age group', 'ag'],
        'division_place' => ['div place', 'division place', 'ag place', 'category place'],
        'club' => ['club', 'team', 'affiliation', 'organization', 'org'],
        'bib' => ['bib', 'bib number', 'number', 'race number'],
        'sex' => ['sex', 'gender', 'm/f', 'mf'],
        'pace' => ['pace', 'avg pace', 'min/mile', 'min/mi'],
        'points' => ['points', 'pts', 'score'],
    ];

    /**
     * Import from uploaded CSV file
     *
     * @param array $file $_FILES array element
     * @param array $options Import options
     * @return array Import result
     */
    public function import_from_upload(array $file, array $options = []): array {
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'File upload error: ' . $file['error']];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt', 'tsv'])) {
            return ['success' => false, 'error' => 'Invalid file type. Please upload CSV, TSV, or TXT.'];
        }

        return $this->import_from_file($file['tmp_name'], $options);
    }

    /**
     * Import from file path
     *
     * @param string $file_path Path to CSV file
     * @param array  $options Import options
     * @return array Import result
     */
    public function import_from_file(string $file_path, array $options = []): array {
        if (!file_exists($file_path)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Could not open file'];
        }

        // Detect delimiter
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = $this->detect_delimiter($first_line);

        // Read header row
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return ['success' => false, 'error' => 'Could not read headers'];
        }

        // Map headers to fields
        $column_map = $this->map_columns($headers);

        if (empty($column_map)) {
            fclose($handle);
            return ['success' => false, 'error' => 'Could not map any columns. Expected: place, name, time, etc.'];
        }

        // Create event if needed
        $event_id = $options['event_id'] ?? 0;
        if (!$event_id && !empty($options['event_name'])) {
            $event_id = $this->create_event($options);
        }

        if (!$event_id) {
            fclose($handle);
            return ['success' => false, 'error' => 'No event specified'];
        }

        // Import rows
        $imported = 0;
        $errors = [];
        $row_num = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row_num++;

            $result = $this->parse_row($row, $column_map, $headers);

            if (!$result) {
                $errors[] = "Row {$row_num}: Could not parse";
                continue;
            }

            $saved = $this->save_result($event_id, $result);

            if ($saved) {
                $imported++;
            } else {
                $errors[] = "Row {$row_num}: Could not save";
            }
        }

        fclose($handle);

        // Update event meta
        update_post_meta($event_id, '_pausatf_result_count', $imported);

        return [
            'success' => true,
            'event_id' => $event_id,
            'imported' => $imported,
            'errors' => $errors,
            'columns_mapped' => array_keys($column_map),
        ];
    }

    /**
     * Detect CSV delimiter
     */
    private function detect_delimiter(string $line): string {
        $delimiters = [',', "\t", ';', '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }

        return array_search(max($counts), $counts);
    }

    /**
     * Map CSV headers to field names
     */
    private function map_columns(array $headers): array {
        $map = [];

        foreach ($headers as $index => $header) {
            $header_lower = strtolower(trim($header));

            foreach (self::COLUMN_MAPPINGS as $field => $variants) {
                foreach ($variants as $variant) {
                    if ($header_lower === $variant || strpos($header_lower, $variant) !== false) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Parse a CSV row into result data
     */
    private function parse_row(array $row, array $column_map, array $headers): ?array {
        $result = [];

        foreach ($column_map as $field => $index) {
            if (!isset($row[$index])) {
                continue;
            }

            $value = trim($row[$index]);

            switch ($field) {
                case 'place':
                case 'division_place':
                case 'athlete_age':
                    $result[$field] = (int) preg_replace('/\D/', '', $value);
                    break;

                case 'time_display':
                    $result['time_display'] = $value;
                    $result['time_seconds'] = $this->parse_time($value);
                    break;

                case 'points':
                    $result['points'] = (float) str_replace(',', '', $value);
                    break;

                case 'sex':
                    $result['sex'] = strtoupper(substr($value, 0, 1));
                    break;

                default:
                    $result[$field] = $value;
            }
        }

        // Handle combined name/age
        if (!empty($result['athlete_name']) && empty($result['athlete_age'])) {
            if (preg_match('/^(.+?),?\s*(\d{1,3})$/', $result['athlete_name'], $matches)) {
                $result['athlete_name'] = trim($matches[1]);
                $result['athlete_age'] = (int) $matches[2];
            }
        }

        // Validate required fields
        if (empty($result['athlete_name'])) {
            return null;
        }

        return $result;
    }

    /**
     * Parse time string to seconds
     */
    private function parse_time(string $time): ?int {
        $time = trim($time);

        // HH:MM:SS
        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
        }

        // MM:SS
        if (preg_match('/^(\d+):(\d{2})/', $time, $m)) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        return null;
    }

    /**
     * Create event post
     */
    private function create_event(array $options): int {
        $event_data = [
            'post_type' => 'pausatf_event',
            'post_title' => $options['event_name'],
            'post_status' => 'publish',
            'meta_input' => [
                '_pausatf_event_date' => $options['event_date'] ?? null,
                '_pausatf_event_location' => $options['event_location'] ?? null,
                '_pausatf_imported_at' => current_time('mysql'),
            ],
        ];

        $event_id = wp_insert_post($event_data);

        if (!is_wp_error($event_id) && !empty($options['event_type'])) {
            wp_set_object_terms($event_id, $options['event_type'], 'pausatf_event_type');
        }

        return is_wp_error($event_id) ? 0 : $event_id;
    }

    /**
     * Save result to database
     */
    private function save_result(int $event_id, array $result): bool {
        global $wpdb;

        $data = array_merge($result, [
            'event_id' => $event_id,
            'raw_data' => json_encode($result),
        ]);

        return (bool) $wpdb->insert($wpdb->prefix . 'pausatf_results', $data);
    }

    /**
     * Get sample template for CSV import
     */
    public static function get_template(): string {
        $headers = ['Place', 'Name', 'Age', 'Sex', 'Division', 'Time', 'Points', 'Club', 'Bib'];

        $sample_data = [
            ['1', 'John Smith', '35', 'M', 'Open', '15:30', '100', 'West Valley TC', '101'],
            ['2', 'Jane Doe', '42', 'F', 'Masters 40+', '16:45', '90', 'Impala Racing', '102'],
            ['3', 'Bob Johnson', '55', 'M', 'Seniors 50+', '17:20', '80', 'Tamalpa Runners', '103'],
        ];

        $output = implode(',', $headers) . "\n";
        foreach ($sample_data as $row) {
            $output .= implode(',', $row) . "\n";
        }

        return $output;
    }
}
