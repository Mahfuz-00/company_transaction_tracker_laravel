<?php

namespace Tests\Browser\MealManager\Modules\Vendors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → VENDORS.
 *
 * Routes (Route::resource, mapped in routes/web.php):
 *   GET  /meals/vendors  (`meals.vendors.index`, vendors.view)
 *   POST /meals/vendors  (`meals.vendors.store`, vendors.manage)
 *
 * The Meal Manager role holds `vendors.view` AND `vendors.manage`, so they can
 * add suppliers as well as read the list.
 */
class VendorsTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_meal_manager_adds_a_vendor(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Vendors', 'POST a vendor', __LINE__);

        $this->actingAs($manager)
            ->post('/meals/vendors', [
                'name' => 'Daily Milk Supplier',
                'status' => 'active',
                'category' => 'groceries',
            ])
            ->assertSessionHas('success');

        $this->step('MealManager', 'Vendors', 'assert vendor scoped to institution', __LINE__);

        $this->assertDatabaseHas('vendors', [
            'name' => 'Daily Milk Supplier',
            'institution_id' => $institution->id,
        ]);
    }

    public function test_meal_manager_sees_the_vendor_list(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->actingAs($manager)->post('/meals/vendors', [
            'name' => 'Rice Wholesaler', 'status' => 'active', 'category' => 'groceries',
        ]);

        $this->step('MealManager', 'Vendors', 'visit /meals/vendors', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/vendors')
                ->waitForText('Rice Wholesaler', 20);
        });
    }
}
