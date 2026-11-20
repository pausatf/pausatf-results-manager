<?php
/**
 * The Import screen's Upload HTML File form imports through ResultsImporter after
 * checking the upload, the extension and the size.
 */

namespace {
    if (!function_exists('sanitize_file_name')) {
        function sanitize_file_name($filename) {
            return preg_replace('/[^A-Za-z0-9._-]/', '', (string) $filename);
        }
    }
}

namespace PAUSATF\Results\Tests {
    use PAUSATF\Results\Admin\AdminImport;
    use PAUSATF\Results\ResultsImporter;
    use PHPUnit\Framework\TestCase;

    final class AdminImportUploadTest extends TestCase {
        private string $tmp = '';

        protected function setUp(): void {
            require_once dirname(__DIR__) . '/includes/parsers/class-parser-detector.php';
            require_once dirname(__DIR__) . '/includes/class-results-importer.php';
            require_once dirname(__DIR__) . '/admin/class-admin-import.php';
            $this->tmp = (string) tempnam(sys_get_temp_dir(), 'pausatf-upload');
            file_put_contents($this->tmp, '<html><body><table><tr><td>1</td></tr></table></body></html>');
        }

        protected function tearDown(): void {
            if (is_file($this->tmp)) {
                unlink($this->tmp);
            }
        }

        /** @param array<string, mixed> $overrides */
        private function file(array $overrides = []): array {
            return $overrides + ['name' => 'XCChamps2024pa.html', 'tmp_name' => $this->tmp, 'size' => filesize($this->tmp), 'error' => UPLOAD_ERR_OK];
        }

        public function test_an_html_page_is_imported_through_results_importer(): void {
            $importer = $this->createMock(ResultsImporter::class);
            $importer->expects($this->once())
                ->method('import_from_html')
                ->with((string) file_get_contents($this->tmp), ['source_file' => 'XCChamps2024pa.html'])
                ->willReturn(['success' => true, 'records_imported' => 1, 'event_name' => 'XC Champs']);

            $result = AdminImport::import_html_upload($this->file(), $importer);

            self::assertTrue($result['success']);
        }

        public function test_rejected_uploads_never_reach_the_importer(): void {
            $importer = $this->createMock(ResultsImporter::class);
            $importer->expects($this->never())->method('import_from_html');

            foreach ([
                'other extension' => $this->file(['name' => 'results.php']),
                'double extension' => $this->file(['name' => 'results.html.php']),
                'svg' => $this->file(['name' => 'results.svg']),
                'empty' => $this->file(['size' => 0]),
                'too large' => $this->file(['size' => AdminImport::MAX_UPLOAD_BYTES + 1]),
                'over the server limit' => $this->file(['error' => UPLOAD_ERR_INI_SIZE]),
                'partial' => $this->file(['error' => UPLOAD_ERR_PARTIAL]),
                'no file' => ['error' => UPLOAD_ERR_NO_FILE],
            ] as $case => $file) {
                $result = AdminImport::import_html_upload($file, $importer);
                self::assertFalse($result['success'], $case);
                self::assertNotEmpty($result['error'], $case);
            }
        }

        public function test_upper_case_htm_is_accepted(): void {
            $importer = $this->createStub(ResultsImporter::class);
            $importer->method('import_from_html')->willReturn(['success' => true]);

            self::assertTrue(AdminImport::import_html_upload($this->file(['name' => 'RESULTS.HTM']), $importer)['success']);
        }

        public function test_the_view_checks_nonce_and_capability_before_importing(): void {
            $view = (string) file_get_contents(dirname(__DIR__) . '/admin/views/import.php');
            $handler = substr($view, (int) strpos($view, "isset(\$_POST['pausatf_upload_file'])"));
            $handler = substr($handler, 0, (int) strpos($handler, 'AdminImport::import_html_upload'));

            self::assertStringContainsString("wp_verify_nonce(\$_POST['_wpnonce'] ?? '', 'pausatf_import')", $handler);
            self::assertStringContainsString("current_user_can('manage_options')", $handler);
            self::assertStringContainsString('is_uploaded_file(', $handler);
        }
    }
}
