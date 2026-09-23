<?php

namespace Tests\Browser\MealManager\Members\Feature;

use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → MEMBERS (ASSIGNMENT-SCOPED ROSTER).
 *
 * Route: GET /meals/students (`meals.students.index`, `permission:students.view`).
 *
 * StudentController::index narrows the roster to the ids returned by
 * User::scopedStudentIds():
 *   - a Meal Manager -> ONLY members where students.manager_id = their id;
 *   - admins/SSA     -> every member in the institution (null = unrestricted).
 *
 * So a Meal Manager must NOT see a colleague's member, even in the same
 * institution.
 */
class MembersTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_sees_only_their_assigned_members(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);

        $manager = $this->makeMealManager($institution, ['email' => 'manager@north.test']);
        $otherManager = $this->makeMealManager($institution, ['email' => 'other@north.test']);

        // Assigned to THIS manager.
        Student::create([
            'institution_id' => $institution->id,
            'name' => 'My Assigned Member',
            'roll' => 'NSU-9101',
            'status' => 'active',
            'manager_id' => $manager->id,
        ]);

        // Assigned to a DIFFERENT manager - must stay hidden.
        Student::create([
            'institution_id' => $institution->id,
            'name' => 'Colleagues Member',
            'roll' => 'NSU-9102',
            'status' => 'active',
            'manager_id' => $otherManager->id,
        ]);

        $this->step('MealManager', 'Members', 'visit /meals/students', __LINE__);

        $this->browse(function (Browser $browser) use ($manager) {
            $browser->loginAs($manager)
                ->visit('/meals/students')
                ->waitForText('My Assigned Member', 20)
                ->assertSee('My Assigned Member');

            $this->step('MealManager', 'Members', 'assert colleague member is hidden', __LINE__);

            $browser->assertDontSee('Colleagues Member');
        });
    }
}
