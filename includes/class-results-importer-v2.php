<?php
/**
 * Results Importer v2 - Refactored with storage separation
 *
 * This version separates parsing orchestration from storage operations.
 * Parsers only handle content extraction, while the repository handles persistence.
 *
 * @package PAUSATF\Results
 * @since 2.3.0
 */

declare(strict_types=1);

namespace PAUSATF\Results;

use PAUSATF\Results\Parsers\ParserDetector;
use PAUSATF\Results\Parsers\ParserInterface;
use PAUSATF\Results\Parsers\ParsedResults;
use PAUSATF\Results\Storage\ResultsRepository;
use PAUSATF\Results\Storage\StorageResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import orchestration - coordinates parsing and storage
 *
 * This class follows the Single Responsibility Principle by delegating:
 * - Content fetching and format detection
 * - Parser selection and execution
 * - Storage operations to ResultsRepository
 */
class ResultsImporterV2 {
    private ParserDetector $detector;
    private ResultsRepository $repository;

    /** @var array<string, callable> Custom pre-processors */
    private array $pre_processors = [];

    /** @var array<string, callable> Custom post-processors */
    private array $post_processors = [];

    public function __construct(?ResultsRepository $repository = null) {
        $this->detector = new ParserDetector();
        $this->repository = $repository ?? new ResultsRepository();
    }

