<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\Broadcasts;

use App\Models\StaffBroadcast;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → PLATFORM BROADCASTS.
 *
 * Route: GET /platform/broadcasts (`ssa.broadcasts.index`) guarded by
 * `permission:monitoring.view`; POST /platform/broadcasts
 * (`ssa.broadcasts.store`) guarded by `permission:monitoring.manage`.
 *
 * PlatformBroadcastController::store resolves the audience (all staff / all
 * members / everyone), writes an in-app notification to each recipient and
 * records a StaffBroadcast history row.
 */
class BroadcastsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_composes_a_platform_broadcast(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // A tenant with an admin and a member, so the audience resolves to > 0.
        $institution = $this->makeInstitution();
        $this->makeInstitutionAdmin($institution);
        $this->makeMember($institution);

        $this->step('SSA', 'Broadcasts', 'POST a platform announcement', __LINE__);

        // Audiences come from PlatformBroadcastController::AUDIENCES.
        $this->httpAs($ssa)
            ->post('/platform/broadcasts', [
                'title' => 'Scheduled maintenance',
                'body' => 'The platform will be briefly unavailable at 02:00 UTC.',
                'audience' => 'all',
                'severity' => 'warning',
            ])
            ->assertSessionHas('success');

        $this->step('SSA', 'Broadcasts', 'assert history row recorded', __LINE__);

        $broadcast = StaffBroadcast::where('title', 'Scheduled maintenance')->first();
        $this->assertNotNull($broadcast);
        $this->assertSame('all', $broadcast->audience);
        $this->assertSame('warning', $broadcast->severity);
        $this->assertSame($ssa->id, $broadcast->sent_by);
    }

    public function test_ssa_sees_broadcast_history(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        StaffBroadcast::create([
            'title' => 'New feature shipped',
            'body' => 'Multi-vendor reporting is live.',
            'audience' => 'admins',
            'severity' => 'success',
            'recipients' => 0,
            'sent_by' => $ssa->id,
        ]);

        $this->step('SSA', 'Broadcasts', 'visit /platform/broadcasts', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/broadcasts')
                ->waitForText('New feature shipped', 20);
        });
    }
}
