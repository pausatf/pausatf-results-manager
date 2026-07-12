<?php
/**
 * Certificates & Social Sharing
 *
 * Generates finisher certificates and social media share cards
 *
 * @package PAUSATF\Results
 */

namespace PAUSATF\Results;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Certificate and social card generator
 */
class Certificates {
    /**
     * Certificate templates
     */
    private const TEMPLATES = [
        'finisher' => 'Finisher Certificate',
        'age_group_winner' => 'Age Group Winner',
        'overall_winner' => 'Overall Winner',
        'record_holder' => 'Record Certificate',
        'pr' => 'Personal Record',
    ];

    public function __construct() {
        add_action('wp_ajax_pausatf_generate_certificate', [$this, 'ajax_generate_certificate']);
        add_action('wp_ajax_nopriv_pausatf_generate_certificate', [$this, 'ajax_generate_certificate']);
        add_action('wp_ajax_pausatf_generate_share_card', [$this, 'ajax_generate_share_card']);
        add_action('wp_ajax_nopriv_pausatf_generate_share_card', [$this, 'ajax_generate_share_card']);

        // Invalidate cached artifacts when an event is unpublished.
        add_action('transition_post_status', [$this, 'purge_on_unpublish'], 10, 3);

        add_shortcode('pausatf_certificate_download', [$this, 'shortcode_download']);
        add_shortcode('pausatf_share_result', [$this, 'shortcode_share']);
    }

    /**
     * Generate certificate PDF
     *
     * @param int $result_id Result ID
     * @param string $template Template type
     * @return string|false PDF file path or false
     */
    public function generate_certificate(int $result_id, string $template = 'finisher'): string|false {
        // Only published events yield certificates; never leak results for
        // draft/private/pending events via enumerable result_id.
        $result = $this->load_result($result_id);
        if (!$result || $result['event_status'] !== 'publish') {
            return false;
        }

        // Unknown templates fall back to the default; the value also keys the
        // cache filename, so restrict it to the known set.
        if (!isset(self::TEMPLATES[$template])) {
            $template = 'finisher';
        }

        $version = $this->row_version($result);
        $filename = $this->artifact_filename('certificate', $result_id, $template, $version, 'pdf');

        // Serve a cached file if one exists: enumeration cannot force repeated
        // PDF generation, and the rate limit only gates first-time creation.
        $cached = $this->cached_path($filename);
        if ($cached !== null) {
            return $cached;
        }

        // Get event details
        $event_date = get_post_meta($result['event_id'], '_pausatf_event_date', true);
        $event_location = get_post_meta($result['event_id'], '_pausatf_event_location', true);

        // Generate HTML for certificate
        $html = $this->render_certificate_html($result, $template, [
            'event_date' => $event_date,
            'event_location' => $event_location,
        ]);

        // Convert to PDF using available library
        return $this->html_to_pdf($html, $filename);
    }

