<?php

namespace Tests\Browser\Finance;

use App\Models\SubsidySource;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 12: Total Subsidies Percentage Validation.
 *
 * Verifies:
 * - Enforces validation ensuring that Total Subsidies allocation rules can never exceed 100%.
 */
class SubsidiesValidationTest extends DuskTestCase
{
    use DuskSupport;

    public function test_subsidies_percentage_cannot_exceed_100_percent(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // Pre-create 70% allocation
        SubsidySource::create([
            'institution_id' => $institution->id,
            'name' => 'Primary Trust',
            'key' => 'primary_trust',
            'percentage' => 70.0,
            'is_active' => true,
        ]);

        $this->step('IA', 'Finance', 'attempt adding allocation exceeding 100%', __LINE__);

        // Attempting to add 35% (70 + 35 = 105 > 100) must fail validation
        $response = $this->httpAs($admin)->post('/settings/subsidy-sources', [
            'name' => 'Excessive Donor Fund',
            'percentage' => 35.0,
        ]);

        $response->assertSessionHasErrors('percentage');
        $this->assertDatabaseMissing('subsidy_sources', [
            'name' => 'Excessive Donor Fund',
        ]);

        $this->step('IA', 'Finance', 'adding allocation up to 100% succeeds', __LINE__);

        // Adding 30% (70 + 30 = 100) must succeed
        $validResponse = $this->httpAs($admin)->post('/settings/subsidy-sources', [
            'name' => 'Exact Donor Fund',
            'percentage' => 30.0,
        ]);

        $validResponse->assertSessionHas('success');
        $this->assertDatabaseHas('subsidy_sources', [
            'name' => 'Exact Donor Fund',
            'percentage' => 30.0,
        ]);
    }
}
