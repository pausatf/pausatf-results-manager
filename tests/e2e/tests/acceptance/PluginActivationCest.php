<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use AcceptanceTester;

/**
 * Plugin Activation Tests
 *
 * Tests that the plugin activates correctly and basic admin functionality works
 */
class PluginActivationCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->loginToWordPress();
    }

    public function pluginIsActiveAndVisibleInAdmin(AcceptanceTester $I): void
    {
        $I->wantTo('verify the plugin is active and visible in admin');

        $I->amOnPage('/wp-admin/plugins.php');
        $I->see('PAUSATF Results Manager');
        $I->see('Deactivate', '.row-actions');
    }

    public function pluginMenuAppearsInAdminSidebar(AcceptanceTester $I): void
    {
        $I->wantTo('verify the plugin menu appears in admin sidebar');

        $I->amOnPage('/wp-admin/');
        $I->see('PAUSATF Results', '#adminmenu');
    }

    public function canAccessPluginDashboard(AcceptanceTester $I): void
    {
        $I->wantTo('access the plugin dashboard');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-results');
        $I->seeResponseCodeIs(200);
        $I->see('PAUSATF Results Manager', 'h1');
    }

    public function canAccessPluginSettingsPage(AcceptanceTester $I): void
    {
        $I->wantTo('access the plugin settings page');

        $I->amOnPage('/wp-admin/admin.php?page=pausatf-settings');
        $I->seeResponseCodeIs(200);
        $I->see('Settings', 'h1');
    }

    public function pluginRegistersCustomPostTypes(AcceptanceTester $I): void
    {
        $I->wantTo('verify custom post types are registered');

        $I->amOnPage('/wp-admin/edit.php?post_type=pausatf_event');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('/wp-admin/edit.php?post_type=pausatf_result');
        $I->seeResponseCodeIs(200);
    }
}
