<?php
/**
 * PHPUnit bootstrap file
 */

// Define test constants.
defined('PAUSATF_TESTING') || define('PAUSATF_TESTING', true);
defined('ABSPATH') || define('ABSPATH', '/tmp/wordpress/');

// Mock WordPress functions for unit testing
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

if (!function_exists('add_shortcode')) {
    function add_shortcode(...$args): void {
    }
}

if (!function_exists('apply_filters')) {
    // Tests set $GLOBALS['pausatf_test_filters'][$hook] to a callable to force a filter result.
    function apply_filters(string $hook, $value, ...$args) {
        $callback = $GLOBALS['pausatf_test_filters'][$hook] ?? null;
        return $callback === null ? $value : $callback($value, ...$args);
    }
}

if (!function_exists('add_action')) {
    // Records listeners in $GLOBALS['pausatf_test_hooks'][$hook]; nothing dispatches them unless a test does.
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
        $GLOBALS['pausatf_test_hooks'][$hook][] = [$callback, $accepted_args];
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) {
        return $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim(strip_tags($text));
    }
}

// Autoload plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'PAUSATF\\Results\\';
    $base_dir = dirname(__DIR__) . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load parser interface
require_once dirname(__DIR__) . '/includes/parsers/interface-parser.php';
