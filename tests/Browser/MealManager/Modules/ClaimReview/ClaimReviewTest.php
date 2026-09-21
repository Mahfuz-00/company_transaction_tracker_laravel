<?php

namespace Tests\Browser\MealManager\Modules\ClaimReview;

use App\Models\Claim;
use App\Models\Student;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEAL MANAGER → MODULES → CLAIM REVIEW (ASSIGNMENT-SCOPED).
 *
 * Route: GET /claims/review (`claims.review`, `permission:claims.review`).
 *
 * ClaimController::review narrows claims via User::scopedStudentIds(), and
 * ClaimController::canReview() refuses a Meal Manager acting on any claim that is
 * not from a member assigned to them ("You cannot review a claim from another
 * institution." / manager-scope guard).
 */
class ClaimReviewTest extends DuskTestCase
{
    use DuskSupport;

    public function test_meal_manager_approves_a_claim_from_an_assigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $member = Student::create([
            'institution_id' => $institution->id,
            'name' => 'My Member',
            'roll' => 'NSU-9401',
            'status' => 'active',
            'manager_id' => $manager->id,
        ]);

        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'kind' => 'dispute', 'subject' => 'deposit', 'amount' => 400,
            'title' => 'Missing deposit', 'status' => 'pending',
        ]);

        $this->step('MealManager', 'ClaimReview', 'PATCH approve an assigned claim', __LINE__);

        $this->httpAs($manager)
            ->patch("/claims/{$claim->id}/approve", ['approved_amount' => 400])
            ->assertSessionHas('success');

        $this->assertSame('approved', $claim->fresh()->status);
    }

    public function test_meal_manager_cannot_review_a_claim_from_an_unassigned_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);
        $otherManager = $this->makeMealManager($institution, ['email' => 'other@example.test']);

        $foreignMember = Student::create([
            'institution_id' => $institution->id,
            // students.name is NOT NULL: the factory/test MUST supply it.
            'name' => 'Foreign Member',
            'roll' => 'NSU-9402',
            'status' => 'active',
            'manager_id' => $otherManager->id,
        ]);

        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $foreignMember->id,
            'kind' => 'dispute', 'subject' => 'deposit', 'amount' => 400,
            'title' => 'Foreign claim', 'status' => 'pending',
        ]);

        $this->step('MealManager', 'ClaimReview', 'approve refused for a foreign claim', __LINE__);

        $this->httpAs($manager)
            ->patch("/claims/{$claim->id}/approve")
            ->assertSessionHas('error');

        $this->assertSame('pending', $claim->fresh()->status);
    }
}
