<?php
/**
 * Data Exporter - CSV and PDF export functionality
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exports results data to various formats
 */
class DataExporter {
    private static ?DataExporter $instance = null;

    public static function instance(): DataExporter {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'handle_export_request']);
        add_action('wp_ajax_pausatf_export', [$this, 'ajax_export']);
    }

    /**
     * Handle export request via query param
     */
    public function handle_export_request(): void {
        if (!isset($_GET['pausatf_export'])) {
            return;
        }

        $format = sanitize_text_field($_GET['pausatf_export']);
        $event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
        $athlete = isset($_GET['athlete']) ? sanitize_text_field($_GET['athlete']) : '';

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pausatf_export')) {
            wp_die('Invalid request');
        }

        switch ($format) {
            case 'csv':
                $this->export_csv($event_id, $athlete);
                break;
            case 'pdf':
                $this->export_pdf($event_id, $athlete);
                break;
        }
        exit;
    }

    /**
     * AJAX export handler
     */
    public function ajax_export(): void {
        check_ajax_referer('pausatf_export', 'nonce');

        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $event_id = absint($_POST['event_id'] ?? 0);

        $url = add_query_arg([
            'pausatf_export' => $format,
            'event_id' => $event_id,
            '_wpnonce' => wp_create_nonce('pausatf_export'),
        ], home_url());

        wp_send_json_success(['url' => $url]);
    }

    /**
     * Export to CSV
     *
     * @param int    $event_id Event ID (0 for all)
     * @param string $athlete Athlete name filter
     */
    public function export_csv(int $event_id = 0, string $athlete = ''): void {
        $results = $this->get_export_data($event_id, $athlete);

        $filename = $this->generate_filename('csv', $event_id);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Headers
        fputcsv($output, [
            'Place',
            'Name',
            'Age',
            'Division',
            'Time',
            'Points',
            'Club',
            'Bib',
            'Event',
            'Date',
        ]);

        // Data
        foreach ($results as $row) {
            fputcsv($output, [
                $row['place'] ?? '',
                $row['athlete_name'] ?? '',
                $row['athlete_age'] ?? '',
                $row['division'] ?? '',
                $row['time_display'] ?? '',
                $row['points'] ?? '',
                $row['club'] ?? '',
                $row['bib'] ?? '',
                $row['event_name'] ?? '',
                $row['event_date'] ?? '',
            ]);
        }

        fclose($output);
    }

    /**
     * Export to PDF (simple HTML-based PDF)
     *
     * @param int    $event_id Event ID
     * @param string $athlete Athlete name filter
     */
    public function export_pdf(int $event_id = 0, string $athlete = ''): void {
        $results = $this->get_export_data($event_id, $athlete);

        $filename = $this->generate_filename('pdf', $event_id);
        $event_name = $event_id ? get_the_title($event_id) : 'All Results';

        // Simple HTML to PDF using browser print
        header('Content-Type: text/html; charset=utf-8');

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo esc_html($event_name); ?> - Results</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 10pt; }
                h1 { font-size: 14pt; margin-bottom: 5px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; }
                th { background: #f0f0f0; font-weight: bold; }
                tr:nth-child(even) { background: #f9f9f9; }
                .print-btn { margin: 10px 0; }
                @media print {
                    .print-btn { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="print-btn">
                <button onclick="window.print()">Print / Save as PDF</button>
                <button onclick="window.close()">Close</button>
            </div>

            <h1><?php echo esc_html($event_name); ?></h1>
            <p>Generated: <?php echo date('F j, Y g:i A'); ?></p>

            <table>
                <thead>
                    <tr>
                        <th>Place</th>
                        <th>Name</th>
                        <th>Age</th>
                        <th>Division</th>
                        <th>Time</th>
                        <th>Points</th>
                        <th>Club</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['place'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['athlete_name'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['athlete_age'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['division'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['time_display'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['points'] ?? ''); ?></td>
                            <td><?php echo esc_html($row['club'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <script>
                // Auto-print on load
                // window.onload = function() { window.print(); };
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Get data for export
     */
    private function get_export_data(int $event_id = 0, string $athlete = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $where = ['1=1'];
        $params = [];

        if ($event_id) {
            $where[] = 'r.event_id = %d';
            $params[] = $event_id;
        }

        if ($athlete) {
            $where[] = 'r.athlete_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($athlete) . '%';
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT r.*, p.post_title as event_name, m.meta_value as event_date
                  FROM {$table} r
                  LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
                  LEFT JOIN {$wpdb->postmeta} m ON r.event_id = m.post_id AND m.meta_key = '_pausatf_event_date'
                  WHERE {$where_clause}
                  ORDER BY m.meta_value DESC, r.place ASC
                  LIMIT 10000";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Generate export filename
     */
    private function generate_filename(string $extension, int $event_id = 0): string {
        if ($event_id) {
            $event_name = sanitize_file_name(get_the_title($event_id));
            return "pausatf-{$event_name}-results.{$extension}";
        }
        return 'pausatf-results-' . date('Y-m-d') . '.' . $extension;
    }

    /**
     * Get export URL
     *
     * @param string $format csv or pdf
     * @param int    $event_id Optional event ID
     * @return string Export URL
     */
    public static function get_export_url(string $format, int $event_id = 0): string {
        return add_query_arg([
            'pausatf_export' => $format,
            'event_id' => $event_id,
            '_wpnonce' => wp_create_nonce('pausatf_export'),
        ], home_url());
    }
}

// Initialize
add_action('plugins_loaded', function() {
    DataExporter::instance();
});
