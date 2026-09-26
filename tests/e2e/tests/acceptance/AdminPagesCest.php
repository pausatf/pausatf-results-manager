<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use AcceptanceTester;

/** Verify the plugin's registered admin pages render for an administrator. */
class AdminPagesCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function dashboardLoads(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results');
        $I->see('PAUSATF Results Manager', 'h1');
    }

    public function importPageLoads(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-import');
        $I->see('Import Results', 'h1');
    }

    public function settingsPageLoads(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-settings');
        $I->see('PAUSATF Results Settings', 'h1');
    }

    public function semanticWebPageLoads(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-semantic');
        $I->see('Semantic Web (RDF/SPARQL)', 'h1');
    }

    public function settingsTabsNavigate(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-settings');
        $I->seeElement('.pausatf-feature-grid');

        foreach (['general', 'integrations', 'tools'] as $tab) {
            $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-settings&tab=' . $tab);
            $I->see('PAUSATF Results Settings', 'h1');
            $I->seeElement('.nav-tab-active');
        }
    }
}
