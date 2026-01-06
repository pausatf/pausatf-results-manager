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
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM {$table} r
             INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.id = %d",
            $result_id
        ), ARRAY_A);

        if (!$result) {
            return false;
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
        return $this->html_to_pdf($html, "certificate-{$result_id}.pdf");
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
        global $wpdb;
        $table = $wpdb->prefix . 'pausatf_results';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_title as event_name
             FROM {$table} r
             INNER JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.id = %d",
            $result_id
        ), ARRAY_A);

        if (!$result) {
            return false;
        }

        // Get dimensions for platform
        $dimensions = $this->get_platform_dimensions($platform);

        // Get event details
        $event_date = get_post_meta($result['event_id'], '_pausatf_event_date', true);

        // Create image using GD
        return $this->create_share_image($result, $dimensions, [
            'event_date' => $event_date,
            'platform' => $platform,
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
        $filename = "share-card-{$result['id']}-{$meta['platform']}.png";
        $filepath = $upload_dir['basedir'] . '/pausatf-share-cards/' . $filename;

        // Ensure directory exists
        wp_mkdir_p(dirname($filepath));

        // Save PNG
        imagepng($image, $filepath, 9);
        imagedestroy($image);

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
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/pausatf-certificates/';
        wp_mkdir_p($pdf_dir);

        $filepath = $pdf_dir . $filename;

        // Try to use mPDF if available
        if (class_exists('Mpdf\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf([
                    'orientation' => 'L',
                    'format' => 'Letter',
                ]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($filepath, 'F');
                return $filepath;
            } catch (\Exception $e) {
                error_log('PDF generation failed: ' . $e->getMessage());
            }
        }

        // Fallback: save as HTML
        $html_filepath = str_replace('.pdf', '.html', $filepath);
        file_put_contents($html_filepath, $html);
        return $html_filepath;
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
     * AJAX handler for certificate generation
     */
    public function ajax_generate_certificate(): void {
        $result_id = (int) ($_GET['result_id'] ?? 0);
        $template = sanitize_text_field($_GET['template'] ?? 'finisher');

        if (!$result_id) {
            wp_die('Invalid result ID');
        }

        $filepath = $this->generate_certificate($result_id, $template);

        if (!$filepath || !file_exists($filepath)) {
            wp_die('Certificate generation failed');
        }

        // Serve file
        $filename = basename($filepath);
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);

        header('Content-Type: ' . ($extension === 'pdf' ? 'application/pdf' : 'text/html'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
    }

    /**
     * AJAX handler for share card generation
     */
    public function ajax_generate_share_card(): void {
        $result_id = (int) ($_GET['result_id'] ?? 0);
        $platform = sanitize_text_field($_GET['platform'] ?? 'instagram');

        if (!$result_id) {
            wp_die('Invalid result ID');
        }

        $filepath = $this->generate_share_card($result_id, $platform);

        if (!$filepath || !file_exists($filepath)) {
            wp_die('Share card generation failed');
        }

        // Serve image
        header('Content-Type: image/png');
        header('Content-Disposition: inline; filename="share-' . $result_id . '.png"');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
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
