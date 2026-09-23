<?php

namespace Tests\Feature\Api\Tenancy;

use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

/**
 * MULTI-TENANT ISOLATION at the mobile API boundary.
 *
 * The whole product promise is that one institution can never see or touch
 * another's data. These tests prove the boundary holds for BOTH the read path
 * (route-model binding + global scope) and the write path (tenant-scoped
 * `exists` validation added in the ApiFormRequest classes):
 *
 *   - reading a foreign member          -> 404 (cannot bind)
 *   - depositing for a foreign member   -> 422 (scoped exists) - the write hole
 *   - meal grid naming a foreign member -> 422 (the previously exploitable hole)
 *   - member in a foreign department    -> 422 (scoped exists)
 */
class ApiTenantIsolationTest extends ApiTestCase
{
    public function test_a_token_cannot_read_another_institutions_member(): void
    {
        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northStaff = $this->makeStaff($north, ['email' => 'admin@north.test']);
        $southMember = $this->makeMember($south, ['name' => 'South Member']);

        Sanctum::actingAs($northStaff);

        // The north token's tenant scope cannot bind the south row -> 404.
        $this->getJson('/api/members/' . $southMember->id)->assertNotFound();
    }

    public function test_listing_members_never_leaks_another_institution(): void
    {
        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northStaff = $this->makeStaff($north, ['email' => 'admin@north.test']);
        $this->makeMember($north, ['name' => 'North Member']);
        $this->makeMember($south, ['name' => 'South Member']);

        Sanctum::actingAs($northStaff);

        $names = collect($this->getJson('/api/members')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('North Member'));
        $this->assertFalse($names->contains('South Member'));
    }

    public function test_recording_a_deposit_for_a_foreign_member_is_rejected(): void
    {
        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northStaff = $this->makeStaff($north, ['email' => 'admin@north.test']);
        $southMember = $this->makeMember($south);

        Sanctum::actingAs($northStaff);

        $this->postJson('/api/deposits', [
            'student_id' => $southMember->id,
            'amount' => 120,
        ])->assertStatus(422)->assertJsonValidationErrors('student_id');

        // Nothing was written.
        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_the_meal_day_grid_rejects_a_foreign_member(): void
    {
        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northStaff = $this->makeStaff($north, ['email' => 'admin@north.test']);
        $southMember = $this->makeMember($south);

        Sanctum::actingAs($northStaff);

        // This is the hole that used to let a foreign student_id be written.
        $this->postJson('/api/meals/day', [
            'date' => now()->toDateString(),
            'entries' => [
                ['student_id' => $southMember->id, 'lunch' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('entries.0.student_id');

        $this->assertDatabaseCount('meal_entries', 0);
    }

    public function test_creating_a_member_in_a_foreign_department_is_rejected(): void
    {
        $north = $this->makeInstitution('North Dorm');
        $south = $this->makeInstitution('South Mess');

        $northStaff = $this->makeStaff($north, ['email' => 'admin@north.test']);
        $southDepartment = $this->makeDepartment($south);

        Sanctum::actingAs($northStaff);

        $this->postJson('/api/members', [
            'name' => 'Sneaky',
            'status' => 'active',
            'department_id' => $southDepartment->id,
        ])->assertStatus(422)->assertJsonValidationErrors('department_id');
    }
}
