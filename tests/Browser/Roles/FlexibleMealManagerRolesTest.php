<?php

namespace Tests\Browser\Roles;

use App\Models\Institution;
use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 17: Flexible Meal Manager Roles & Self-Service Roles.
 *
 * Verifies:
 * - Each institute admin can configure how many meal manager roles exist within their institution.
 * - Admins can assign themselves as meal managers.
 * - Both admins and meal managers can act as regular meal members themselves (dual-role self-service).
 */
class FlexibleMealManagerRolesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_admin_configures_meal_manager_role_seat_limit(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['meal_manager_roles' => 3]);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('IA', 'Roles', 'configure meal manager seats to 5', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/institution')
                ->waitFor('[data-testid="meal-manager-roles-input"]', 20)
                ->clear('[data-testid="meal-manager-roles-input"]')
                ->type('[data-testid="meal-manager-roles-input"]', '5')
                ->press('Save Institution Settings')
                ->waitForText('Institution settings saved', 20);
        });

        $this->assertSame(5, (int) $institution->fresh()->meal_manager_roles);
    }

    public function test_admins_and_meal_managers_can_act_as_regular_meal_members(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // Admin assigns themselves the Meal Manager and Member roles
        $admin->assignRole('Meal Manager');
        $admin->assignRole('Member');

        $this->assertTrue($admin->hasRole('Institution Admin'));
        $this->assertTrue($admin->hasRole('Meal Manager'));
        $this->assertTrue($admin->hasRole('Member'));

        // Create student record so the admin/manager participates in meals
        $student = Student::create([
            'institution_id' => $institution->id,
            'user_id' => $admin->id,
            'name' => $admin->name,
            'roll' => 'ADMIN-MEMBER-01',
            'status' => 'active',
        ]);

        $this->step('IA', 'Roles', 'dual-role admin/manager can access personal meal dashboard', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/my/dashboard')
                ->waitForText('Your personal meal account', 20)
                ->assertSee('ADMIN-MEMBER-01');
        });
    }
}
