<?php
/**
 * PHPUnit bootstrap file
 */

// Define test constants
define('PAUSATF_TESTING', true);
define('ABSPATH', '/tmp/wordpress/');

// Mock WordPress functions for unit testing
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
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
