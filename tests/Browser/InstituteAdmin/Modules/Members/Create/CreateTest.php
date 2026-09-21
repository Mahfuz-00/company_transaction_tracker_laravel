<?php

namespace Tests\Browser\InstituteAdmin\Modules\Members\Create;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEMBERS → CREATE.
 *
 * Route: POST /meals/students (`meals.students.store`), guarded by
 * `permission:students.manage`. StudentController::store stamps the ACTIVE
 * institution's id, so a created member is always tenant-scoped.
 *
 * UI: the "Add Member" toolbar button opens MemberFormModal (form #member-form).
 * Field ids come from the shared Field component (id === name): #name, #roll,
 * #department_id, #join_date, #status, #manager_id.
 */
class CreateTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_adds_a_member_to_the_roster(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Members', 'open Add Member modal', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/students')
                ->waitForText('Members', 20)
                ->press('Add Member')
                ->waitFor('#member-form', 15);

            $this->step('InstituteAdmin', 'Members', 'fill and submit the form', __LINE__);

            $browser->type('#name', 'Sadia Islam')
                ->type('#roll', 'NSU-3300')
                // The modal footer submit is bound to the form by the `form` attr.
                ->click('button[form="member-form"]')
                ->waitForText('Sadia Islam added to the roster.', 20);
        });

        $this->step('InstituteAdmin', 'Members', 'assert member stamped with institution_id', __LINE__);

        $member = Student::where('roll', 'NSU-3300')->first();
        $this->assertNotNull($member);
        $this->assertSame($institution->id, $member->institution_id);
    }
}
