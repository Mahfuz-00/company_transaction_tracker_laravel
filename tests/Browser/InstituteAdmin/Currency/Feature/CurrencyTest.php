<?php

namespace Tests\Browser\InstituteAdmin\Currency\Feature;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → CURRENCY MANAGER.
 *
 * Routes: GET /settings/currency (`settings.currency`, `permission:currency.view`)
 * and POST /settings/currency (`settings.currency.store`,
 * `permission:currency.manage`). SettingsController::store writes the format onto
 * the ACTIVE institution's `currency_settings` JSON.
 *
 * UI: resources/js/Pages/Settings/CurrencyManager.jsx -> heading
 * "Currency & Formatting", inputs #symbol, #decimal_precision, "Save Settings".
 */
class CurrencyTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_configures_the_workspace_currency(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution([
            'name' => 'North South University Dorm',
            'currency_settings' => null,
            'currency_code' => null,
        ]);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Currency', 'open Currency Manager', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/currency')
                ->waitForText('Currency & Formatting', 20);

            $this->step('InstituteAdmin', 'Currency', 'set symbol + precision, save', __LINE__);

            $browser->clear('#symbol')
                ->type('#symbol', '$')
                ->clear('#decimal_precision')
                ->type('#decimal_precision', '3')
                ->press('Save Settings')
                ->waitForText('Currency settings saved for this institution.', 20);
        });

        $this->step('InstituteAdmin', 'Currency', 'assert format persisted per institution', __LINE__);

        $settings = $institution->fresh()->currencySettings();
        $this->assertSame('$', $settings['symbol']);
        $this->assertSame(3, (int) $settings['decimal_precision']);
    }

    public function test_a_member_cannot_change_the_workspace_currency(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('InstituteAdmin', 'Currency', 'assert member is forbidden', __LINE__);

        // `currency.view` / `currency.manage` are not held by a Member.
        $this->httpAs($member)->get('/settings/currency')->assertForbidden();
        $this->httpAs($member)->post('/settings/currency', ['symbol' => '$'])->assertForbidden();
    }
}
