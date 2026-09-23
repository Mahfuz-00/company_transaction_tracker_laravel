<?php

namespace Tests\Browser\Member\Claims\Feature;

use App\Models\Claim;
use App\Models\Student;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → MY CLAIMS.
 *
 * Route: GET /claims (`claims.index`), gated by `permission:claims.view` AND
 * `role:Member` (member-only in routes/web.php). POST /claims (`claims.store`)
 * is gated by `permission:claims.submit`.
 *
 * ClaimController::index scopes to the signed-in member's own Student record;
 * ::store refuses if the login is not linked to a member record.
 */
class ClaimsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_submits_a_claim(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'claimer@north.test']);

        $this->step('Member', 'Claims', 'POST a dispute claim', __LINE__);

        // Claim::KINDS / SUBJECTS keys: dispute|expense, deposit|meal|...
        $this->httpAs($member)
            ->post('/claims', [
                'kind' => 'dispute',
                'subject' => 'deposit',
                'amount' => 500,
                'title' => 'Missing cash deposit',
                'claim_date' => now()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->step('Member', 'Claims', 'assert claim pending + scoped to member', __LINE__);

        $memberRecord = Student::where('user_id', $member->id)->firstOrFail();
        $claim = Claim::where('student_id', $memberRecord->id)->first();
        $this->assertNotNull($claim);
        $this->assertSame('pending', $claim->status);
        $this->assertSame($institution->id, $claim->institution_id);
    }

    public function test_member_sees_their_own_claims_list(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'claimer@north.test']);
        $memberRecord = Student::where('user_id', $member->id)->firstOrFail();

        Claim::create([
            'institution_id' => $institution->id,
            'student_id' => $memberRecord->id,
            'kind' => 'dispute', 'subject' => 'deposit', 'amount' => 250,
            'title' => 'My visible claim', 'status' => 'pending',
        ]);

        $this->step('Member', 'Claims', 'visit /claims', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/claims')
                ->waitForText('My visible claim', 20);
        });
    }
}
