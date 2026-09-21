<?php

namespace Tests\Browser\InstituteAdmin\Modules\Departments;

use App\Models\Department;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → DEPARTMENTS.
 *
 * Routes (Route::resource, mapped in routes/web.php):
 *   GET /meals/departments        (`meals.departments.index`,   departments.view)
 *   POST /meals/departments       (`meals.departments.store`,   departments.manage)
 *   PUT /meals/departments/{id}   (`meals.departments.update`,  departments.manage)
 *   DELETE /meals/departments/{id}(`meals.departments.destroy`, departments.manage)
 *
 * DepartmentController enforces per-institution uniqueness and refuses to delete
 * a department that still has members.
 */
class DepartmentsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_lists_departments(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        Department::create(['institution_id' => $institution->id, 'name' => 'Kitchen Team', 'slug' => 'kitchen-team']);

        $this->step('InstituteAdmin', 'Departments', 'visit /meals/departments', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/meals/departments')
                ->waitForText('Kitchen Team', 20);
        });
    }

    public function test_institute_admin_creates_a_department(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Departments', 'POST a new department', __LINE__);

        $this->httpAs($admin)
            ->post('/meals/departments', ['name' => 'Hall A'])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Departments', 'assert tenant-scoped row', __LINE__);

        $department = Department::where('name', 'Hall A')->first();
        $this->assertNotNull($department);
        $this->assertSame($institution->id, $department->institution_id);
    }

    /*
     * NOTE: the "a department with members cannot be deleted" business rule is
     * covered by tests/Feature/Institution/DepartmentGuardTest.php instead. A
     * route-model-binding DELETE that relies on tenant scope is far cleaner to
     * assert at the HTTP/Feature layer than through a Dusk browser session (where
     * a CSRF token + scoped binding make the same call fragile for no added value).
 */
}
