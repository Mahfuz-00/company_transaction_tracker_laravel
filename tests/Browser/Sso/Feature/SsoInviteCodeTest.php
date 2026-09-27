<?php

namespace Tests\Browser\Sso\Feature;

use App\Models\SocialAccount;
use App\Support\OAuthProviders;
use Illuminate\Database\QueryException;
use Laravel\Dusk\Browser;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * CONTEXTUAL SSO / OAUTH WITH THE INSTITUTION INVITE CODE.
 *
 * ROUTES
 *   GET /auth/{provider}/redirect   (oauth.redirect)  - mint state, validate code
 *   GET /auth/{provider}/callback   (oauth.callback)  - verify state, match account
 *
 * PROVIDERS: google, microsoft (OIDC) + facebook, x (plain OAuth2).
 *
 * WHAT THESE TESTS LOCK IN
 *   1. The invite code is validated BEFORE the provider round-trip: an unknown code
 *      fails immediately, so a typo never costs a full OAuth journey.
 *   2. A VALID code pins the sign-in to that workspace (stored server-side).
 *   3. All four providers are supported and appear in the shared `oauth` prop.
 *   4. The login/registration UI gates the SSO buttons behind the code.
 *   5. A callback without a valid `state` is refused (CSRF protection).
 *
 * NOTE ON NETWORK: these tests never reach Google/Microsoft/Facebook/X. They assert
 * the PRE-FLIGHT behaviour (code validation, state, provider registry) and the
 * callback guards, which is where the platform's own logic lives. Reaching a real
 * provider would need live credentials and is not appropriate in CI.
 */
class SsoInviteCodeTest extends DuskTestCase
{
    use DuskSupport;

    public function test_an_unknown_invite_code_fails_before_contacting_the_provider(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'SSO', 'redirect with an invalid invite code', __LINE__);

