<?php
/**
 * Bootstrap file for Codeception tests
 */

// Load environment variables
$dotenv_file = __DIR__ . '/../.env';
if (file_exists($dotenv_file)) {
    $lines = file($dotenv_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        putenv($line);
    }
}

// Set default test environment variables
$defaults = [
    'WP_URL' => 'http://wordpress',
    'WP_DOMAIN' => 'wordpress',
    'WP_ADMIN_USERNAME' => 'admin',
    'WP_ADMIN_PASSWORD' => 'admin',
    'DB_HOST' => 'db',
    'DB_NAME' => 'wordpress',
    'DB_USER' => 'wordpress',
    'DB_PASSWORD' => 'wordpress',
];

foreach ($defaults as $key => $value) {
    if (!getenv($key)) {
        putenv("$key=$value");
    }
}
