<?php

namespace Tests\Browser\MealManager\Dashboard\Feature;

use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → DASHBOARD.
 *
 * Route: GET /dashboard (`dashboard`, DashboardController::index), tenant-scoped.
 *
 * A Meal Manager runs the day-to-day mess operations for the members ASSIGNED to
 * them (students.manager_id). Their operational modules (Members, Deposits,
 * Entries, Expenses, Vendors, Reports, Claim Review) are all scoped through
 * User::scopedStudentIds().
 */
class DashboardTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_dashboard_renders_within_their_workspace(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $manager = $this->makeMealManager($institution, ['email' => 'manager@north.test']);

        // A member overseen by this manager.
        Student::create([
            'institution_id' => $institution->id,
            'name' => 'Assigned Member',
            'roll' => 'NSU-9001',
            'status' => 'active',
            'manager_id' => $manager->id,
        ]);

        $this->step('MealManager', 'Dashboard', 'visit /dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/dashboard')
                ->waitForText('Dashboard', 20)
                ->assertPathIs('/dashboard');
        });
    }
}
