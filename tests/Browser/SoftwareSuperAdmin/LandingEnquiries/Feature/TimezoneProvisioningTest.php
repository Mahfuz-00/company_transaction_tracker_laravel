<?php

namespace Tests\Browser\SoftwareSuperAdmin\LandingEnquiries\Feature;

use App\Models\Institution;
use App\Models\LandingEnquiry;
use App\Models\User;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → PROVISIONING → TIMEZONE DEFAULT.
 *
 * Regression lock for "NOT NULL constraint failed: institutions.timezone".
 *
 * `institutions.timezone` is NOT NULL DEFAULT 'UTC', but an EXPLICIT null -
 * which the provisioner used to send as `$data['timezone'] ?? null` - overrides
 * the default and aborts the insert. Both provisioning paths that omit a
 * timezone must therefore succeed and land on the platform default:
 *
 *   - POST /platform/enquiries/{enquiry}/approve  (`ssa.enquiries.approve`)
 *     (the reported TRIAL APPROVAL failure), and
 *   - POST /settings/institutions                 (`settings.institutions.store`).
 */
class TimezoneProvisioningTest extends DuskTestCase
{
    use DuskSupport;

    public function test_approving_an_enquiry_without_a_timezone_uses_the_platform_default(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $enquiry = LandingEnquiry::create([
            'name' => 'Karim Ahmed',
            'email' => 'karim@acme-food.test',
            'institution_name' => 'Acme Foods Cafeteria',
            'institution_type' => 'corporate',
            'status' => 'new',
        ]);

        $this->step('SSA', 'LandingEnquiries', 'approve WITHOUT a timezone', __LINE__);

        // No 'timezone' key at all - this used to 500 on the NOT NULL column.
        $this->httpAs($ssa)
            ->post("/platform/enquiries/{$enquiry->id}/approve", [])
            ->assertSessionHas('success');

        $enquiry->refresh();
        $this->assertSame('approved', $enquiry->status);

        $institution = Institution::findOrFail($enquiry->institution_id);
        $this->assertNotNull($institution->timezone);
        $this->assertSame(config('app.timezone', 'UTC'), $institution->timezone);

        // The first Institution Admin was provisioned too.
        $this->assertTrue(
            User::where('email', 'karim@acme-food.test')->where('institution_id', $institution->id)->exists()
        );
    }

    public function test_the_registry_can_create_an_institution_without_a_timezone(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'InstitutionRegistry', 'create WITHOUT a timezone', __LINE__);

        $this->httpAs($ssa)
            ->post('/settings/institutions', [
                'name' => 'Registry Dorm',
                'type' => 'university_dorm',
                'admin_name' => 'Registry Admin',
                'admin_email' => 'registry-admin@example.test',
                'admin_password' => 'password123',
            ])
            ->assertSessionHas('success');

        $institution = Institution::where('name', 'Registry Dorm')->firstOrFail();
        $this->assertNotNull($institution->timezone);
        $this->assertSame(config('app.timezone', 'UTC'), $institution->timezone);
    }

    public function test_the_model_heals_an_explicit_null_timezone(): void
    {
        $this->seedRbac();

        // Any caller writing null directly must not throw and must land on the
        // platform default.
        $institution = Institution::create([
            'name' => 'Healed Dorm',
            'type' => 'general_mess',
            'timezone' => null,
            'is_active' => true,
        ]);

        $this->assertNotNull($institution->fresh()->timezone);
        $this->assertSame(config('app.timezone', 'UTC'), $institution->fresh()->timezone);
    }
}
