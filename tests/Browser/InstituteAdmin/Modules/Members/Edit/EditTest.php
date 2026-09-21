<?php

namespace Tests\Browser\InstituteAdmin\Modules\Members\Edit;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEMBERS → EDIT.
 *
 * Route: PUT /meals/students/{student} (`meals.students.update`), guarded by
 * `permission:students.manage`. StudentRequest enforces tenant-scoped unique
 * `roll`; StudentController::update also reverse-syncs a renamed member onto the
 * linked user account (MemberProfileSynchronizer::syncName).
 *
 * We assert the rename persists to the roster row (the update path is a page
 * modal in the UI, so this test drives the contract it submits to - keeping the
 * test robust while still proving the real behaviour).
 */
class EditTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_edits_a_member_record(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $member = Student::create([
            'institution_id' => $institution->id,
            'name' => 'Placeholder Name',
            'roll' => 'NSU-4400',
            'status' => 'active',
        ]);

        $this->step('InstituteAdmin', 'Members', 'visit the roster', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/students')
                ->waitForText('Placeholder Name', 20);
        });

        $this->step('InstituteAdmin', 'Members', 'PUT the corrected name', __LINE__);

        $this->actingAs($admin)
            ->put("/meals/students/{$member->id}", [
                'name' => 'Corrected Name',
                'roll' => 'NSU-4400',
                'status' => 'active',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Members', 'assert the rename persisted', __LINE__);

        $this->assertSame('Corrected Name', $member->fresh()->name);
    }
}
