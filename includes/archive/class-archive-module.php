<?php
/**
 * Administrator archive workflow and published-only WordPress routes.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;
use Throwable;

final class ArchiveModule {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_pausatf_archive_context', [self::class, 'handle']);
        add_action('admin_enqueue_scripts', static function (): void {
            if (($_GET['page'] ?? null) === 'pausatf-archive-review' && current_user_can('manage_options')) {
                wp_enqueue_style('pausatf-archive-context', PAUSATF_RESULTS_URL . 'assets/css/archive-context.css', [], '1');
            }
        });
        add_action('rest_api_init', [self::class, 'routes']);
        add_shortcode('pausatf_archive_release', [self::class, 'shortcode']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('pausatf-results archive', new ArchiveCli());
        }
    }

    public static function workflow(): ArchiveWorkflow {
        global $wpdb;
        if (!$wpdb->dbh instanceof \mysqli) { throw new \RuntimeException('Archive context requires the native MySQL driver.'); }
        return new ArchiveWorkflow(new ArchiveDatabase($wpdb->dbh, $wpdb->prefix));
    }

    public static function menu(): void {
        add_submenu_page('pausatf-results', __('Archive review', 'pausatf-results'), __('Archive review', 'pausatf-results'), 'manage_options', 'pausatf-archive-review', [self::class, 'page']);
    }

    public static function routes(): void {
        $collections = implode('|', ArchiveBundle::ENTITIES);
        $args = ['id' => ['type' => 'integer', 'minimum' => 1]];
        register_rest_route('pausatf/v1', '/archive/releases/(?P<id>[1-9][0-9]*)', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [self::class, 'public_release'], 'args' => $args,
        ]);
        register_rest_route('pausatf/v1', '/archive/releases/(?P<id>[1-9][0-9]*)/(?P<collection>' . $collections . ')', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [self::class, 'public_release'],
            'args' => $args + ['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]],
        ]);
        register_rest_route('pausatf/v1', '/archive/review/(?P<id>[1-9][0-9]*)', [
            'methods' => 'GET', 'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'callback' => [self::class, 'private_release'], 'args' => $args,
        ]);
    }

    public static function public_release(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        try {
            $release = self::workflow()->published(self::id($request['id']));
            if ($release === null) { return new \WP_Error('archive_not_found', 'Published archive release not found.', ['status' => 404]); }
            $entities = $release['entities'];
            unset($release['entities']);
            $kind = $request->get_param('collection');
            if ($kind === null) {
                $release['collections'] = array_map('count', $entities);
            } else {
                if (!is_string($kind) || !in_array($kind, ArchiveBundle::ENTITIES, true)) { throw new InvalidArgumentException('Invalid collection.'); }
                $page = self::id($request->get_param('page') ?? 1);
                $limit = self::id($request->get_param('per_page') ?? 50);
                if ($page > 100000 || $limit > 100) { throw new InvalidArgumentException('Invalid archive page.'); }
                $release['collection'] = $kind;
                $release['total'] = count($entities[$kind]);
                $release['page'] = $page;
                $release['per_page'] = $limit;
                $release['records'] = array_slice($entities[$kind], ($page - 1) * $limit, $limit);
            }
            return new \WP_REST_Response($release);
        } catch (InvalidArgumentException) {
            return new \WP_Error('archive_invalid_request', 'Invalid archive request.', ['status' => 400]);
        } catch (Throwable) {
            return new \WP_Error('archive_unavailable', 'Archive is unavailable.', ['status' => 503]);
        }
    }

    public static function private_release(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (!current_user_can('manage_options')) { return new \WP_Error('archive_forbidden', 'Administrator access required.', ['status' => 403]); }
        try {
            $row = self::workflow()->inspect(self::id($request['id']));
            return $row === null ? new \WP_Error('archive_not_found', 'Archive candidate not found.', ['status' => 404]) : new \WP_REST_Response($row);
        } catch (Throwable) {
            return new \WP_Error('archive_unavailable', 'Archive is unavailable.', ['status' => 503]);
        }
    }

    public static function handle(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('POST required.', '', ['response' => 405]); }
        check_admin_referer('pausatf_archive_context');
        try {
            $action = $_POST['archive_action'] ?? null;
            if (!is_string($action) || !in_array($action, ['install', 'stage', 'review', 'publish', 'withdraw'], true)) {
                throw new InvalidArgumentException('Invalid archive action.');
            }
            $workflow = self::workflow();
            $id = null;
            if ($action === 'install') { $workflow->install(); }
            elseif ($action === 'stage') {
                $id = $workflow->stage(self::upload('bundle', ArchiveBundle::MAX_JSON_BYTES), self::upload('source', ArchiveBundle::MAX_SOURCE_BYTES), get_current_user_id());
            } else {
                $id = self::id($_POST['release_id'] ?? null);
                if ($action === 'review') {
                    $workflow->review($id, self::upload('report', ArchiveBundle::MAX_JSON_BYTES), self::upload('source', ArchiveBundle::MAX_SOURCE_BYTES), get_current_user_id());
                } elseif ($action === 'publish') { $workflow->publish($id); }
                else { $workflow->withdraw($id); }
            }
            $url = add_query_arg(['page' => 'pausatf-archive-review', 'notice' => 'saved'] + ($id === null ? [] : ['release_id' => $id]), admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        } catch (Throwable $error) {
            $message = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Archive operation was not confirmed. Inspect the candidate by its checksum before retrying.';
            wp_die(esc_html($message), '', ['response' => $error instanceof InvalidArgumentException ? 400 : 500]);
        }
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
        echo '<div class="wrap"><h1>' . esc_html__('Archive review', 'pausatf-results') . '</h1>';
        try {
            $workflow = self::workflow();
            if (!$workflow->is_installed()) {
                echo '<p>' . esc_html__('Install the additive archive tables before staging candidates. Existing results are retained.', 'pausatf-results') . '</p>';
                self::form('install', __('Install archive tables', 'pausatf-results'));
                echo '</div>';
                return;
            }
            if (($_GET['notice'] ?? '') === 'saved') { echo '<div class="notice notice-success"><p>' . esc_html__('Archive operation confirmed.', 'pausatf-results') . '</p></div>'; }
            echo '<h2>' . esc_html__('Stage a candidate', 'pausatf-results') . '</h2><p>' . esc_html__('Upload the context bundle and its original source. Staging does not publish results. A different administrator must compare the source and submit a verification report.', 'pausatf-results') . '</p>';
            self::form('stage', __('Stage candidate', 'pausatf-results'), null, ['bundle' => 'Context bundle (JSON)', 'source' => 'Original source']);
            if (isset($_GET['release_id'])) {
                $row = $workflow->inspect(self::id($_GET['release_id']));
                if ($row !== null) {
                    echo '<h2>' . esc_html__('Candidate details', 'pausatf-results') . '</h2><p>' . esc_html($row['competition_key'] . ' | ' . $row['state']) . '</p>';
                    echo '<p>' . esc_html__('Bundle checksum:', 'pausatf-results') . ' <code>' . esc_html($row['bundle_sha256']) . '</code></p>';
                    echo '<details><summary>' . esc_html__('Original context and source locations', 'pausatf-results') . '</summary><pre class="pausatf-archive-evidence">' . esc_html($row['bundle_json']) . '</pre></details>';
                    if ($row['review'] !== null) { echo '<details><summary>' . esc_html__('Independent verification evidence', 'pausatf-results') . '</summary><pre class="pausatf-archive-evidence">' . esc_html($row['review']['report_json']) . '</pre></details>'; }
                    if ($row['state'] === 'candidate') {
                        if ((int) $row['producer_user_id'] !== get_current_user_id()) {
                            self::form('review', __('Save independent review', 'pausatf-results'), (int) $row['id'], ['report' => 'Verification report (JSON)', 'source' => 'Original source']);
                        } else { echo '<p>' . esc_html__('Another administrator must review this candidate.', 'pausatf-results') . '</p>'; }
                    } elseif ($row['state'] === 'verified') { self::form('publish', __('Publish this verified version', 'pausatf-results'), (int) $row['id']); }
                    elseif ($row['state'] === 'published') {
                        echo '<p>' . esc_html__('Display shortcode:', 'pausatf-results') . ' <code>' . esc_html('[pausatf_archive_release id="' . (int) $row['id'] . '"]') . '</code></p>';
                        self::form('withdraw', __('Withdraw public release', 'pausatf-results'), (int) $row['id']);
                    }
                }
            }
            $page = isset($_GET['archive_page']) ? self::id($_GET['archive_page']) : 1;
            if ($page > 40000) { throw new InvalidArgumentException('Invalid archive page.'); }
            $rows = $workflow->recent(($page - 1) * 25, 25);
            echo '<h2>' . esc_html__('Recent candidates and releases', 'pausatf-results') . '</h2><table class="widefat"><thead><tr><th scope="col">ID</th><th scope="col">Competition</th><th scope="col">State</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $url = add_query_arg(['page' => 'pausatf-archive-review', 'release_id' => $row['id']], admin_url('admin.php'));
                echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html((string) $row['id']) . '</a></td><td>' . esc_html($row['competition_key']) . '</td><td>' . esc_html($row['state']) . '</td></tr>';
            }
            echo '</tbody></table><p>';
            if ($page > 1) { echo '<a href="' . esc_url(add_query_arg(['page' => 'pausatf-archive-review', 'archive_page' => $page - 1], admin_url('admin.php'))) . '">' . esc_html__('Previous page', 'pausatf-results') . '</a> '; }
            if (count($rows) === 25) { echo '<a href="' . esc_url(add_query_arg(['page' => 'pausatf-archive-review', 'archive_page' => $page + 1], admin_url('admin.php'))) . '">' . esc_html__('Next page', 'pausatf-results') . '</a>'; }
            echo '</p>';
        } catch (Throwable) { echo '<div class="notice notice-error"><p>' . esc_html__('Archive information is unavailable. Check schema installation and review the operation before retrying.', 'pausatf-results') . '</p></div>'; }
        echo '</div>';
    }

    public static function shortcode(array|string $attributes): string {
        $attributes = shortcode_atts(['id' => '0'], $attributes, 'pausatf_archive_release');
        try {
            $release = self::workflow()->published(self::id($attributes['id']));
            if ($release === null) { return '<p>' . esc_html__('Published archive release not found.', 'pausatf-results') . '</p>'; }
            $page = isset($_GET['archive_result_page']) ? self::id($_GET['archive_result_page']) : 1;
            if ($page > 100000) { throw new InvalidArgumentException('Invalid archive page.'); }
            wp_enqueue_style('pausatf-archive-context', PAUSATF_RESULTS_URL . 'assets/css/archive-context.css', [], '1');
            return ArchiveView::render($release, $page);
        } catch (Throwable) { return '<p>' . esc_html__('Archive results are unavailable.', 'pausatf-results') . '</p>'; }
    }

    public static function id(mixed $value): int {
        if (!is_int($value) && !is_string($value)) { throw new InvalidArgumentException('Invalid archive identifier.'); }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) { throw new InvalidArgumentException('Invalid archive identifier.'); }
        return $id;
    }

    private static function upload(string $field, int $limit): string {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Select a valid uploaded source and JSON artifact.');
        }
        $contents = file_get_contents($file['tmp_name'], false, null, 0, $limit + 1);
        if ($contents === false || $contents === '' || strlen($contents) > $limit) { throw new InvalidArgumentException('Uploaded file is empty or exceeds the archive size limit.'); }
        return $contents;
    }

    private static function form(string $action, string $label, ?int $id = null, array $files = []): void {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="pausatf_archive_context"><input type="hidden" name="archive_action" value="' . esc_attr($action) . '">';
        wp_nonce_field('pausatf_archive_context');
        if ($id !== null) { echo '<input type="hidden" name="release_id" value="' . esc_attr((string) $id) . '">'; }
        foreach ($files as $name => $description) {
            $field = 'archive-' . $action . '-' . $name;
            echo '<p><label for="' . esc_attr($field) . '">' . esc_html($description) . '</label> <input id="' . esc_attr($field) . '" type="file" name="' . esc_attr($name) . '" required></p>';
        }
        submit_button($label, 'secondary', 'submit', true);
        echo '</form>';
    }
}
