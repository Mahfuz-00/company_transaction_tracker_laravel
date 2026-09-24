<?php

namespace Tests\Browser\SoftwareSuperAdmin\Plans\Feature;

use App\Models\SubscriptionPlan;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → PRICING & PLANS → KEY PROTECTION + CONTEXTUAL VALIDATION.
 *
 * The plan `key` is a stable identifier institutions store as their plan. These
 * lock in two rules:
 *   - a blank key on UPDATE must NOT clear the stored key (it stays as-is),
 *   - `monthly_price` is required only for a PAID plan (contextual, columns
 *     stay nullable).
 */
class PlanKeyProtectionTest extends DuskTestCase
{
    use DuskSupport;

    public function test_updating_a_plan_with_a_blank_key_keeps_the_existing_key(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Create (key derived from the name).
        $this->httpAs($ssa)->post('/platform/plans', [
            'name' => 'Pro', 'monthly_price' => 100, 'member_limit' => -1, 'manager_limit' => -1,
        ])->assertSessionHas('success');

        $plan = SubscriptionPlan::where('name', 'Pro')->firstOrFail();
        $this->assertSame('pro', $plan->key);

        $this->step('SSA', 'Plans', 'update with NO key field', __LINE__);

        // Update without a `key` key at all - it must be preserved.
        $this->httpAs($ssa)->put("/platform/plans/{$plan->id}", [
            'name' => 'Pro', 'member_limit' => -1, 'manager_limit' => -1, 'is_free' => true,
        ])->assertSessionHas('success');

        $this->assertSame('pro', $plan->fresh()->key, 'A blank/absent key must never clear the stored key.');

        // Update with an EXPLICIT empty string key - still preserved.
        $this->httpAs($ssa)->put("/platform/plans/{$plan->id}", [
            'name' => 'Pro', 'key' => '', 'member_limit' => -1, 'manager_limit' => -1, 'is_free' => true,
        ])->assertSessionHas('success');

        $this->assertSame('pro', $plan->fresh()->key);
    }

    public function test_a_paid_plan_requires_a_price_but_a_free_plan_does_not(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        // Paid plan with no price -> rejected.
        $this->httpAs($ssa)
            ->post('/platform/plans', [
                'name' => 'Paid Plan', 'is_free' => false, 'member_limit' => -1, 'manager_limit' => -1,
            ])
            ->assertSessionHasErrors('monthly_price');

        // Free plan with no price -> accepted.
        $this->httpAs($ssa)
            ->post('/platform/plans', [
                'name' => 'Free Plan', 'is_free' => true, 'member_limit' => -1, 'manager_limit' => -1,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subscription_plans', ['name' => 'Free Plan', 'monthly_price' => 0]);
    }
}