    /**
     * Import results from a URL
     *
     * @param string $url Source URL
     * @param array $options Import options
     * @return ImportResult
     */
    public function import_from_url(string $url, array $options = []): ImportResult {
        $result = new ImportResult();
        $result->source_url = $url;
        $result->started_at = microtime(true);

        // Log import start
        $import_log_id = $this->repository->log_import([
            'source_url' => $url,
            'status' => 'processing',
        ]);
        $result->import_log_id = $import_log_id;

        try {
            // Fetch content
            $html = $this->fetch_url($url);

            // Import the HTML
            $result = $this->import_from_html($html, array_merge($options, [
                'source_url' => $url,
                'import_log_id' => $import_log_id,
            ]));

            // Update log with success
            $this->repository->update_import_log($import_log_id, [
                'status' => $result->success ? 'completed' : 'failed',
                'event_id' => $result->event_id,
                'records_imported' => $result->records_imported,
                'error_message' => $result->error ?? null,
            ]);

        } catch (\Exception $e) {
            $result->success = false;
            $result->error = $e->getMessage();

            $this->repository->update_import_log($import_log_id, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        $result->completed_at = microtime(true);
        $result->duration = $result->completed_at - $result->started_at;

        return $result;
    }

    /**
     * Import results from HTML content
     *
     * @param string $html HTML content
     * @param array $options Import options
     * @return ImportResult
     */
    public function import_from_html(string $html, array $options = []): ImportResult {
        $result = new ImportResult();
        $result->started_at = $result->started_at ?? microtime(true);

        try {
            // Step 1: Pre-process HTML
            $html = $this->run_pre_processors($html, $options);

            // Step 2: Detect and select parser
            $parser = $this->detector->detect($html);

            if (!$parser) {
                $result->success = false;
                $result->error = 'No suitable parser found for this HTML format';
                $result->analysis = $this->detector->analyze($html);
                return $result;
            }

            $result->parser = $parser->get_id();

            // Step 3: Parse content (pure extraction, no storage)
            $parsed = $parser->parse($html, $options);

            if ($parsed->has_errors()) {
                $result->success = false;
                $result->errors = $parsed->errors;
                $result->warnings = $parsed->warnings;
                return $result;
            }

            // Step 4: Validate parsed results
            $validation = $this->validate_parsed_results($parsed);
            if (!$validation['valid']) {
                $result->success = false;
                $result->errors = $validation['errors'];
                return $result;
            }

            // Step 5: Store event (delegates to repository)
            $event_id = $this->repository->store_event($parsed, [
                'source_url' => $options['source_url'] ?? '',
                'source_file' => $options['source_file'] ?? '',
                'parser' => $parser->get_id(),
            ]);

            if (is_wp_error($event_id)) {
                $result->success = false;
                $result->error = $event_id->get_error_message();
                return $result;
            }

            $result->event_id = $event_id;

            // Step 6: Store results (delegates to repository)
            $storage_result = $this->repository->store_results($event_id, $parsed->results);

            $result->success = $storage_result->is_success();
            $result->records_imported = $storage_result->inserted_records;
            $result->records_failed = $storage_result->failed_records;
            $result->warnings = $parsed->warnings;
            $result->divisions = $parsed->divisions;
            $result->event_name = $parsed->event_name;
            $result->event_date = $parsed->event_date;

            if ($storage_result->has_errors()) {
                $result->storage_errors = $storage_result->errors;
            }

            // Step 7: Run post-processors
            $this->run_post_processors($result, $parsed, $options);

        } catch (\Exception $e) {
            $result->success = false;
            $result->error = $e->getMessage();
        }

        $result->completed_at = microtime(true);
        $result->duration = $result->completed_at - ($result->started_at ?? $result->completed_at);

        return $result;
    }

    /**
     * Import from a local file
     *
     * @param string $file_path Path to HTML file
     * @param array $options Import options
     * @return ImportResult
     */
    public function import_from_file(string $file_path, array $options = []): ImportResult {
        $result = new ImportResult();
        $result->source_file = $file_path;

        if (!file_exists($file_path)) {
            $result->success = false;
            $result->error = 'File not found: ' . $file_path;
            return $result;
        }

        $html = file_get_contents($file_path);

        if ($html === false) {
            $result->success = false;
            $result->error = 'Failed to read file: ' . $file_path;
            return $result;
        }

        // Extract context from filename
        $filename = basename($file_path);
        if (preg_match('/^([A-Z]+).*?(\d{4})/', $filename, $matches)) {
            $options['event_type_hint'] = $matches[1];
            $options['year_hint'] = (int) $matches[2];
        }

        return $this->import_from_html($html, array_merge($options, [
            'source_file' => $file_path,
        ]));
    }

    /**
     * Batch import from directory
     *
     * @param string $directory Path to directory
     * @param array $options Import options
     * @return BatchImportResult
     */
    public function import_from_directory(string $directory, array $options = []): BatchImportResult {
        $batch = new BatchImportResult();
        $batch->directory = $directory;

        $files = glob($directory . '/*.html');
        $batch->total_files = count($files);

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip non-result files
            if ($this->should_skip_file($filename)) {
                $batch->skipped++;
                $batch->skipped_files[] = $filename;
                continue;
            }

            $import_result = $this->import_from_file($file, $options);

            if ($import_result->success) {
                $batch->successful++;
            } else {
                $batch->failed++;
            }

            $batch->results[$filename] = $import_result;

            // Prevent timeout
            if (function_exists('set_time_limit')) {
                set_time_limit(30);
            }

            /**
             * Fires after each file in a batch import
             *
             * @param string $filename The processed filename
             * @param ImportResult $import_result The import result
             * @param BatchImportResult $batch The overall batch result
             */
            do_action('pausatf_batch_import_file_processed', $filename, $import_result, $batch);
        }

        return $batch;
    }

    /**
     * Preview import without storing
     *
     * @param string $html HTML content
     * @param array $options Import options
     * @return array Preview data
     */
    public function preview(string $html, array $options = []): array {
        $parser = $this->detector->detect($html);

        if (!$parser) {
            return [
                'success' => false,
                'error' => 'No suitable parser found',
                'analysis' => $this->detector->analyze($html),
            ];
        }

        $parsed = $parser->parse($html, $options);

        return [
            'success' => !$parsed->has_errors(),
            'parser' => $parser->get_id(),
            'event_name' => $parsed->event_name,
            'event_date' => $parsed->event_date,
            'event_location' => $parsed->event_location,
            'divisions' => $parsed->divisions,
            'result_count' => count($parsed->results),
            'sample_results' => array_slice($parsed->results, 0, 10),
            'warnings' => $parsed->warnings,
            'errors' => $parsed->errors,
        ];
    }

    /**
     * Register a pre-processor
     *
     * @param string $name Processor name
     * @param callable $processor Function that takes (string $html, array $options) and returns string
     */
    public function add_pre_processor(string $name, callable $processor): void {
        $this->pre_processors[$name] = $processor;
    }

    /**
     * Register a post-processor
     *
     * @param string $name Processor name
     * @param callable $processor Function that takes (ImportResult, ParsedResults, array $options)
     */
    public function add_post_processor(string $name, callable $processor): void {
        $this->post_processors[$name] = $processor;
    }

    /**
     * Fetch URL content
     */
    private function fetch_url(string $url): string {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'PAUSATF Results Importer/2.0',
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Failed to fetch URL: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            throw new \Exception("HTTP error {$status_code} fetching URL");
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            throw new \Exception('Empty response from URL');
        }

        return $html;
    }

