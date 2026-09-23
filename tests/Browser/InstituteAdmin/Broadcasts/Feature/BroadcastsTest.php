<?php

namespace Tests\Browser\InstituteAdmin\Broadcasts\Feature;

use App\Models\InstitutionBroadcast;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTION ADMIN → BROADCASTS (workspace-scoped).
 *
 * Routes: GET /settings/broadcasts (`broadcasts.index`) and
 * POST /settings/broadcasts (`broadcasts.store`), both guarded by
 * `permission:notifications.announce`. InstitutionBroadcastController resolves
 * the target institution from the ACTOR and dispatches via
 * Notifier::institutionBroadcast(), which filters recipients by institution_id.
 *
 * MIRRORS tests/Browser/SoftwareSuperAdmin/Broadcasts/Feature/BroadcastsTest.php
 * for the platform-level module — but asserts the TENANT isolation.
 */
class BroadcastsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institution_admin_composes_a_workspace_broadcast(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution(['name' => 'Alpha Dorm']);
        $admin = $this->makeInstitutionAdmin($institution, ['email' => 'admin@alpha.test']);
        $this->makeMember($institution, ['email' => 'member@alpha.test']);

        $this->step('InstituteAdmin', 'Broadcasts', 'POST a workspace announcement', __LINE__);

        // Audiences come from InstitutionBroadcast::AUDIENCES (no platform-wide option).
        $this->httpAs($admin)
            ->post('/settings/broadcasts', [
                'title' => 'Menu change from Monday',
                'body' => 'Lunch moves to 13:00 for the rest of the term.',
                'audience' => 'members',
                'severity' => 'info',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Broadcasts', 'assert the history row is tenant-stamped', __LINE__);

        $broadcast = InstitutionBroadcast::where('title', 'Menu change from Monday')->first();
        $this->assertNotNull($broadcast);
        $this->assertSame($institution->id, $broadcast->institution_id);
        $this->assertSame('members', $broadcast->audience);
        $this->assertSame($admin->id, $broadcast->sent_by);
    }

    public function test_institution_admin_sees_their_broadcast_history(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution(['name' => 'Alpha Dorm']);
        $admin = $this->makeInstitutionAdmin($institution, ['email' => 'admin@alpha.test']);

        InstitutionBroadcast::create([
            'institution_id' => $institution->id,
            'title' => 'Holiday closure',
            'body' => 'The kitchen is closed on Friday.',
            'audience' => 'admins',
            'severity' => 'warning',
            'recipients' => 0,
            'sent_by' => $admin->id,
        ]);

        $this->step('InstituteAdmin', 'Broadcasts', 'visit /settings/broadcasts', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/broadcasts')
                ->waitForText('Holiday closure', 20);
        });
    }
}
