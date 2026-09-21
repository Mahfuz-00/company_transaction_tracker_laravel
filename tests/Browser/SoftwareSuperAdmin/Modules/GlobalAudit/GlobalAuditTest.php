<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\GlobalAudit;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → GLOBAL SYSTEM AUDIT & SECURITY LOG.
 *
 * Route: GET /platform/audit (`ssa.audit.index`), guarded by
 * `permission:monitoring.view`. GlobalAuditController lists cross-tenant
 * activity, filterable by institution and severity, with an export.
 *
 * Activity rows are written by AuditLogger (e.g. a login). This test proves the
 * screen is reachable and returns the SSA's cross-tenant audit stream.
 */
class GlobalAuditTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_reaches_the_global_audit_log(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'GlobalAudit', 'visit /platform/audit', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/audit')
                ->assertPathIs('/platform/audit')
                // The page title is set by the audit page's <Head>.
                ->assertSee('Audit');
        });
    }

    public function test_a_tenant_admin_cannot_reach_the_global_audit_log(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('SSA', 'GlobalAudit', 'assert tenant admin is forbidden', __LINE__);

        $this->httpAs($admin)->get('/platform/audit')->assertForbidden();
    }
}
