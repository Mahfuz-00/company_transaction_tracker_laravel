<?php

namespace Tests\Browser\InstituteAdmin\Modules\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEAL REPORTS.
 *
 * Routes:
 *   GET /meals/reports         (`meals.reports.index`, meals.reports)
 *   GET /meals/reports/export  (`meals.reports.export`, exports.download)
 *
 * MealReportController::index defaults strictly to the CURRENT MONTH (a Month
 * selector replaces the old from/to range) and computes figures through the
 * shared FinanceCalculator.
 */
class ReportsTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_opens_the_meal_report(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Reports', 'visit /meals/reports', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/reports')
                ->assertPathIs('/meals/reports');
        });
    }

    public function test_institute_admin_can_download_the_roster_export(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Reports', 'GET the members export', __LINE__);

        // exports.download permission lets the export stream a file response.
        $response = $this->actingAs($admin)->get('/meals/students-export?format=excel');

        $response->assertOk();
    }
}
