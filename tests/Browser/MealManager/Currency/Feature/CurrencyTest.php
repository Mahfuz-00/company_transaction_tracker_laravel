<?php

namespace Tests\Browser\MealManager\Currency\Feature;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → CURRENCY (VIEW-ONLY).
 *
 * Route: GET /settings/currency (`settings.currency`, `permission:currency.view`).
 * The Meal Manager holds `currency.view` but NOT `currency.manage`, so they can
 * read the workspace currency format but the POST is refused
 * (SettingsController::store re-checks isInstitutionAdmin()).
 */
class CurrencyTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_can_view_the_currency_settings(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Currency', 'visit /settings/currency', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/settings/currency')
                ->assertPathIs('/settings/currency');
        });
    }

    public function test_meal_manager_cannot_change_the_currency(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Currency', 'POST is forbidden (no manage permission)', __LINE__);

        $this->httpAs($manager)
            ->post('/settings/currency', ['symbol' => '$'])
            ->assertForbidden();
    }
}
