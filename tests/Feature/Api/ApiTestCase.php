<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the mobile-API Feature tests.
 *
 * Deliberately named `*TestCase.php` (not `*Test.php`) so PHPUnit does NOT try
 * to run it as a test class - the same convention the Dusk base class uses.
 *
 * These rows are created directly with the models rather than through HTTP, so
 * each test can stand up exactly the tenant(s) it needs and then assert the API
 * boundary in isolation.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed the RBAC tables for EVERY API test.
     *
     * The API enforces the web's permission matrix at the ROUTE level (a Member
     * cannot read the roster; a Meal Manager cannot manage the institution).
     * Without seeded roles, `makeStaff()` could not assign one and every request
     * would be refused with a 403 — so tests would fail for a reason unrelated to
     * what they assert.
     *
     * Seeding here means each test runs against a realistic, permission-holding
     * user by default. A test that needs a specific role can assign it, and a test
     * that deliberately needs NONE can call `$user->syncRoles([])`.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A tenant with the fields the Institution model requires to be usable. */
    protected function makeInstitution(string $name = 'North South University Dorm'): Institution
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

    /**
     * A staff login bound to an institution.
     *
     * The Institution Admin role is assigned by default (when the RBAC tables
     * exist) because MOST API tests exercise a NORMAL staff workflow, and the API
     * now enforces the web's permission matrix at the route level — a roleless
     * user would be refused with a 403 before the behaviour under test is ever
     * reached.
     *
     * A test that needs a DIFFERENT role (or deliberately none) can either pass
     * its own role in the attributes and override it, or call
     * `syncRoles([])` on the returned user after seeding RBAC.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeStaff(Institution $institution, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Institute Admin',
            'email' => 'admin@' . $institution->id . '.test',
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ], $attributes));

        /*
         * Assign the default role ONLY when the guard can resolve it — i.e. when
         * RolesAndPermissionsSeeder has run. Some isolation tests deliberately do
         * not seed RBAC (they assert tenant scoping, not authorisation), and
         * assigning a non-existent role would throw.
         */
        $alreadyRoled = method_exists($user, 'getRoleNames') && $user->getRoleNames()->isNotEmpty();

        if (! $alreadyRoled && $this->rbacIsSeeded()) {
            $user->assignRole('Institution Admin');
        }

        return $user;
    }

    /**
     * Have the roles/permissions tables been populated in this test?
     *
     * Keyed on `permissions` (not `roles`), because the role rows are also created
     * by a migration — an empty permissions table is the reliable "not seeded"
     * signal, exactly as `db:seed-if-empty` reasons.
     */
    protected function rbacIsSeeded(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('permissions')
            && \Illuminate\Support\Facades\DB::table('permissions')->exists();
    }
    /** A member (internally a `students` row) belonging to an institution. */
    protected function makeMember(Institution $institution, array $attributes = []): Student
    {
        return Student::create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Member',
            'roll' => 'ROLL-' . random_int(1000, 9999),
            'status' => 'active',
        ], $attributes));
    }

    /** A department belonging to an institution (slug is required by the model). */
    protected function makeDepartment(Institution $institution, array $attributes = []): Department
    {
        $name = $attributes['name'] ?? 'Kitchen';

        return Department::create(array_merge([
            'institution_id' => $institution->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . random_int(100, 999),
        ], $attributes));
    }
}
