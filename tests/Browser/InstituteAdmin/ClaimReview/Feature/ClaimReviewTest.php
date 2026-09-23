<?php

namespace Tests\Browser\InstituteAdmin\ClaimReview\Feature;

use App\Models\Claim;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → CLAIM REVIEW.
 *
 * Route: GET /claims/review (`claims.review`, `permission:claims.review`) and the
 * decision endpoints PATCH /claims/{claim}/approve|reject.
 *
 * ClaimController::review scopes claims to the active institution; approval is
 * the ONLY point money moves, and it happens transactionally.
 */
class ClaimReviewTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_sees_the_claim_review_queue(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['name' => 'Claiming Member', 'roll' => 'NSU-8001']);

        Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'kind' => 'dispute',
            'subject' => 'deposit',
            'amount' => 500,
            'title' => 'Missing deposit',
            'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'ClaimReview', 'visit /claims/review', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/claims/review')
                ->waitForText('Missing deposit', 20);
        });
    }

    public function test_institute_admin_approves_a_claim_and_credits_the_member(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-8002']);

        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'kind' => 'dispute',
            'subject' => 'deposit',
            'amount' => 750,
            'title' => 'Unrecorded deposit',
            'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'ClaimReview', 'PATCH approve', __LINE__);

        $this->httpAs($admin)
            ->patch("/claims/{$claim->id}/approve", ['approved_amount' => 750])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'ClaimReview', 'assert approved + a credit deposit exists', __LINE__);

        $claim->refresh();
        $this->assertSame('approved', $claim->status);
        $this->assertNotNull($claim->result_deposit_id, 'Approval must create the correcting deposit.');
    }

    public function test_institute_admin_rejects_a_claim_without_moving_money(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);
        $member = $this->makeStudent($institution, ['roll' => 'NSU-8003']);

        $claim = Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $member->id,
            'kind' => 'dispute', 'subject' => 'deposit', 'amount' => 300,
            'title' => 'Doubtful claim', 'status' => 'pending',
        ]);

        $this->step('InstituteAdmin', 'ClaimReview', 'PATCH reject', __LINE__);

        $this->httpAs($admin)
            ->patch("/claims/{$claim->id}/reject", ['review_notes' => 'Not substantiated.'])
            ->assertSessionHas('success');

        $claim->refresh();
        $this->assertSame('rejected', $claim->status);
        $this->assertNull($claim->result_deposit_id);
    }
}
