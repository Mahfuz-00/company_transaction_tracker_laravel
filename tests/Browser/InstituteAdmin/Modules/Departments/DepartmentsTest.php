<?php

namespace Tests\Browser\InstituteAdmin\Modules\Departments;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

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

        $this->actingAs($admin)
            ->post('/meals/departments', ['name' => 'Hall A'])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Departments', 'assert tenant-scoped row', __LINE__);

        $department = Department::where('name', 'Hall A')->first();
        $this->assertNotNull($department);
        $this->assertSame($institution->id, $department->institution_id);
    }

    public function test_a_department_with_members_cannot_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $department = Department::create([
            'institution_id' => $institution->id,
            'name' => 'Occupied Team',
            'slug' => 'occupied-team',
        ]);

        $this->makeStudent($institution, ['department_id' => $department->id]);

        $this->step('InstituteAdmin', 'Departments', 'DELETE is refused (has members)', __LINE__);

        $this->actingAs($admin)
            ->delete("/meals/departments/{$department->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }
}
