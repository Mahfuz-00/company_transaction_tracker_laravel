<?php

namespace Tests\Browser\InstituteAdmin\Modules\EmailLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → EMAIL LOG / OUTBOX.
 *
 * Route: GET /settings/emails (`settings.emails.index`, `permission:emails.view`).
 * EmailLogController LOCKS a non-SSA to their own institution (resolved from the
 * user record), so an admin sees only their workspace's outbox.
 */
class EmailLogTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_reaches_the_email_outbox(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'EmailLog', 'visit /settings/emails', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/emails')
                ->assertPathIs('/settings/emails')
                ->assertSee('North South University Dorm');
        });
    }
}
