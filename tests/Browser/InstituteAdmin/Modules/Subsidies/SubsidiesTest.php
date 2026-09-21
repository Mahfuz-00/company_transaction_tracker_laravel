<?php

namespace Tests\Browser\InstituteAdmin\Modules\Subsidies;

use App\Models\Subsidy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → INSTITUTIONAL SUBSIDIES.
 *
 * Routes:
 *   GET   /meals/subsidies              (`meals.subsidies.index`,   subsidies.view)
 *   POST  /meals/subsidies              (`meals.subsidies.store`,   subsidies.manage)
 *   PATCH /meals/subsidies/{id}/reverse (`meals.subsidies.reverse`, subsidies.manage)
 *
 * SubsidyController keeps institutional money distinct from member deposits and
 * can distribute per-member as tagged credit.
 */
class SubsidiesTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_records_a_subsidy(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Subsidies', 'POST a subsidy', __LINE__);

        // apply_mode values come from Subsidy::APPLY_MODES.
        $this->actingAs($admin)
            ->post('/meals/subsidies', [
                'source' => 'university_authority',
                'amount' => 50000,
                'apply_mode' => 'pool',
                'period_month' => now()->format('Y-m'),
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Subsidies', 'assert subsidy persisted', __LINE__);

        $subsidy = Subsidy::where('source', 'university_authority')->first();
        $this->assertNotNull($subsidy);
        $this->assertSame($institution->id, $subsidy->institution_id);
        $this->assertSame('active', $subsidy->status);
    }

    public function test_institute_admin_reverses_a_subsidy(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->actingAs($admin)->post('/meals/subsidies', [
            'source' => 'grant', 'amount' => 1000, 'apply_mode' => 'pool',
        ]);

        $subsidy = Subsidy::where('source', 'grant')->firstOrFail();

        $this->step('InstituteAdmin', 'Subsidies', 'PATCH reverse', __LINE__);

        $this->actingAs($admin)
            ->patch("/meals/subsidies/{$subsidy->id}/reverse")
            ->assertSessionHas('success');

        $this->assertSame('reversed', $subsidy->fresh()->status);
    }
}
