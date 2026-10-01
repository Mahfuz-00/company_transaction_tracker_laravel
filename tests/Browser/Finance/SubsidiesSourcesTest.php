<?php

namespace Tests\Browser\Finance;

use App\Models\SubsidySource;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 11: Subsidies Sources Persistence Fix.
 *
 * Verifies:
 * - All subsidy sources are dynamic, stored in the database, and editable.
 * - No hardcoded mock/persistent data.
 */
class SubsidiesSourcesTest extends DuskTestCase
{
    use DuskSupport;

    public function test_subsidy_sources_are_dynamic_database_stored_and_editable(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $source = SubsidySource::create([
            'institution_id' => $institution->id,
            'name' => 'Welfare Board Grant',
            'key' => 'welfare_board_grant',
            'percentage' => 40.0,
            'description' => 'Annual student welfare subsidy',
            'is_active' => true,
        ]);

        $this->step('IA', 'Finance', 'view and edit dynamic subsidy sources', __LINE__);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/subsidy-sources')
                ->waitForText('Welfare Board Grant', 20)
                ->assertSee('40%')
                ->assertSee('welfare_board_grant');
        });

        // Update the dynamic source
        $source->update([
            'name' => 'Updated Welfare Board Grant',
            'percentage' => 45.0,
        ]);

        $this->browse(function (Browser $browser) use ($admin) {
            $browser->loginAs($admin)
                ->visit('/settings/subsidy-sources')
                ->waitForText('Updated Welfare Board Grant', 20)
                ->assertSee('45%');
        });

        $this->assertDatabaseHas('subsidy_sources', [
            'id' => $source->id,
            'name' => 'Updated Welfare Board Grant',
            'percentage' => 45.0,
        ]);
    }
}
