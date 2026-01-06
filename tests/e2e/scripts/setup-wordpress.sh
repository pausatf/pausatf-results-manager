#!/bin/bash
# WordPress E2E Test Setup Script
# Sets up WordPress with the PAUSATF Results Manager plugin for testing

set -e

echo "=== PAUSATF Results Manager E2E Test Setup ==="

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" &>/dev/null; do
    sleep 2
done
echo "MySQL is ready!"

# Wait for WordPress to be ready
echo "Waiting for WordPress..."
until curl -s http://wordpress:80 > /dev/null; do
    sleep 2
done
echo "WordPress is ready!"

# Install WordPress if not already installed
if ! wp core is-installed --path=/var/www/html --allow-root 2>/dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --path=/var/www/html \
        --url="$WP_URL" \
        --title="PAUSATF Test Site" \
        --admin_user="$WP_ADMIN_USERNAME" \
        --admin_password="$WP_ADMIN_PASSWORD" \
        --admin_email="admin@pausatf.test" \
        --skip-email \
        --allow-root
    echo "WordPress installed!"
else
    echo "WordPress already installed."
fi

# Configure WordPress for testing
echo "Configuring WordPress..."
wp option update permalink_structure '/%postname%/' --path=/var/www/html --allow-root
wp rewrite flush --path=/var/www/html --allow-root

# Enable debug mode
wp config set WP_DEBUG true --raw --path=/var/www/html --allow-root
wp config set WP_DEBUG_LOG true --raw --path=/var/www/html --allow-root
wp config set WP_DEBUG_DISPLAY false --raw --path=/var/www/html --allow-root
wp config set SCRIPT_DEBUG true --raw --path=/var/www/html --allow-root

# Activate the plugin
echo "Activating PAUSATF Results Manager plugin..."
if wp plugin is-installed pausatf-results-manager --path=/var/www/html --allow-root 2>/dev/null; then
    wp plugin activate pausatf-results-manager --path=/var/www/html --allow-root
    echo "Plugin activated!"
else
    echo "Warning: Plugin not found. Make sure it's mounted correctly."
fi

# Create test pages
echo "Creating test pages..."
wp post create --post_type=page --post_title='Results' --post_status=publish --post_name=results --path=/var/www/html --allow-root 2>/dev/null || true
wp post create --post_type=page --post_title='Events' --post_status=publish --post_name=events --path=/var/www/html --allow-root 2>/dev/null || true
wp post create --post_type=page --post_title='Athletes' --post_status=publish --post_name=athletes --path=/var/www/html --allow-root 2>/dev/null || true
wp post create --post_type=page --post_title='Records' --post_status=publish --post_name=records --path=/var/www/html --allow-root 2>/dev/null || true

# Set up REST API
echo "Enabling REST API..."
wp option update show_on_front page --path=/var/www/html --allow-root

# Create application password for API testing
echo "Setting up API authentication..."
wp eval "
\$user = get_user_by('login', 'admin');
if (\$user) {
    \$app_passwords = WP_Application_Passwords::get_user_application_passwords(\$user->ID);
    if (empty(\$app_passwords)) {
        \$result = WP_Application_Passwords::create_new_application_password(\$user->ID, array('name' => 'E2E Tests'));
        if (!is_wp_error(\$result)) {
            echo 'APP_PASSWORD=' . \$result[0];
        }
    }
}
" --path=/var/www/html --allow-root > /tmp/app_password.txt 2>/dev/null || true

# Create test data
echo "Creating test data..."
wp eval "
// Create test event
\$event_data = array(
    'event_name' => 'PA State Championships',
    'event_date' => date('Y-m-d', strtotime('+30 days')),
    'event_location' => 'Philadelphia, PA',
    'event_type' => 'championship',
    'sanction_number' => '24-TEST-001'
);
update_option('pausatf_test_event', \$event_data);

// Create test athlete
\$athlete_data = array(
    'first_name' => 'Test',
    'last_name' => 'Athlete',
    'club' => 'Philadelphia TC',
    'gender' => 'M',
    'age_group' => 'Open'
);
update_option('pausatf_test_athlete', \$athlete_data);

echo 'Test data created!';
" --path=/var/www/html --allow-root

# Enable all plugin features for testing
echo "Enabling all plugin features..."
wp eval "
\$features = array(
    'event_management' => true,
    'results_management' => true,
    'athlete_profiles' => true,
    'club_management' => true,
    'usatf_rules' => true,
    'records_management' => true,
    'rankings_system' => true,
    'performance_analytics' => true,
    'rest_api' => true,
    'rdf_support' => true,
    'import_export' => true
);
update_option('pausatf_features', \$features);
echo 'Features enabled!';
" --path=/var/www/html --allow-root

# Flush rewrite rules
wp rewrite flush --path=/var/www/html --allow-root

echo ""
echo "=== Setup Complete ==="
echo "WordPress URL: $WP_URL"
echo "Admin Username: $WP_ADMIN_USERNAME"
echo "Admin Password: $WP_ADMIN_PASSWORD"
echo ""
