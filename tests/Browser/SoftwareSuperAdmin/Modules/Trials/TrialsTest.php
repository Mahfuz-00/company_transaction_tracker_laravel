<?php

namespace Tests\Browser\SoftwareSuperAdmin\Modules\Trials;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSA → MODULES → TRIAL & SUBSCRIPTION MANAGEMENT.
 *
 * Route: GET /settings/trials (`settings.trials.index`), guarded by
 * `permission:monitoring.view`. TrialManagementController tracks who is on a
 * 7-day trial vs a permanent subscription, with expiry countdowns.
 *
 * Write routes (all `permission:monitoring.manage`):
 *   POST /settings/trials/{institution}/remind  (`settings.trials.remind`)
 *   POST /settings/trials/{institution}/convert (`settings.trials.convert`)
 *   POST /settings/trials/{institution}/extend  (`settings.trials.extend`)
 */
class TrialsTest extends DuskTestCase
{
    use DuskSupport;

    public function test_ssa_sees_trials_and_subscribers(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $trial = $this->makeInstitution(['name' => 'Trialing Hall']);
        $trial->startTrial(7); // Institution::startTrial -> 7-day window

        $this->makeInstitution([
            'name' => 'Paying Mess',
            'onboarding_mode' => 'subscription',
            'subscription_status' => 'paid',
        ]);

        $this->step('SSA', 'Trials', 'visit /settings/trials', __LINE__);

        $this->browse(function (Browser $browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit('/settings/trials')
                ->waitForText('Trial & Subscription Management', 20)
                ->assertSee('Trialing Hall')
                ->assertSee('Paying Mess');
        });
    }

    public function test_ssa_converts_a_trial_to_a_permanent_subscription(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $institution = $this->makeInstitution(['name' => 'Trialing Hall']);
        $institution->startTrial(7);

        $this->step('SSA', 'Trials', 'POST convert', __LINE__);

        $this->httpAs($ssa)
            ->post("/settings/trials/{$institution->slug}/convert", [
                'subscription_plan' => 'standard',
                'subscription_amount' => 2500,
            ])
            ->assertSessionHas('success');

        $this->step('SSA', 'Trials', 'assert converted to a paid subscription', __LINE__);

        $institution->refresh();
        $this->assertSame('subscription', $institution->onboarding_mode);
        $this->assertSame('paid', $institution->subscription_status);
        $this->assertNotNull($institution->converted_at);
    }

    public function test_ssa_extends_a_trial_window(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $institution = $this->makeInstitution(['name' => 'Trialing Hall']);
        $institution->startTrial(7);
        $originalEnd = $institution->trial_ends_at->copy();

        $this->step('SSA', 'Trials', 'POST extend by 5 days', __LINE__);

        $this->httpAs($ssa)
            ->post("/settings/trials/{$institution->slug}/extend", ['days' => 5])
            ->assertSessionHas('success');

        $this->step('SSA', 'Trials', 'assert trial end moved forward', __LINE__);

        $this->assertTrue(
            $institution->fresh()->trial_ends_at->greaterThan($originalEnd),
            'Extending a trial must move trial_ends_at forward.'
        );
    }
}
