<?php

namespace Tests\Browser\MealManager\Modules\Subsidies;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → SUBSIDIES (VIEW-ONLY).
 *
 * Route: GET /meals/subsidies (`meals.subsidies.index`). The Meal Manager role
 * holds `subsidies.view` but NOT `subsidies.manage` (see
 * RolesAndPermissionsSeeder), so they can READ the subsidy ledger for their
 * workspace but the POST is refused.
 */
class SubsidiesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_can_view_subsidies(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Subsidies', 'visit /meals/subsidies', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/subsidies')
                ->assertPathIs('/meals/subsidies');
        });
    }

    public function test_meal_manager_cannot_record_a_subsidy(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Subsidies', 'POST is forbidden (no manage permission)', __LINE__);

        // subsidies.manage is an Institution-Admin/SSA permission.
        $this->httpAs($manager)
            ->post('/meals/subsidies', [
                'source' => 'authority', 'amount' => 1000, 'apply_mode' => 'pool',
            ])
            ->assertForbidden();
    }
}
