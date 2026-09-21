<?php

namespace Tests\Browser\Member\Modules\Profile;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * MEMBER → MODULES → PROFILE MANAGER.
 *
 * Route: GET /profile (`profile.edit`) + PATCH /profile (`profile.update`).
 * Reachable by every authenticated user. ProfileController::update keeps the
 * member's name as a single source of truth: a rename here is synchronised onto
 * the linked Student roster record (MemberProfileSynchronizer::syncName).
 */
class ProfileTest extends DuskTestCase
{
    use DuskSupport;

    public function test_member_opens_the_profile_manager(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['name' => 'Samira Member']);

        $this->step('Member', 'Profile', 'visit /profile', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/profile')
                ->waitForText('Profile Manager', 20)
                ->assertSee('Samira Member');
        });
    }

    public function test_member_can_change_their_password(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution, ['email' => 'pw@north.test']);

        $this->step('Member', 'Profile', 'open the change-password screen', __LINE__);

        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/password/change')
                ->assertPathIs('/password/change');
        });

        $this->step('Member', 'Profile', 'PUT the new password', __LINE__);

        // ProfileController::updatePassword routes through PasswordGuard and
        // requires the current password to match.
        $this->httpAs($member)
            ->put('/password/change', [
                'current_password' => 'password',
                'password' => 'BrandNewPass123',
                'password_confirmation' => 'BrandNewPass123',
            ])
            ->assertSessionHas('success');
    }
}
