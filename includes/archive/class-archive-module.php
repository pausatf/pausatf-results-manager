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
        ArchiveCapabilities::register();
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_pausatf_archive_context', [self::class, 'handle']);
        add_action('admin_enqueue_scripts', static function (): void {
            if (($_GET['page'] ?? null) === 'pausatf-archive-review' && self::can_use_archive()) {
                wp_enqueue_style('pausatf-archive-context', PAUSATF_RESULTS_URL . 'assets/css/archive-context.css', [], '1');
            }
        });
        add_action('rest_api_init', [self::class, 'routes']);
        add_shortcode('pausatf_archive_release', [self::class, 'shortcode']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('pausatf-results archive', new ArchiveCli());
            \WP_CLI::add_command('pausatf archive', new ArchiveCli());
        }
    }

    public static function workflow(): ArchiveWorkflow {
        global $wpdb;
        if (!$wpdb->dbh instanceof \mysqli) { throw new \RuntimeException('Archive context requires the native MySQL driver.'); }
        return new ArchiveWorkflow(new ArchiveDatabase($wpdb->dbh, $wpdb->prefix), [self::class, 'transitioned'](...));
    }

    /**
     * Fires pausatf_archive_release_published / pausatf_archive_release_withdrawn after the state change has
     * committed. A failing listener is logged and never turns a committed change into a reported failure.
     */
    public static function transitioned(string $state, int $id): void {
        try {
            do_action('pausatf_archive_release_' . $state, $id);
        } catch (Throwable $error) {
            error_log(sprintf('pausatf archive: listener for release %d %s failed: %s', $id, $state, $error->getMessage()));
        }
    }

    /** Any archive role may open the workflow screen; each action checks its own capability. */
    public static function can_use_archive(): bool {
        foreach (['manage_options', ArchiveCapabilities::PRODUCE, ArchiveCapabilities::VERIFY, ArchiveCapabilities::PUBLISH] as $cap) {
            if (current_user_can($cap)) { return true; }
        }
        return false;
    }

    /** Capability required for an archive action (release id where the check depends on it). */
    public static function action_allowed(string $action, ?int $id = null): bool {
        return match ($action) {
            'install' => current_user_can('manage_options'),
            'stage' => current_user_can(ArchiveCapabilities::PRODUCE),
            'review' => $id !== null && current_user_can(ArchiveCapabilities::VERIFY_RELEASE, $id),
            'publish', 'withdraw' => current_user_can(ArchiveCapabilities::PUBLISH),
            'inspect' => self::can_use_archive(),
            default => false,
        };
    }

    public static function menu(): void {
        $cap = 'manage_options';
        foreach ([ArchiveCapabilities::PRODUCE, ArchiveCapabilities::VERIFY, ArchiveCapabilities::PUBLISH] as $candidate) {
            if (!current_user_can('manage_options') && current_user_can($candidate)) { $cap = $candidate; break; }
        }
        add_submenu_page('pausatf-results', __('Archive review', 'pausatf-results'), __('Archive review', 'pausatf-results'), $cap, 'pausatf-archive-review', [self::class, 'page']);
    }

    public static function routes(): void {
        (new ArchiveRestController())->register_routes();
    }

    public static function handle(): void {
        if (!self::can_use_archive()) { wp_die('Archive access required.', '', ['response' => 403]); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('POST required.', '', ['response' => 405]); }
        check_admin_referer('pausatf_archive_context');
        try {
            $action = $_POST['archive_action'] ?? null;
            if (!is_string($action) || !in_array($action, ['install', 'stage', 'review', 'publish', 'withdraw'], true)) {
                throw new InvalidArgumentException('Invalid archive action.');
            }
            $id = $action === 'install' || $action === 'stage' ? null : self::id($_POST['release_id'] ?? null);
            if (!self::action_allowed($action, $id)) { wp_die('You are not allowed to perform this archive action.', '', ['response' => 403]); }
            $workflow = self::workflow();
            if ($action === 'install') { $workflow->install(); }
            elseif ($action === 'stage') {
                $id = $workflow->stage(self::upload('bundle', ArchiveBundle::MAX_JSON_BYTES), self::upload('source', ArchiveBundle::MAX_SOURCE_BYTES), get_current_user_id());
            } else {
                if ($id === null) { throw new InvalidArgumentException('Invalid release identifier.'); }
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
        if (!self::can_use_archive()) { wp_die('Archive access required.', '', ['response' => 403]); }
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
            if (self::action_allowed('stage')) { self::form('stage', __('Stage candidate', 'pausatf-results'), null, ['bundle' => 'Context bundle (JSON)', 'source' => 'Original source']); }
            if (isset($_GET['release_id'])) {
                $row = $workflow->inspect(self::id($_GET['release_id']));
                if ($row !== null) {
                    echo '<h2>' . esc_html__('Candidate details', 'pausatf-results') . '</h2><p>' . esc_html($row['competition_key'] . ' | ' . $row['state']) . '</p>';
                    echo '<p>' . esc_html__('Bundle checksum:', 'pausatf-results') . ' <code>' . esc_html($row['bundle_sha256']) . '</code></p>';
                    echo '<details><summary>' . esc_html__('Original context and source locations', 'pausatf-results') . '</summary><pre class="pausatf-archive-evidence">' . esc_html($row['bundle_json']) . '</pre></details>';
                    if ($row['review'] !== null) { echo '<details><summary>' . esc_html__('Independent verification evidence', 'pausatf-results') . '</summary><pre class="pausatf-archive-evidence">' . esc_html($row['review']['report_json']) . '</pre></details>'; }
                    if ($row['state'] === 'candidate') {
                        if (self::action_allowed('review', (int) $row['id'])) {
                            self::form('review', __('Save independent review', 'pausatf-results'), (int) $row['id'], ['report' => 'Verification report (JSON)', 'source' => 'Original source']);
                        } elseif ((int) $row['producer_user_id'] === get_current_user_id()) {
                            echo '<p>' . esc_html__('Another verifier must review this candidate.', 'pausatf-results') . '</p>';
                        } else { echo '<p>' . esc_html__('A user with the archive verifier capability must review this candidate.', 'pausatf-results') . '</p>'; }
                    } elseif ($row['state'] === 'verified' && self::action_allowed('publish')) { self::form('publish', __('Publish this verified version', 'pausatf-results'), (int) $row['id']); }
                    elseif ($row['state'] === 'published') {
                        echo '<p>' . esc_html__('Display shortcode:', 'pausatf-results') . ' <code>' . esc_html('[pausatf_archive_release id="' . (int) $row['id'] . '"]') . '</code></p>';
                        if (self::action_allowed('withdraw')) { self::form('withdraw', __('Withdraw public release', 'pausatf-results'), (int) $row['id']); }
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