    /**
     * Render certificate HTML
     */
    private function render_certificate_html(array $result, string $template, array $meta): string {
        $template_class = $template;
        $title = self::TEMPLATES[$template] ?? 'Certificate';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { size: landscape; margin: 0; }
                body {
                    font-family: 'Georgia', serif;
                    margin: 0;
                    padding: 40px;
                    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                    min-height: 100vh;
                    box-sizing: border-box;
                }
                .certificate {
                    background: white;
                    border: 8px double #1a365d;
                    padding: 40px 60px;
                    text-align: center;
                    position: relative;
                }
                .certificate::before {
                    content: '';
                    position: absolute;
                    top: 20px;
                    left: 20px;
                    right: 20px;
                    bottom: 20px;
                    border: 2px solid #c9a227;
                    pointer-events: none;
                }
                .logo {
                    width: 120px;
                    margin-bottom: 20px;
                }
                .header {
                    font-size: 14px;
                    color: #666;
                    letter-spacing: 3px;
                    text-transform: uppercase;
                    margin-bottom: 10px;
                }
                .title {
                    font-size: 42px;
                    color: #1a365d;
                    margin: 20px 0;
                    font-weight: normal;
                }
                .subtitle {
                    font-size: 18px;
                    color: #4a5568;
                    margin-bottom: 30px;
                }
                .athlete-name {
                    font-size: 36px;
                    color: #2d3748;
                    font-style: italic;
                    margin: 30px 0;
                    border-bottom: 2px solid #c9a227;
                    display: inline-block;
                    padding: 0 40px 10px;
                }
                .event-name {
                    font-size: 24px;
                    color: #1a365d;
                    margin: 20px 0;
                }
                .details {
                    display: flex;
                    justify-content: center;
                    gap: 60px;
                    margin: 30px 0;
                }
                .detail-item {
                    text-align: center;
                }
                .detail-label {
                    font-size: 12px;
                    color: #718096;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                .detail-value {
                    font-size: 28px;
                    color: #2d3748;
                    font-weight: bold;
                }
                .footer {
                    margin-top: 40px;
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                }
                .signature {
                    text-align: center;
                }
                .signature-line {
                    width: 200px;
                    border-top: 1px solid #2d3748;
                    margin-top: 40px;
                    padding-top: 5px;
                    font-size: 12px;
                }
                .date {
                    font-size: 14px;
                    color: #4a5568;
                }
                .seal {
                    width: 80px;
                    height: 80px;
                    border: 3px solid #c9a227;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 10px;
                    color: #c9a227;
                    text-transform: uppercase;
                }
            </style>
        </head>
        <body>
            <div class="certificate <?php echo esc_attr($template_class); ?>">
                <div class="header">Pacific Association USA Track & Field</div>
                <h1 class="title"><?php echo esc_html($title); ?></h1>
                <div class="subtitle">This is to certify that</div>

                <div class="athlete-name"><?php echo esc_html($result['athlete_name']); ?></div>

                <div class="event-name"><?php echo esc_html($result['event_name']); ?></div>

                <div class="details">
                    <?php if ($result['time_display']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Finish Time</div>
                            <div class="detail-value"><?php echo esc_html($result['time_display']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($result['place']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Overall Place</div>
                            <div class="detail-value"><?php echo esc_html($result['place']); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($result['division_place']): ?>
                        <div class="detail-item">
                            <div class="detail-label"><?php echo esc_html($result['division']); ?> Place</div>
                            <div class="detail-value"><?php echo esc_html($result['division_place']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="footer">
                    <div class="signature">
                        <div class="signature-line">Association President</div>
                    </div>
                    <div class="seal">PA-USATF<br>Official</div>
                    <div class="date">
                        <?php echo esc_html($meta['event_location']); ?><br>
                        <?php echo esc_html(date('F j, Y', strtotime($meta['event_date']))); ?>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate social share card image
     *
     * @param int $result_id Result ID
     * @param string $platform Social platform (instagram, twitter, facebook)
     * @return string|false Image path or false
     */
    public function generate_share_card(int $result_id, string $platform = 'instagram'): string|false {
        $result = $this->load_result($result_id);
        if (!$result || $result['event_status'] !== 'publish') {
            return false;
        }

        $version = $this->row_version($result);

        // Reuse a cached card if present, mirroring generate_certificate.
        $cached = $this->cached_path($this->artifact_filename('share-card', $result_id, $platform, $version, 'png'));
        if ($cached !== null) {
            return $cached;
        }

        // Get dimensions for platform (get_platform_dimensions allowlists via match)
        $dimensions = $this->get_platform_dimensions($platform);

        // Get event details
        $event_date = get_post_meta($result['event_id'], '_pausatf_event_date', true);

        // Create image using GD
        return $this->create_share_image($result, $dimensions, [
            'event_date' => $event_date,
            'platform' => $platform,
            'version' => $version,
        ]);
    }

    /**
     * Get dimensions for social platform
     */
    private function get_platform_dimensions(string $platform): array {
        return match ($platform) {
            'instagram' => ['width' => 1080, 'height' => 1080],
            'instagram_story' => ['width' => 1080, 'height' => 1920],
            'twitter' => ['width' => 1200, 'height' => 675],
            'facebook' => ['width' => 1200, 'height' => 630],
            'linkedin' => ['width' => 1200, 'height' => 627],
            default => ['width' => 1200, 'height' => 630],
        };
    }

    /**
     * Create share image using GD
     */
    private function create_share_image(array $result, array $dimensions, array $meta): string|false {
        $width = $dimensions['width'];
        $height = $dimensions['height'];

        // Create image
        $image = imagecreatetruecolor($width, $height);

        // Colors
        $bg_color = imagecolorallocate($image, 26, 54, 93); // Dark blue
        $accent_color = imagecolorallocate($image, 201, 162, 39); // Gold
        $white = imagecolorallocate($image, 255, 255, 255);
        $light_blue = imagecolorallocate($image, 74, 144, 226);

        // Fill background
        imagefill($image, 0, 0, $bg_color);

        // Draw accent bar
        imagefilledrectangle($image, 0, 0, $width, 10, $accent_color);
        imagefilledrectangle($image, 0, $height - 10, $width, $height, $accent_color);

        // Get font (use system font or bundled)
        $font_regular = $this->get_font_path('regular');
        $font_bold = $this->get_font_path('bold');

        // Draw text
        $center_x = $width / 2;

        // Event name
        $this->draw_centered_text($image, $result['event_name'], $font_bold, 48, $white, $center_x, 150);

        // Date
        $date_text = date('F j, Y', strtotime($meta['event_date']));
        $this->draw_centered_text($image, $date_text, $font_regular, 24, $light_blue, $center_x, 210);

        // Athlete name
        $this->draw_centered_text($image, $result['athlete_name'], $font_bold, 64, $white, $center_x, $height / 2 - 50);

        // Time (large)
        if ($result['time_display']) {
            $this->draw_centered_text($image, $result['time_display'], $font_bold, 96, $accent_color, $center_x, $height / 2 + 60);
        }

        // Place info
        $place_text = [];
        if ($result['place']) {
            $place_text[] = "Overall: #{$result['place']}";
        }
        if ($result['division_place'] && $result['division']) {
            $place_text[] = "{$result['division']}: #{$result['division_place']}";
        }
        if (!empty($place_text)) {
            $this->draw_centered_text($image, implode('  |  ', $place_text), $font_regular, 28, $white, $center_x, $height / 2 + 160);
        }

        // PA-USATF branding
        $this->draw_centered_text($image, 'PA-USATF Results', $font_regular, 20, $light_blue, $center_x, $height - 50);

        // Save image
        $upload_dir = wp_upload_dir();
        $filename = $this->artifact_filename('share-card', (int) $result['id'], (string) $meta['platform'], (string) ($meta['version'] ?? ''), 'png');
        $dir = $upload_dir['basedir'] . '/pausatf-share-cards/';
        $filepath = $dir . $filename;
        if (!$this->protect_dir($dir)) {
            imagedestroy($image);
            return false;
        }

        // Atomic write: build to a temp file, then rename into place, so an
        // interrupted render never leaves a corrupt cached artifact.
        $tmp = wp_tempnam($filename, $dir);
        if (!$tmp || !imagepng($image, $tmp, 9)) {
            imagedestroy($image);
            if ($tmp && file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return false;
        }
        imagedestroy($image);
        if (!@rename($tmp, $filepath)) {
            wp_delete_file($tmp);
            return false;
        }
        return $filepath;
    }

    /**
     * Draw centered text
     */
    private function draw_centered_text($image, string $text, string $font, int $size, $color, int $x, int $y): void {
        if (!file_exists($font)) {
            // Fallback to built-in font
            imagestring($image, 5, $x - (strlen($text) * 4), $y, $text, $color);
            return;
        }

        $bbox = imagettfbbox($size, 0, $font, $text);
        $text_width = abs($bbox[4] - $bbox[0]);
        $text_x = $x - ($text_width / 2);

        imagettftext($image, $size, 0, (int) $text_x, $y, $color, $font, $text);
    }

    /**
     * Get font path
     */
    private function get_font_path(string $type): string {
        $font_dir = PAUSATF_RESULTS_DIR . 'assets/fonts/';

        return match ($type) {
            'bold' => $font_dir . 'OpenSans-Bold.ttf',
            default => $font_dir . 'OpenSans-Regular.ttf',
        };
    }

    /**
     * Convert HTML to PDF
     */
    private function html_to_pdf(string $html, string $filename): string|false {
        $pdf_dir = wp_upload_dir()['basedir'] . '/pausatf-certificates/';
        if (!$this->protect_dir($pdf_dir)) {
            return false;
        }
        $filepath = $pdf_dir . $filename;

        // Try mPDF: render to a temp file, then atomically rename into place.
        if (class_exists('Mpdf\Mpdf')) {
            $tmp = wp_tempnam($filename, $pdf_dir);
            try {
                $mpdf = new \Mpdf\Mpdf(['orientation' => 'L', 'format' => 'Letter']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($tmp, 'F');
                if (filesize($tmp) > 0 && @rename($tmp, $filepath)) {
                    return $filepath;
                }
            } catch (\Exception $e) {
                error_log('PDF generation failed: ' . $e->getMessage());
            }
            if ($tmp && file_exists($tmp)) {
                wp_delete_file($tmp); // never leave a partial artifact
            }
        }

        // Fallback: HTML via the WP Filesystem API, also written atomically.
        $html_filepath = str_replace('.pdf', '.html', $filepath);
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return false;
        }
        $tmp = wp_tempnam(basename($html_filepath), $pdf_dir);
        if (!$tmp
            || !$wp_filesystem->put_contents($tmp, $html, FS_CHMOD_FILE)
            || !@rename($tmp, $html_filepath)) {
            if ($tmp && file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return false;
        }
        return $html_filepath;
    }

    /**
     * Make an artifact directory deny direct web access (Apache) and index
     * listing, so downloads only flow through the publish-gated, rate-limited
     * handler. Mirrors the protected-uploads pattern used by major plugins.
     */
    private function protect_dir(string $dir): bool {
        wp_mkdir_p($dir);
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return false; // fail closed: no generation without a writable guard
        }
        if (!file_exists($dir . '.htaccess')) {
            $wp_filesystem->put_contents($dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE);
            $wp_filesystem->put_contents($dir . 'web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n", FS_CHMOD_FILE);
            $wp_filesystem->put_contents($dir . 'index.php', "<?php // Silence is golden.\n", FS_CHMOD_FILE);
        }
        // Fail closed if the Apache deny guard is not actually in place.
        if (!file_exists($dir . '.htaccess')) {
            return false;
        }
        // Unpredictable HMAC filenames are the primary defense; on nginx the
        // deny files are inert, so document the server block in a README.
        if (!file_exists($dir . 'nginx.conf.example')) {
            $wp_filesystem->put_contents(
                $dir . 'nginx.conf.example',
                "# Add to the server block to block direct access on nginx:\n"
                . "location ~* /pausatf-(certificates|share-cards)/ { deny all; return 404; }\n",
                FS_CHMOD_FILE
            );
        }
        return true;
    }

    /**
     * Purge cached artifacts for an event's results when it leaves 'publish',
     * so nothing lingers on disk after the live gate would deny it.
     */
    public function purge_on_unpublish(string $new_status, string $old_status, \WP_Post $post): void {
        if ($old_status !== 'publish' || $new_status === 'publish') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE event_id = %d", $post->ID));
        if (!$ids) {
            return;
        }
        $base = wp_upload_dir()['basedir'];
        foreach (['/pausatf-certificates/', '/pausatf-share-cards/'] as $sub) {
            foreach ((array) glob($base . $sub . '*') as $file) {
                foreach ($ids as $id) {
                    if (preg_match("~-{$id}[-.]~", basename($file))) {
                        wp_delete_file($file);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Get share URL for result
     */
    public function get_share_url(int $result_id, string $platform): string {
        $result_url = add_query_arg([
            'result' => $result_id,
        ], home_url('/results/'));

        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM {$wpdb->prefix}pausatf_results r
             INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.id = %d",
            $result_id
        ));

        if (!$result) {
            return '';
        }

        $text = sprintf(
            "I finished %s in %s! %s #PAUSATF",
            $result->event_name,
            $result->time_display,
            $result_url
        );

        return match ($platform) {
            'twitter' => 'https://twitter.com/intent/tweet?' . http_build_query(['text' => $text]),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?' . http_build_query(['u' => $result_url]),
            'linkedin' => 'https://www.linkedin.com/shareArticle?' . http_build_query([
                'mini' => 'true',
                'url' => $result_url,
                'title' => $result->event_name . ' Results',
            ]),
            default => $result_url,
        };
    }

    /**
     * Build an unpredictable artifact filename. The HMAC (keyed by a site
     * secret) makes direct-URL enumeration infeasible regardless of web
     * server, so the publish gate and rate limit cannot be bypassed by
     * guessing sequential ids.
     */
    private function artifact_filename(string $kind, int $result_id, string $variant, string $version, string $ext): string {
        $variant = sanitize_key($variant);
        // Version (result updated_at) is part of the signed key, so corrected
        // results produce a new filename and never serve a stale artifact.
        $sig = substr(hash_hmac('sha256', "{$kind}-{$result_id}-{$variant}-{$version}", wp_salt('auth')), 0, 32);
        return "{$kind}-{$result_id}-{$variant}-{$sig}.{$ext}";
    }

    /**
     * Load a result row joined to its event, or null. The single authoritative
     * loader so the publish gate and the cache version always agree.
     */
    private function load_result(int $result_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_status AS event_status, p.post_title AS event_name
             FROM {$table} r INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.id = %d",
            $result_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Content-hash cache version: schema-independent, so any correction to the
     * result (or event) yields a new filename and orphans the stale artifact.
     * Both the handler pre-check and the generators derive it from the same row.
     */
    private function row_version(array $row): string {
        return substr(md5((string) wp_json_encode($row)), 0, 16);
    }

    /**
     * Return an existing cached artifact path, or null if it must be generated.
     * Keeps enumeration cheap: repeat requests never re-run PDF/image builds.
     */
    private function cached_path(string $filename): ?string {
        $base = wp_upload_dir()['basedir'];
        // Route to the directory the artifact is actually written to.
        $dir = str_starts_with($filename, 'share-card-')
            ? $base . '/pausatf-share-cards/'
            : $base . '/pausatf-certificates/';
        foreach ([$filename, str_replace('.pdf', '.html', $filename)] as $candidate) {
            $path = $dir . basename($candidate);
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Cap first-time generation per client so an enumeration of result_ids
     * cannot force unbounded PDF/image builds (cache hits are not limited).
     */
    private function generation_allowed(): bool {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $key = 'pausatf_cert_gen_' . hash('sha256', $ip);

        // Atomic increment when a persistent object cache is present (prod runs
        // Redis), so concurrent requests cannot slip past the cap.
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, 'pausatf', 10 * MINUTE_IN_SECONDS);
            $count = wp_cache_incr($key, 1, 'pausatf');
            return is_int($count) && $count <= 20;
        }

        // Fallback: transient check-then-set. A small race is acceptable for a
        // DoS guard on an otherwise cached, publish-gated endpoint.
        $count = (int) get_transient($key);
        if ($count >= 20) {
            return false;
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }


    private function serve_file(string $filepath, string $disposition): void {
        // Confine to the plugin's own artifact directories: never an arbitrary path.
        $base = wp_upload_dir()['basedir'];
        $allowed = [
            realpath($base . '/pausatf-certificates'),
            realpath($base . '/pausatf-share-cards'),
        ];
        $real_file = realpath($filepath);
        $inside = $real_file !== false && array_filter(
            $allowed,
            static fn($dir) => $dir !== false && str_starts_with($real_file, $dir . DIRECTORY_SEPARATOR)
        );
        if (!$inside) {
            wp_die('Not found', '', ['response' => 404]);
        }
        $extension = pathinfo($real_file, PATHINFO_EXTENSION);
        $type = match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'text/html',
        };
        nocache_headers();
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($real_file) . '"');
        header('Content-Length: ' . (string) filesize($real_file));
        header('X-Content-Type-Options: nosniff');
        readfile($real_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a confined binary download; WP has no core streaming helper
        exit;
    }

    /**
     * AJAX handler for certificate generation. Public by design (shareable),
     * but hardened: published results only, cached, rate-limited on miss.
     */
    public function ajax_generate_certificate(): void {
        $result_id = (int) ($_GET['result_id'] ?? 0);
        $template = sanitize_key((string) ($_GET['template'] ?? 'finisher'));
        if (!isset(self::TEMPLATES[$template])) {
            $template = 'finisher';
        }
        $result = $result_id ? $this->load_result($result_id) : null;
        if (!$result || $result['event_status'] !== 'publish') {
            wp_die('Not found', '', ['response' => 404]);
        }
        $version = $this->row_version($result);

        $cached = $this->cached_path($this->artifact_filename('certificate', $result_id, $template, $version, 'pdf'));
        if ($cached === null && !$this->generation_allowed()) {
            wp_die('Too many requests. Please try again shortly.', '', ['response' => 429]);
        }

        $filepath = $cached ?? $this->generate_certificate($result_id, $template);
        if (!$filepath || !file_exists($filepath)) {
            wp_die('Not found', '', ['response' => 404]);
        }
        $this->serve_file($filepath, 'attachment');
    }

    /**
     * AJAX handler for share card generation. Same hardening as certificates.
     */
    public function ajax_generate_share_card(): void {
        $result_id = (int) ($_GET['result_id'] ?? 0);
        $platform = sanitize_key((string) ($_GET['platform'] ?? 'instagram'));
        $known = ['instagram', 'instagram_story', 'twitter', 'facebook', 'linkedin'];
        if (!in_array($platform, $known, true)) {
            $platform = 'instagram';
        }
        $result = $result_id ? $this->load_result($result_id) : null;
        if (!$result || $result['event_status'] !== 'publish') {
            wp_die('Not found', '', ['response' => 404]);
        }
        $version = $this->row_version($result);

        // Serve the cached card if present; only rate-limit real generation.
        $cached = $this->cached_path($this->artifact_filename('share-card', $result_id, $platform, $version, 'png'));
        if ($cached === null && !$this->generation_allowed()) {
            wp_die('Too many requests. Please try again shortly.', '', ['response' => 429]);
        }

        $filepath = $cached ?? $this->generate_share_card($result_id, $platform);
        if (!$filepath || !file_exists($filepath)) {
            wp_die('Not found', '', ['response' => 404]);
        }
        $this->serve_file($filepath, 'inline');
    }

    /**
     * Shortcode for certificate download button
     */
    public function shortcode_download(array $atts): string {
        $atts = shortcode_atts([
            'result_id' => 0,
            'template' => 'finisher',
            'text' => 'Download Certificate',
        ], $atts);

        if (!$atts['result_id']) {
            return '';
        }

        $url = add_query_arg([
            'action' => 'pausatf_generate_certificate',
            'result_id' => $atts['result_id'],
            'template' => $atts['template'],
        ], admin_url('admin-ajax.php'));

        return sprintf(
            '<a href="%s" class="pausatf-certificate-btn" target="_blank">%s</a>',
            esc_url($url),
            esc_html($atts['text'])
        );
    }

    /**
     * Shortcode for share buttons
     */
    public function shortcode_share(array $atts): string {
        $atts = shortcode_atts([
            'result_id' => 0,
        ], $atts);

        if (!$atts['result_id']) {
            return '';
        }

        $share_card_url = add_query_arg([
            'action' => 'pausatf_generate_share_card',
            'result_id' => $atts['result_id'],
            'platform' => 'instagram',
        ], admin_url('admin-ajax.php'));

        ob_start();
        ?>
        <div class="pausatf-share-buttons">
            <a href="<?php echo esc_url($this->get_share_url($atts['result_id'], 'twitter')); ?>"
               class="share-btn twitter" target="_blank" rel="noopener">
                Share on Twitter
            </a>
            <a href="<?php echo esc_url($this->get_share_url($atts['result_id'], 'facebook')); ?>"
               class="share-btn facebook" target="_blank" rel="noopener">
                Share on Facebook
            </a>
            <a href="<?php echo esc_url($share_card_url); ?>"
               class="share-btn instagram" download>
                Download for Instagram
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
new Certificates();
