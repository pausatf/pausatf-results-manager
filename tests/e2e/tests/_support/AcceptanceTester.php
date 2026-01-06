<?php

declare(strict_types=1);

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
*/
class AcceptanceTester extends \Codeception\Actor
{
    use _generated\AcceptanceTesterActions;

    /**
     * Custom login method
     */
    public function loginToWordPress(): void
    {
        $this->amOnPage('/wp-login.php');
        $this->fillField('#user_login', getenv('WP_ADMIN_USERNAME') ?: 'admin');
        $this->fillField('#user_pass', getenv('WP_ADMIN_PASSWORD') ?: 'admin');
        $this->click('#wp-submit');
        $this->waitForElement('#wpadminbar', 10);
    }

    /**
     * Verify plugin is active
     */
    public function seePluginIsActive(): void
    {
        $this->amOnPage('/wp-admin/plugins.php');
        $this->see('PAUSATF Results Manager');
        $this->see('Deactivate');
    }

    /**
     * Navigate to plugin menu
     */
    public function openPluginMenu(string $menuItem): void
    {
        $this->click('#toplevel_page_pausatf-results');
        $this->waitForElement('.wp-submenu', 5);

        if ($menuItem !== 'main') {
            $this->click($menuItem, '.wp-submenu');
        }
    }
}
