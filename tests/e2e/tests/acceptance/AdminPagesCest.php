<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use AcceptanceTester;

/**
 * Admin Pages Tests
 *
 * Tests all plugin admin pages load correctly
 */
class AdminPagesCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function resultsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the results page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function eventsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the events page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-events');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function athletesPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the athletes page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-athletes');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function clubsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the clubs page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-clubs');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function recordsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the records page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-records');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function rankingsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the rankings page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-rankings');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function reportsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the reports page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-reports');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function importPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the import page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-import');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function settingsPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the settings page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function semanticWebPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the semantic web page loads correctly');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-semantic');
        $I->seeResponseCodeIs(200);
        $I->see('RDF');
        $I->dontSee('Error');
        $I->dontSee('Warning');
    }

    public function settingsTabsNavigate(AcceptanceTester $I): void
    {
        $I->wantTo('navigate between settings tabs');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');

        // Features tab
        $I->click('a[href="#features"]');
        $I->waitForElement('.feature-grid', 5);

        // General tab
        $I->click('a[href="#general"]');
        $I->waitForElement('#general', 5);

        // Integrations tab
        $I->click('a[href="#integrations"]');
        $I->waitForElement('#integrations', 5);

        // Tools tab
        $I->click('a[href="#tools"]');
        $I->waitForElement('#tools', 5);
    }
}
