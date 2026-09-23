<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
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

    /** A staff login bound to an institution (the API only needs auth, not a role). */
    protected function makeStaff(Institution $institution, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'institution_id' => $institution->id,
            'name' => 'Institute Admin',
            'email' => 'admin@' . $institution->id . '.test',
            'password' => 'password',
            'status' => 'active',
            'must_change_password' => false,
            'setup_completed_at' => now(),
        ], $attributes));
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
