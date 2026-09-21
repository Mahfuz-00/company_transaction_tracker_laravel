<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\Plans;

use App\Models\Institution;
use App\Models\SubscriptionPlan;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → PRICING & PLANS.
 *
 * Route: GET /platform/plans (`ssa.plans.index`), guarded by
 * `permission:plans.view` (global role only). SubscriptionPlanController is
 * SSA-gated at the route AND re-asserts isSuperAdmin().
 *
 * Write routes: POST /platform/plans (`ssa.plans.store`), PUT
 * /platform/plans/{plan} (`ssa.plans.update`), DELETE
 * /platform/plans/{plan} (`ssa.plans.destroy`) - all `permission:plans.manage`.
 */
class PlansTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_sees_the_plan_manager(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        SubscriptionPlan::create([
            'key' => 'standard',
            'name' => 'Standard',
            'description' => 'For growing institutions',
            'monthly_price' => 2500,
            'is_free' => false,
            'member_limit' => 200,
            'manager_limit' => 10,
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->step('SSA', 'Plans', 'visit /platform/plans', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/platform/plans')
                ->waitForText('Standard', 20);
        });
    }

    public function test_ssa_creates_a_pricing_plan(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'Plans', 'POST a new plan', __LINE__);

        // SubscriptionPlanController::validated() requires name, monthly_price,
        // member_limit, manager_limit; key is derived from name when omitted.
        $this->httpAs($ssa)
            ->post('/platform/plans', [
                'name' => 'Enterprise',
                'monthly_price' => 9000,
                'member_limit' => -1,   // -1 == unlimited
                'manager_limit' => -1,
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        $this->step('SSA', 'Plans', 'assert plan persisted with derived key', __LINE__);

        $plan = SubscriptionPlan::where('name', 'Enterprise')->first();
        $this->assertNotNull($plan);
        $this->assertSame('enterprise', $plan->key);
    }

    public function test_ssa_cannot_delete_a_plan_in_use(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $plan = SubscriptionPlan::create([
            'key' => 'standard', 'name' => 'Standard', 'monthly_price' => 2500,
            'is_free' => false, 'member_limit' => 200, 'manager_limit' => 10,
            'is_active' => true, 'is_public' => true,
        ]);

        // An institution actively on the plan makes deletion unsafe.
        Institution::create([
            'name' => 'Bound Institution', 'type' => 'general_mess',
            'subscription_plan' => 'standard', 'subscription_status' => 'paid',
            'is_active' => true,
        ]);

        $this->step('SSA', 'Plans', 'DELETE an in-use plan is refused', __LINE__);

        $this->httpAs($ssa)
            ->delete("/platform/plans/{$plan->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }

    public function test_a_tenant_admin_cannot_reach_the_plan_manager(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('SSA', 'Plans', 'assert tenant admin is forbidden', __LINE__);

        $this->httpAs($admin)->get('/platform/plans')->assertForbidden();
    }
}
