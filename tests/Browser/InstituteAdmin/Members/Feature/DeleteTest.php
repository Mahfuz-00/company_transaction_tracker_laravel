<?php

namespace Tests\Browser\InstituteAdmin\Members\Feature;

use App\Models\MealEntry;
use App\Models\Student;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEMBERS → DELETE.
 *
 * Route: DELETE /meals/students/{student} (`meals.students.destroy`), guarded by
 * `permission:students.manage`.
 *
 * StudentController::destroy refuses to delete a member with meal or deposit
 * history (financial history must survive), and deletes a clean member. Both
 * branches are covered.
 */
class DeleteTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_deletes_a_member_without_history(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $member = Student::create([
            'institution_id' => $institution->id,
            'name' => 'Removable Member',
            'roll' => 'NSU-5001',
            'status' => 'active',
        ]);

        $this->step('InstituteAdmin', 'Members', 'DELETE a clean member', __LINE__);

        $this->httpAs($admin)
            ->delete("/meals/students/{$member->id}")
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Members', 'assert the row is gone', __LINE__);

        $this->assertDatabaseMissing('students', ['id' => $member->id]);
    }

    public function test_a_member_with_meal_history_cannot_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $member = Student::create([
            'institution_id' => $institution->id,
            'name' => 'Locked Member',
            'roll' => 'NSU-5002',
            'status' => 'active',
        ]);

        // A meal entry gives the member financial history.
        MealEntry::create([
            'student_id' => $member->id,
            'date' => now()->toDateString(),
            'breakfast' => 1,
            'lunch' => 1,
            'dinner' => 0,
            'recorded_by' => $admin->id,
        ]);

        $this->step('InstituteAdmin', 'Members', 'DELETE is refused (history exists)', __LINE__);

        $this->httpAs($admin)
            ->delete("/meals/students/{$member->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('students', ['id' => $member->id]);
    }
}
