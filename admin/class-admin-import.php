<?php
/**
 * Admin Import Handler
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results\Admin;

use PAUSATF\Results\ResultsImporter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Import screen's file upload. The URL, batch and analyze forms are handled in the view.
 */
class AdminImport {
    /** Largest accepted results page. Legacy pages, Word-generated ones included, are well under this. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const ALLOWED_EXTENSIONS = ['html', 'htm'];

    /**
     * Import one uploaded HTML results page through the same parsers as a URL import.
     *
     * The caller checks the nonce, the capability and is_uploaded_file(). The page is parsed
     * from PHP's temporary file and never stored in uploads, so it is not web-reachable.
     *
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $file One $_FILES entry.
     * @return array<string, mixed> ResultsImporter::import_from_html() result, or success false with an error.
     */
    public static function import_html_upload(array $file, ?ResultsImporter $importer = null): array {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return ['success' => false, 'error' => __('The file is larger than the server accepts.', 'pausatf-results')];
        }
        if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            return ['success' => false, 'error' => __('No file was uploaded.', 'pausatf-results')];
        }

        // The extension, not the client-supplied type, decides what is accepted.
        $name = (string) ($file['name'] ?? '');
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED_EXTENSIONS, true)) {
            return ['success' => false, 'error' => __('Upload an .html or .htm results page.', 'pausatf-results')];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return ['success' => false, 'error' => __('The file is empty or larger than 10 MB.', 'pausatf-results')];
        }

        $html = file_get_contents($file['tmp_name']);
        if ($html === false || $html === '') {
            return ['success' => false, 'error' => __('The uploaded file could not be read.', 'pausatf-results')];
        }

        return ($importer ?? new ResultsImporter())->import_from_html($html, [
            'source_file' => sanitize_file_name($name),
        ]);
    }
}
