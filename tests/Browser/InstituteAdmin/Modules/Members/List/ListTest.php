<?php

namespace Tests\Browser\InstituteAdmin\Modules\Members\List;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → MEMBERS → LIST.
 *
 * Route: GET /meals/students (`meals.students.index`), guarded by
 * `permission:students.view`. StudentController::index scopes the roster to the
 * active institution (plus the manager's assigned ids where relevant).
 *
 * UI: resources/js/Pages/Meals/Students/Index.jsx -> the roster table.
 */
class ListTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_sees_only_their_institutions_members(): void
    {
        $this->seedRbac();

        $north = $this->makeInstitution(['name' => 'North South University Dorm']);
        $south = $this->makeInstitution(['name' => 'South College Mess']);
        $admin = $this->makeInstitutionAdmin($north);

        Student::create(['institution_id' => $north->id, 'name' => 'Rafiul Karim', 'roll' => 'NSU-2201', 'status' => 'active']);
        Student::create(['institution_id' => $south->id, 'name' => 'Cross Tenant Boarder', 'roll' => 'SCM-9999', 'status' => 'active']);

        $this->step('InstituteAdmin', 'Members', 'visit /meals/students', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/students')
                ->waitForText('Rafiul Karim', 20)
                ->assertSee('Rafiul Karim');

            $this->step('InstituteAdmin', 'Members', 'assert cross-tenant member is hidden', __LINE__);

            $browser->assertDontSee('Cross Tenant Boarder');
        });
    }
}
