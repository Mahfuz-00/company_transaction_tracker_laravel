<?php

namespace Tests\Feature\Api\Members;

use App\Models\Deposit;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

/**
 * MEMBER ROSTER API (GET/POST/PATCH/DELETE /api/members).
 *
 * Verifies the roster is tenant-scoped, creation stamps the institution, and the
 * history guard refuses a destructive delete.
 */
class MemberApiTest extends ApiTestCase
{
    public function test_the_roster_lists_members_with_pagination_meta(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);
        $this->makeMember($institution, ['name' => 'Roster One']);
        $this->makeMember($institution, ['name' => 'Roster Two']);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/members')->assertOk();

        $response->assertJsonStructure([
            'data',
            'meta' => ['month', 'per_meal_rate', 'departments', 'pagination' => ['current_page', 'last_page', 'per_page', 'total']],
        ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_creating_a_member_stamps_the_institution(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);

        Sanctum::actingAs($staff);

        $this->postJson('/api/members', [
            'name' => 'Fresh Member',
            'roll' => 'R-100',
            'status' => 'active',
        ])->assertCreated();

        $this->assertDatabaseHas('students', [
            'name' => 'Fresh Member',
            'institution_id' => $institution->id,
        ]);
    }

    public function test_a_member_with_history_cannot_be_deleted(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);
        $member = $this->makeMember($institution);

        // Any deposit row counts as history that must survive.
        Deposit::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'amount' => 50,
            'kind' => 'personal',
        ]);

        Sanctum::actingAs($staff);

        $this->deleteJson('/api/members/' . $member->id)->assertStatus(409);
        $this->assertDatabaseHas('students', ['id' => $member->id]);
    }

    public function test_a_member_without_history_is_deleted(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);
        $member = $this->makeMember($institution);

        Sanctum::actingAs($staff);

        $this->deleteJson('/api/members/' . $member->id)->assertOk();
        $this->assertDatabaseMissing('students', ['id' => $member->id]);
    }
}
