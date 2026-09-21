<?php

namespace Tests\Browser\MealManager\Modules\Reports;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → MEAL REPORTS.
 *
 * Route: GET /meals/reports (`meals.reports.index`, `permission:meals.reports`).
 * The Meal Manager role holds meals.reports, so they can read the month summary
 * that FinanceCalculator produces for the active institution.
 */
class ReportsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_opens_the_meal_report(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Reports', 'visit /meals/reports', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/reports')
                ->assertPathIs('/meals/reports');
        });
    }

    public function test_meal_manager_can_export_the_report(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Reports', 'GET the report export', __LINE__);

        // `exports.download` is held by the Meal Manager role too.
        $this->httpAs($manager)
            ->get('/meals/reports/export?format=excel')
            ->assertOk();
    }
}
