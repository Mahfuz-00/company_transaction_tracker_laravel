<?php

namespace Tests\Browser\SoftwareSuperAdmin\InstitutionRegistry\Feature;

use App\Models\Institution;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → TIMEZONE (dropdown value + persistence).
 *
 * The timezone is chosen from a dropdown of VALID IANA identifiers and saved on
 * the institution, so it becomes the active workspace's operational timezone.
 * A crafted/invalid value must be rejected (the columns stay as-is; only the
 * validation tightens).
 */
class TimezoneDropdownTest extends DuskTestCase
{
    use DuskSupport;

    public function test_updating_the_workspace_timezone_saves_a_valid_zone(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // The SSA is not switched in, so Institution::current() resolves to the
        // first active workspace.
        $institution = $this->makeInstitution(['timezone' => 'UTC']);

        $this->step('SSA', 'InstitutionSettings', 'save Asia/Dhaka', __LINE__);

        $this->httpAs($ssa)
            ->put('/settings/institution', [
                'name' => $institution->name,
                'type' => $institution->type,
                'timezone' => 'Asia/Dhaka',
            ])
            ->assertSessionHas('success');

        $this->assertSame('Asia/Dhaka', $institution->fresh()->timezone);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $institution = $this->makeInstitution(['timezone' => 'UTC']);

        $this->step('SSA', 'InstitutionSettings', 'reject a free-text zone', __LINE__);

        $this->httpAs($ssa)
            ->put('/settings/institution', [
                'name' => $institution->name,
                'type' => $institution->type,
                'timezone' => 'Nowhere/Imaginary',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $institution->fresh()->timezone);
    }

    public function test_creating_an_institution_persists_the_selected_timezone(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'InstitutionRegistry', 'create with Europe/Paris', __LINE__);

        $this->httpAs($ssa)
            ->post('/settings/institutions', [
                'name' => 'Paris Dorm',
                'type' => 'university_dorm',
                'timezone' => 'Europe/Paris',
                'admin_name' => 'Paris Admin',
                'admin_email' => 'admin@paris.test',
                'admin_password' => 'password123',
            ])
            ->assertSessionHas('success');

        $this->assertSame('Europe/Paris', Institution::where('name', 'Paris Dorm')->firstOrFail()->timezone);
    }
}
