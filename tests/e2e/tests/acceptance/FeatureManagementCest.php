<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use AcceptanceTester;

/** Verify feature settings use the currently registered controls. */
class FeatureManagementCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function featureControlsAreDisplayed(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-settings&tab=features');
        $I->see('PAUSATF Results Settings', 'h1');
        $I->seeElement('.pausatf-feature-grid');
        $I->see('Core Feature');
    }

    public function coreFeaturesCannotBeDisabled(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results-settings&tab=features');
        $I->seeElement('.pausatf-feature-card.core');
        $I->dontSeeElement('.pausatf-feature-card.core input[type="checkbox"]');
    }
}