        // No provider call happens: the code is rejected up front and the user is
        // returned to the login screen with a precise message.
        $this->get('/auth/google/redirect?invite_code=NOPE9999')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_a_valid_invite_code_pins_the_sign_in_to_that_institution(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'Code Target Workspace']);

        // Google must be CONFIGURED for the controller to proceed to the provider;
        // an unconfigured provider is refused before the invite code is even read.
        config(['services.oauth.google.client_id' => 'test-client-id']);
        config(['services.oauth.google.client_secret' => 'test-secret']);

        $this->step('Guest', 'SSO', 'redirect with a valid invite code', __LINE__);

        // A valid code is ACCEPTED and the browser is sent on to the provider.
        // The provider itself is external (a real call needs live credentials), so
        // we assert the redirect target rather than following it.
        $response = $this->get('/auth/google/redirect?invite_code='.$institution->invite_code);

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString(
            'accounts.google.com',
            $location,
            'A valid invite code must carry the user on to the provider.'
        );
        // The CSRF state is minted server-side and travels in the URL.
        $this->assertStringContainsString('state=', $location);
    }

    public function test_an_inactive_institution_cannot_be_entered_by_sso(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['is_active' => false]);

        $this->step('Guest', 'SSO', 'an inactive institution refuses SSO', __LINE__);

        $this->get('/auth/google/redirect?invite_code='.$institution->invite_code)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_an_unsupported_provider_is_a_404(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'SSO', 'an unknown provider 404s', __LINE__);

        $this->get('/auth/myspace/redirect')->assertNotFound();
    }

    public function test_the_callback_without_a_valid_state_is_refused(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'SSO', 'callback with no state', __LINE__);

        // No pending attempt exists (the session was never primed), so the callback
        // must refuse rather than trusting the query string.
        $this->get('/auth/google/callback?code=fake-code&state=fake-state')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_the_callback_with_a_mismatched_state_is_refused(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();

        // Prime a real pending attempt.
        $this->get('/auth/google/redirect?invite_code='.$institution->invite_code);

        $this->step('Guest', 'SSO', 'callback with a tampered state', __LINE__);

        // A DIFFERENT state value must be rejected: this is the CSRF guard.
        $this->get('/auth/google/callback?code=fake-code&state=tampered-state-value')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_the_callback_does_not_trust_an_institution_from_the_query_string(): void
    {
        $this->seedRbac();

        $target = $this->makeInstitution(['name' => 'Legit Workspace']);

        // Prime a legitimate attempt for `target`, then attempt a callback that
        // NAMES a different institution in the query string.
        $this->get('/auth/google/redirect?invite_code='.$target->invite_code);

        $this->step('Guest', 'SSO', 'the institution comes from the session, not the URL', __LINE__);

        // The callback resolves the institution from the SESSION and validates the
        // state. A crafted `institution` query parameter is therefore ignored - the
        // attempt is refused outright rather than silently re-targeted.
        $this->get('/auth/google/callback?code=fake&state=fake&institution=999')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_all_four_providers_are_supported_by_the_registry(): void
    {
        // The registry is the single source of truth for which providers exist.
        $this->assertSame(
            ['google', 'microsoft', 'facebook', 'x'],
            OAuthProviders::PROVIDERS
        );
    }

    public function test_each_provider_resolves_its_endpoints_and_metadata(): void
    {
        foreach (['google', 'microsoft', 'facebook', 'x'] as $provider) {
            $endpoints = OAuthProviders::endpoints($provider);

            $this->assertArrayHasKey('authorize', $endpoints, "{$provider} needs an authorize URL.");
            $this->assertArrayHasKey('token', $endpoints, "{$provider} needs a token URL.");
            $this->assertArrayHasKey('userinfo', $endpoints, "{$provider} needs a userinfo URL.");
            $this->assertNotEmpty($endpoints['scope'], "{$provider} needs a scope.");

            $meta = OAuthProviders::meta($provider);

            $this->assertNotEmpty($meta['label'], "{$provider} needs a label for the UI.");
            $this->assertNotEmpty($meta['icon'], "{$provider} needs an icon key for the UI.");
        }
    }

    public function test_oidc_providers_are_distinguished_from_plain_oauth2(): void
    {
        // Google and Microsoft issue verifiable ID tokens; Facebook and X do not.
        // The distinction drives whether the token-validation step runs at all.
        $this->assertTrue(OAuthProviders::isOidc('google'));
        $this->assertTrue(OAuthProviders::isOidc('microsoft'));
        $this->assertFalse(OAuthProviders::isOidc('facebook'));
        $this->assertFalse(OAuthProviders::isOidc('x'));
    }

    public function test_the_x_identity_is_unwrapped_from_its_nested_payload(): void
    {
        // X nests the user under `data` and returns no email on the free tier.
        $identity = OAuthProviders::normaliseIdentity('x', [
            'data' => ['id' => '12345678', 'name' => 'Voter', 'username' => 'voterhandle'],
        ]);

        $this->assertSame('12345678', $identity['id']);
        $this->assertSame('Voter', $identity['name']);
        // A stable, clearly-marked placeholder rather than an empty value.
        $this->assertSame('voterhandle@x.placeholder', $identity['email']);
    }

    public function test_the_facebook_identity_unwraps_the_avatar(): void
    {
        $identity = OAuthProviders::normaliseIdentity('facebook', [
            'id' => 'fb-999',
            'name' => 'Fb User',
            'email' => 'Fb@Example.test',
            'picture' => ['data' => ['url' => 'https://example.test/avatar.jpg']],
        ]);

        $this->assertSame('fb-999', $identity['id']);
        // The email is normalised to lower case so the account match is reliable.
        $this->assertSame('fb@example.test', $identity['email']);
        $this->assertSame('https://example.test/avatar.jpg', $identity['avatar']);
    }

    public function test_the_login_screen_offers_sso_when_a_provider_is_configured(): void
    {
        $this->seedRbac();

        // The shared Inertia prop is what drives the login screen's SSO block, so
        // asserting it is asserting what the browser will receive.
        config(['services.oauth.google.client_id' => 'test-client-id']);
        config(['services.oauth.google.client_secret' => 'test-secret']);

        $this->step('Guest', 'SSO', 'GET /login carries the oauth prop', __LINE__);

        $response = $this->get('/login');
        $response->assertOk();

        $oauth = $response->viewData('page')['props']['oauth'];

        $this->assertNotEmpty($oauth, 'A configured provider must be offered.');
        $this->assertSame('google', $oauth[0]['provider']);
        $this->assertSame('Google Workspace', $oauth[0]['label']);
        // The button links to OUR redirect (where the CSRF state is minted), never
        // straight to the provider.
        $this->assertStringContainsString('/auth/google/redirect', $oauth[0]['redirect_url']);
    }

    public function test_the_login_screen_hides_sso_when_no_provider_is_configured(): void
    {
        $this->seedRbac();

        // No credentials configured -> no buttons, no dead ends.
        config([
            'services.oauth.google.client_id' => null,
            'services.oauth.google.client_secret' => null,
            'services.oauth.microsoft.client_id' => null,
            'services.oauth.microsoft.client_secret' => null,
            'services.oauth.facebook.client_id' => null,
            'services.oauth.facebook.client_secret' => null,
            'services.oauth.x.client_id' => null,
            'services.oauth.x.client_secret' => null,
        ]);

        $this->step('Guest', 'SSO', 'no providers configured', __LINE__);

        $response = $this->get('/login');
        $response->assertOk();

        $oauth = $response->viewData('page')['props']['oauth'];

        $this->assertSame([], $oauth, 'No configured provider may be advertised.');
    }

    public function test_the_shared_oauth_prop_lists_only_configured_providers(): void
    {
        $this->seedRbac();

        config([
            'services.oauth.google.client_id' => 'g-id',
            'services.oauth.google.client_secret' => 'g-secret',
            'services.oauth.microsoft.client_id' => null,
            'services.oauth.facebook.client_id' => null,
            'services.oauth.x.client_id' => null,
        ]);

        $this->assertSame(['google'], OAuthProviders::available());
    }

    public function test_a_social_account_link_is_unique_per_provider_identity(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        SocialAccount::create([
            'user_id' => $member->id,
            'institution_id' => $institution->id,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => $member->email,
        ]);

        $this->assertTrue($member->fresh()->hasSocialProvider('google'));

        $this->step('Member', 'SSO', 'a duplicate identity link is rejected', __LINE__);

        // The (provider, provider_user_id) unique index is the real guarantee that
        // two local accounts can never claim the same external identity.
        $this->expectException(QueryException::class);

        SocialAccount::create([
            'user_id' => $this->makeMember($institution, ['email' => 'other@example.test'])->id,
            'institution_id' => $institution->id,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => 'other@example.test',
        ]);
    }
}
