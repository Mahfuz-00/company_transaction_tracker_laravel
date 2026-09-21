<?php

namespace Tests\Browser\InstituteAdmin\Modules\ActivityLog;

use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → ACTIVITY LOG.
 *
 * Route: GET /settings/activity (`settings.activity.index`, `permission:audit.view`).
 * ActivityLogController HARD-SCOPES a non-SSA to their own institution, resolved
 * from the user record (not from a query parameter), so the scope cannot be
 * widened by the client.
 */
class ActivityLogTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_sees_their_institutions_activity(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        // A real audit entry, written by AuditLogger on demand.
        AuditLogger::log('updated', 'performed a testable action', $institution, [], [
            'subject_label' => 'Test Action',
            'institution_id' => $institution->id,
        ]);

        $this->step('InstituteAdmin', 'ActivityLog', 'visit /settings/activity', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/activity')
                ->assertPathIs('/settings/activity')
                ->assertSee('North South University Dorm');
        });
    }
}
