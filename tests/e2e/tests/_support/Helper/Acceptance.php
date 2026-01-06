<?php

declare(strict_types=1);

namespace Helper;

use Codeception\Module;

/**
 * Acceptance Test Helper
 *
 * Custom methods for acceptance tests
 */
class Acceptance extends Module
{
    /**
     * Login to WordPress admin
     */
    public function loginAsAdmin(): void
    {
        $I = $this->getModule('WebDriver');

        $I->amOnPage('/wp-login.php');
        $I->fillField('#user_login', getenv('WP_ADMIN_USERNAME') ?: 'admin');
        $I->fillField('#user_pass', getenv('WP_ADMIN_PASSWORD') ?: 'admin');
        $I->click('#wp-submit');
        $I->waitForElement('#wpadminbar', 10);
    }

    /**
     * Navigate to plugin settings page
     */
    public function goToPluginSettings(): void
    {
        $I = $this->getModule('WebDriver');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->waitForElement('.pausatf-settings-wrap', 10);
    }

    /**
     * Navigate to plugin results page
     */
    public function goToResultsPage(): void
    {
        $I = $this->getModule('WebDriver');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results');
        $I->waitForElement('.wrap', 10);
    }

    /**
     * Navigate to plugin events page
     */
    public function goToEventsPage(): void
    {
        $I = $this->getModule('WebDriver');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-events');
        $I->waitForElement('.wrap', 10);
    }

    /**
     * Navigate to SPARQL query page
     */
    public function goToSparqlPage(): void
    {
        $I = $this->getModule('WebDriver');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-semantic');
        $I->waitForElement('.pausatf-semantic-wrap', 10);
    }

    /**
     * Check if a plugin feature is enabled
     */
    public function seeFeatureEnabled(string $featureId): void
    {
        $I = $this->getModule('WebDriver');

        $this->goToPluginSettings();
        $I->click('a[href="#features"]');
        $I->waitForElement("#feature-{$featureId}", 5);
        $I->seeCheckboxIsChecked("#feature-{$featureId}");
    }

    /**
     * Enable a plugin feature
     */
    public function enableFeature(string $featureId): void
    {
        $I = $this->getModule('WebDriver');

        $this->goToPluginSettings();
        $I->click('a[href="#features"]');
        $I->waitForElement("#feature-{$featureId}", 5);

        if (!$I->grabAttributeFrom("#feature-{$featureId}", 'checked')) {
            $I->click("#feature-{$featureId}");
            $I->click('input[type="submit"]');
            $I->waitForElement('.notice-success', 10);
        }
    }

    /**
     * Disable a plugin feature
     */
    public function disableFeature(string $featureId): void
    {
        $I = $this->getModule('WebDriver');

        $this->goToPluginSettings();
        $I->click('a[href="#features"]');
        $I->waitForElement("#feature-{$featureId}", 5);

        if ($I->grabAttributeFrom("#feature-{$featureId}", 'checked')) {
            $I->click("#feature-{$featureId}");
            $I->click('input[type="submit"]');
            $I->waitForElement('.notice-success', 10);
        }
    }

    /**
     * Create an event via the admin
     */
    public function createEvent(array $eventData): void
    {
        $I = $this->getModule('WebDriver');

        $this->goToEventsPage();
        $I->click('Add New Event');
        $I->waitForElement('#event-form', 10);

        if (isset($eventData['name'])) {
            $I->fillField('#event_name', $eventData['name']);
        }
        if (isset($eventData['date'])) {
            $I->fillField('#event_date', $eventData['date']);
        }
        if (isset($eventData['location'])) {
            $I->fillField('#event_location', $eventData['location']);
        }

        $I->click('Save Event');
        $I->waitForElement('.notice-success', 10);
    }

    /**
     * Wait for page to fully load
     */
    public function waitForPageLoad(): void
    {
        $I = $this->getModule('WebDriver');

        $I->waitForJS('return document.readyState === "complete"', 30);
    }

    /**
     * Take a screenshot with timestamp
     */
    public function takeTimestampedScreenshot(string $name): void
    {
        $I = $this->getModule('WebDriver');

        $timestamp = date('Y-m-d_H-i-s');
        $I->makeScreenshot("{$name}_{$timestamp}");
    }
}