    /**
     * Run pre-processors on HTML
     */
    private function run_pre_processors(string $html, array $options): string {
        foreach ($this->pre_processors as $name => $processor) {
            try {
                $html = $processor($html, $options);
            } catch (\Exception $e) {
                // Log but don't fail
                error_log("PAUSATF pre-processor '{$name}' failed: " . $e->getMessage());
            }
        }

        /**
         * Filter HTML before parsing
         *
         * @param string $html The HTML content
         * @param array $options Import options
         */
        return apply_filters('pausatf_pre_parse_html', $html, $options);
    }

    /**
     * Run post-processors after import
     */
    private function run_post_processors(ImportResult $result, ParsedResults $parsed, array $options): void {
        foreach ($this->post_processors as $name => $processor) {
            try {
                $processor($result, $parsed, $options);
            } catch (\Exception $e) {
                error_log("PAUSATF post-processor '{$name}' failed: " . $e->getMessage());
            }
        }

        /**
         * Fires after successful import
         *
         * @param ImportResult $result The import result
         * @param ParsedResults $parsed The parsed data
         * @param array $options Import options
         */
        do_action('pausatf_import_completed', $result, $parsed, $options);
    }

    /**
     * Validate parsed results before storage
     */
    private function validate_parsed_results(ParsedResults $parsed): array {
        $errors = [];

        // Must have at least one result
        if (empty($parsed->results)) {
            $errors[] = 'No results were parsed from the content';
        }

        // Check for minimum required data
        $valid_results = 0;
        foreach ($parsed->results as $index => $result) {
            if (!empty($result['athlete_name'])) {
                $valid_results++;
            }
        }

        if ($valid_results === 0) {
            $errors[] = 'No valid results with athlete names found';
        }

        /**
         * Filter to add custom validation
         *
         * @param array $errors Current validation errors
         * @param ParsedResults $parsed The parsed results
         */
        $errors = apply_filters('pausatf_validate_parsed_results', $errors, $parsed);

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if file should be skipped
     */
    private function should_skip_file(string $filename): bool {
        $skip_patterns = [
            '/^index/i',
            '/schedule/i',
            '/form/i',
            '/flyer/i',
            '/flier/i',
            '/^info/i',
            '/^about/i',
            '/bylaws/i',
            '/minutes/i',
            '/procedures/i',
        ];

        foreach ($skip_patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        /**
         * Filter to add custom skip patterns
         *
         * @param bool $skip Whether to skip the file
         * @param string $filename The filename
         */
        return apply_filters('pausatf_should_skip_import_file', false, $filename);
    }

    /**
     * Get the parser detector
     */
    public function get_detector(): ParserDetector {
        return $this->detector;
    }

    /**
     * Get the repository
     */
    public function get_repository(): ResultsRepository {
        return $this->repository;
    }
}

/**
 * Import result data structure
 */
class ImportResult {
    public bool $success = false;
    public ?int $event_id = null;
    public ?int $import_log_id = null;
    public int $records_imported = 0;
    public int $records_failed = 0;
    public ?string $source_url = null;
    public ?string $source_file = null;
    public ?string $parser = null;
    public ?string $event_name = null;
    public ?string $event_date = null;
    public array $divisions = [];
    public ?string $error = null;
    public array $errors = [];
    public array $warnings = [];
    public array $storage_errors = [];
    public ?array $analysis = null;
    public ?float $started_at = null;
    public ?float $completed_at = null;
    public ?float $duration = null;

    public function to_array(): array {
        return [
            'success' => $this->success,
            'event_id' => $this->event_id,
            'import_log_id' => $this->import_log_id,
            'records_imported' => $this->records_imported,
            'records_failed' => $this->records_failed,
            'source_url' => $this->source_url,
            'source_file' => $this->source_file,
            'parser' => $this->parser,
            'event_name' => $this->event_name,
            'event_date' => $this->event_date,
            'divisions' => $this->divisions,
            'error' => $this->error,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'storage_errors' => $this->storage_errors,
            'duration' => $this->duration,
        ];
    }
}

/**
 * Batch import result data structure
 */
class BatchImportResult {
    public string $directory = '';
    public int $total_files = 0;
    public int $successful = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public array $skipped_files = [];
    public array $results = [];

    public function to_array(): array {
        return [
            'directory' => $this->directory,
            'total_files' => $this->total_files,
            'successful' => $this->successful,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'skipped_files' => $this->skipped_files,
            'results' => array_map(fn($r) => $r->to_array(), $this->results),
        ];
    }
}
