<?php

namespace Tests\Browser\Onboarding\Feature;

use App\Models\Institution;
use App\Models\User;
use App\Support\OnboardingGuide;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * FIRST-TIME LOGIN → ROLE-SPECIFIC ONBOARDING MANUAL.
 *
 * The guide is delivered through the shared Inertia props (`onboarding`), keyed
 * off the signed-in user's role. Four journeys exist:
 *
 *   SSA (Software Super Admin) / IA (Institution Admin) /
 *   MM (Meal Manager) / Member.
 *
 * WHAT THESE TESTS LOCK IN
 * ------------------------
 *   1. The guide appears on the FIRST sign-in for every role, with the correct
 *      role-specific content.
 *   2. It does NOT reappear once completed (the `onboarding_completed_at` flag).
 *   3. Completing it persists the flag for the signed-in user.
 *   4. The four journeys are genuinely DIFFERENT (not one generic modal).
 *
 * The content itself is asserted through `OnboardingGuide` (the single source of
 * truth the modal renders from), so these tests stay stable even if the modal's
 * markup changes.
 */
class RoleOnboardingTest extends DuskTestCase
{
    use DuskSupport;

    /** Every role's guide is distinct and non-empty. */
    public function test_each_role_has_a_distinct_journey(): void
    {
        $this->seedRbac();

        $institution = $this->makeInstitution();

        $ssa = $this->makeSuperAdmin();
        $admin = $this->makeInstitutionAdmin($institution);
        $manager = $this->makeMealManager($institution);
        $member = $this->makeMember($institution);

        $this->assertSame('ssa', $ssa->onboardingRole());
        $this->assertSame('ia', $admin->onboardingRole());
        $this->assertSame('mm', $manager->onboardingRole());
        $this->assertSame('member', $member->onboardingRole());

        $guides = [
            'ssa' => OnboardingGuide::for($ssa),
            'ia' => OnboardingGuide::for($admin),
            'mm' => OnboardingGuide::for($manager),
            'member' => OnboardingGuide::for($member),
        ];

        foreach ($guides as $role => $guide) {
            $this->assertSame($role, $guide['role']);
            $this->assertNotEmpty($guide['steps'], "The {$role} guide must have steps.");
            $this->assertNotEmpty($guide['title']);
            $this->assertNotEmpty($guide['first_action']['label']);
        }

        // The four journeys must be genuinely different: compare the step titles.
        $titles = array_map(
            fn ($guide) => implode('|', array_column($guide['steps'], 'title')),
            $guides
        );

        $this->assertCount(4, array_unique($titles), 'Each role must have its own journey, not a shared one.');
    }

    public function test_the_ssa_guide_explains_platform_level_features(): void
    {
        $this->seedRbac();
        $ssa = $this->makeSuperAdmin();

        $guide = OnboardingGuide::for($ssa);

        // The SSA journey must cover the platform modules, not a tenant dashboard.
        $body = strtolower(implode(' ', array_column($guide['steps'], 'body')));
        $this->assertStringContainsString('institution registry', $body);
        $this->assertStringContainsString('platform', $body);
    }

    public function test_the_member_guide_explains_personal_features(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $guide = OnboardingGuide::for($member);

        $body = strtolower(implode(' ', array_column($guide['steps'], 'body')));
        $this->assertStringContainsString('balance', $body);
        $this->assertStringContainsString('claim', $body);
    }

    public function test_the_member_sees_their_onboarding_modal_on_first_login(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Fresh Member']);

        // A brand-new account has never seen the guide.
        $this->assertTrue($member->shouldSeeOnboarding());

        $this->step('Member', 'Onboarding', 'first visit shows the role modal', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="onboarding-modal"]', 20)
                ->assertVisible('[data-testid="onboarding-modal"]')
                // The member journey's first step.
                ->assertSee('Your Dashboard')
                // The role chip names the correct user type.
                ->assertSee('Member');
        });
    }

    public function test_completing_the_onboarding_persists_the_flag(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->assertNull($member->onboarding_completed_at);

        $this->step('Member', 'Onboarding', 'POST /onboarding/complete', __LINE__);

        $this->httpAs($member)
            ->post('/onboarding/complete')
            ->assertSessionHas('success');

        $member->refresh();

        $this->assertNotNull(
            $member->onboarding_completed_at,
            'Completing the guide must persist onboarding_completed_at.'
        );
        $this->assertFalse(
            $member->shouldSeeOnboarding(),
            'The guide must not be shown again once completed.'
        );
    }

    public function test_the_modal_does_not_reappear_after_completion(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Returning Member']);

        // Mark it complete, as the modal's "Get started" button would.
        $member->forceFill(['onboarding_completed_at' => now()])->save();

        $this->step('Member', 'Onboarding', 'second visit hides the modal', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitForText('Welcome, Returning Member', 20)
                ->assertMissing('[data-testid="onboarding-modal"]');
        });
    }

    public function test_the_onboarding_endpoint_is_guarded_to_authenticated_users(): void
    {
        $this->seedRbac();

        // A guest has no session to mark complete -> the auth middleware redirects.
        $this->get('/onboarding/guide')->assertRedirect();
    }

    public function test_the_guide_endpoint_returns_the_role_specific_content(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $manager = $this->makeMealManager($institution);

        $this->step('MealManager', 'Onboarding', 'GET /onboarding/guide', __LINE__);

        $this->httpAs($manager)
            ->getJson('/onboarding/guide')
            ->assertOk()
            ->assertJson(['role' => 'mm'])
            ->assertJsonStructure(['role', 'guide' => ['title', 'steps', 'first_action']]);
    }

    public function test_a_user_with_a_temporary_password_does_not_get_the_modal_first(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();

        // An admin-provisioned account still holds a temporary password: the
        // forced password-change flow takes priority over the onboarding guide.
        $user = $this->makeTenantUser($institution, 'Member', [
            'must_change_password' => true,
            'onboarding_completed_at' => null,
        ]);

        $this->assertTrue($user->mustChangePassword());
        $this->assertFalse(
            $user->shouldSeeOnboarding(),
            'The forced password change must take priority over onboarding.'
        );
    }
}
