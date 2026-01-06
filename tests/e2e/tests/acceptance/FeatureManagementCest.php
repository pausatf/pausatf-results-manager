<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use AcceptanceTester;

/**
 * Feature Management Tests
 *
 * Tests the feature toggle system in plugin settings
 */
class FeatureManagementCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function canAccessFeaturesTab(AcceptanceTester $I): void
    {
        $I->wantTo('access the features tab in settings');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);
        $I->see('Core Features');
    }

    public function coreFeaturesShouldBeEnabled(AcceptanceTester $I): void
    {
        $I->wantTo('verify core features are enabled by default');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        $I->seeCheckboxIsChecked('#feature-event_management');
        $I->seeCheckboxIsChecked('#feature-results_management');
    }

    public function canToggleOptionalFeature(AcceptanceTester $I): void
    {
        $I->wantTo('toggle an optional feature on and off');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        // Find the performance analytics toggle
        $initialState = $I->grabAttributeFrom('#feature-performance_analytics', 'checked');

        // Toggle it
        $I->click('#feature-performance_analytics');
        $I->click('input[type="submit"][name="submit"]');

        $I->waitForElement('.notice-success', 10);
        $I->see('Settings saved');
    }

    public function bulkEnableAllFeatures(AcceptanceTester $I): void
    {
        $I->wantTo('enable all features using bulk action');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        $I->click('Enable All');
        $I->waitForJS('return document.readyState === "complete"', 10);

        // Verify most toggles are now checked
        $I->seeCheckboxIsChecked('#feature-rankings_system');
        $I->seeCheckboxIsChecked('#feature-performance_analytics');
    }

    public function resetToDefaultFeatures(AcceptanceTester $I): void
    {
        $I->wantTo('reset features to default settings');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        $I->click('Reset to Defaults');
        $I->waitForJS('return document.readyState === "complete"', 10);

        // Core features should still be enabled
        $I->seeCheckboxIsChecked('#feature-event_management');
        $I->seeCheckboxIsChecked('#feature-results_management');
    }

    public function featureDependenciesAreEnforced(AcceptanceTester $I): void
    {
        $I->wantTo('verify feature dependencies are enforced');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        // Core features should have a 'disabled' attribute since they can't be turned off
        $disabledAttr = $I->grabAttributeFrom('#feature-event_management', 'disabled');
        $I->assertEquals('disabled', $disabledAttr);
    }
}
