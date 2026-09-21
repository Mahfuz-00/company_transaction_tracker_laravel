<?php

namespace Tests\Feature\Institution;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEPARTMENT TENANCY GUARDS (HTTP layer).
 *
 * The departments module is tenant-scoped via route-model binding + the
 * BelongsToInstitution global scope. These guards are asserted here rather than
 * in a Dusk browser test because they are pure request/response behaviour:
 *
 *   - a department that still has members cannot be deleted (financial/roster
 *     history must survive);
 *   - a department belonging to ANOTHER institution is not even routable by an
 *     Institution Admin in a different workspace (404, not 200/403).
 */
class DepartmentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRbac(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeInstitution(string $name): Institution
    {
        return Institution::create([
            'name' => $name,
            'type' => 'university_dorm',
            'timezone' => 'UTC',
            'is_active' => true,
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
        ]);
    }

    protected function makeInstitutionAdmin(Institution $institution, string $email): User
    {
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Institution Admin',
            'email' => $email,
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ]);

        $user->assignRole('Institution Admin');

        return $user;
    }

    public function test_a_department_with_members_cannot_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution('North South University Dorm');
        $admin = $this->makeInstitutionAdmin($institution, 'admin@north.test');

        // Create the department through the real endpoint (tenant-stamped).
        $this->actingAs($admin)
            ->post('/meals/departments', ['name' => 'Occupied Team'])
            ->assertSessionHas('success');

        $department = Department::withoutGlobalScopes()->where('name', 'Occupied Team')->firstOrFail();

        // Give it a member so the guard must refuse.
        Student::create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'name' => 'A Member',
            'roll' => 'NSU-7001',
            'status' => 'active',
        ]);

        /*
         * Departments are addressed by SLUG (Department::getRouteKeyName() returns
         * 'slug'), so the URL must use the slug - passing the numeric id would
         * 404 the route binding.
         *
         * `from()` sets the previous URL so the controller's back() redirect
         * resolves to a real page and the flashed message survives the redirect.
         */
        $this->actingAs($admin)
            ->from('/meals/departments')
            ->delete("/meals/departments/{$department->slug}")
            ->assertSessionHas('error');

        // The row survived.
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_a_department_without_members_can_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution('North South University Dorm');
        $admin = $this->makeInstitutionAdmin($institution, 'admin@north.test');

        $this->actingAs($admin)
            ->from('/meals/departments')
            ->post('/meals/departments', ['name' => 'Empty Team'])
            ->assertSessionHas('success');

        $department = Department::withoutGlobalScopes()->where('name', 'Empty Team')->firstOrFail();

        // Tenant sanity: the created row belongs to the admin's institution.
        $this->assertSame($institution->id, $department->institution_id);

        // Addressed by SLUG (Department::getRouteKeyName() === 'slug').
        $this->actingAs($admin)
            ->from('/meals/departments')
            ->delete("/meals/departments/{$department->slug}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_an_admin_cannot_reach_another_institutions_department(): void
    {
        $this->seedRbac();

        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northAdmin = $this->makeInstitutionAdmin($north, 'admin@north.test');

        // A department owned by the SOUTH institution.
        $southDepartment = Department::create([
            'institution_id' => $south->id,
            'name' => 'South Kitchen',
            'slug' => 'south-kitchen',
        ]);

        // The NORTH admin's tenant scope cannot bind the SOUTH row -> 404.
        $this->actingAs($northAdmin)
            ->delete("/meals/departments/{$southDepartment->slug}")
            ->assertNotFound();

        // The south row is untouched.
        $this->assertDatabaseHas('departments', ['id' => $southDepartment->id]);
    }
}
