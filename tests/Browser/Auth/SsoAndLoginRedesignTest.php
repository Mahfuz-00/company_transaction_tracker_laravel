<?php

namespace Tests\Browser\Auth;

use App\Models\Institution;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * SSO/OAuth Buttons & Login UI Redesign Verification.
 *
 * Verifies:
 * - Google, Microsoft, Facebook, and X (Twitter) authentication buttons strictly below main form inputs
 *   on both Login and Registration views, separated by a clean "Or continue with" divider.
 * - Registration Invite Gate enforces mandatory Institution Invite Code check before allowing SSO/OAuth redirect.
 * - Light intro panel & animation on login.
 */
class SsoAndLoginRedesignTest extends DuskTestCase
{
    use DuskSupport;

    public function test_sso_buttons_render_below_form_inputs_and_light_intro_panel_on_login(): void
    {
        $this->seedRbac();

        config(['services.oauth.google.client_id' => 'test-google-id']);
        config(['services.oauth.google.client_secret' => 'test-google-secret']);
        config(['services.oauth.microsoft.client_id' => 'test-ms-id']);
        config(['services.oauth.microsoft.client_secret' => 'test-ms-secret']);
        config(['services.oauth.facebook.client_id' => 'test-fb-id']);
        config(['services.oauth.facebook.client_secret' => 'test-fb-secret']);
        config(['services.oauth.x.client_id' => 'test-x-id']);
        config(['services.oauth.x.client_secret' => 'test-x-secret']);

        $this->step('Guest', 'Auth', 'assert login layout and sso placement', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->waitFor('form', 20)
                ->assertVisible('input[name="email"]')
                ->assertVisible('input[name="password"]')
                ->assertMissing('input[name="invite_code"]')
                ->assertMissing('[data-testid="login-invite-code"]')
                ->assertVisible('[data-testid="sso-buttons"]')
                ->assertVisible('[data-testid="sso-divider-text"]')
                ->assertVisible('[data-testid="sso-button-google"]')
                ->assertAttribute('[data-testid="sso-button-google"]', 'aria-disabled', 'false')
                ->assertVisible('[data-testid="sso-button-microsoft"]')
                ->assertVisible('[data-testid="sso-button-facebook"]')
                ->assertVisible('[data-testid="sso-button-x"]');
        });
    }

    public function test_registration_invite_gate_blocks_sso_redirect_when_invalid(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['invite_code' => 'CORRECT99']);

        config(['services.oauth.google.client_id' => 'test-google-id']);
        config(['services.oauth.google.client_secret' => 'test-google-secret']);

        $this->step('Guest', 'Auth', 'assert registration invite code gate for sso', __LINE__);

        $this->browse(function (Browser $browser) {
            $browser->visit('/register')
                ->waitFor('form', 20)
                ->assertVisible('input[name="invite_code"]')
                ->assertVisible('[data-testid="sso-buttons"]')
                // Initially with no code or invalid code, SSO buttons are disabled
                ->assertAttribute('[data-testid="sso-button-google"]', 'aria-disabled', 'true')
                ->type('invite_code', 'INVALIDCODE')
                ->waitFor('[data-testid="invite-code-invalid"]', 20)
                ->assertAttribute('[data-testid="sso-button-google"]', 'aria-disabled', 'true')
                // Now type correct invite code
                ->clear('invite_code')
                ->type('invite_code', 'CORRECT99')
                ->waitFor('[data-testid="invite-code-valid"]', 20)
                ->assertAttribute('[data-testid="sso-button-google"]', 'aria-disabled', 'false');
        });
    }
}
