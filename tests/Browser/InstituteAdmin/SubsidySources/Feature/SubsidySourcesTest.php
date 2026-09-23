<?php

namespace Tests\Browser\InstituteAdmin\SubsidySources\Feature;

use App\Models\SubsidySource;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → SUBSIDY SOURCES.
 *
 * Routes:
 *   GET    /settings/subsidy-sources              (`settings.subsidy-sources.index`,  subsidies.view)
 *   POST   /settings/subsidy-sources              (`settings.subsidy-sources.store`,  subsidies.manage)
 *   PUT    /settings/subsidy-sources/{id}         (`settings.subsidy-sources.update`, subsidies.manage)
 *   DELETE /settings/subsidy-sources/{id}         (`settings.subsidy-sources.destroy`,subsidies.manage)
 *
 * SubsidySourceController manages the per-institution funding sources (each with
 * a default percentage share). Sources are institution-scoped or global.
 */
class SubsidySourcesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_institute_admin_adds_a_funding_source(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'SubsidySources', 'POST a funding source', __LINE__);

        $this->httpAs($admin)
            ->post('/settings/subsidy-sources', [
                'name' => 'Alumni Grant',
                'percentage' => 25,
                'description' => 'Annual alumni contribution.',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'SubsidySources', 'assert source scoped to institution', __LINE__);

        $source = SubsidySource::where('name', 'Alumni Grant')->first();
        $this->assertNotNull($source);
        $this->assertSame($institution->id, $source->institution_id);
        $this->assertSame('alumni_grant', $source->key); // Str::slug(name, '_')
    }

    public function test_a_source_in_use_cannot_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->httpAs($admin)->post('/settings/subsidy-sources', ['name' => 'Used Source']);
        $source = SubsidySource::where('name', 'Used Source')->firstOrFail();

        // Record a subsidy that references the source key so history exists.
        $this->httpAs($admin)->post('/meals/subsidies', [
            'source' => $source->key, 'amount' => 500, 'apply_mode' => 'pool',
        ]);

        $this->step('InstituteAdmin', 'SubsidySources', 'DELETE refused (references exist)', __LINE__);

        $this->httpAs($admin)
            ->delete("/settings/subsidy-sources/{$source->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('subsidy_sources', ['id' => $source->id]);
    }
}
