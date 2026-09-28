<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\OAuthProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * SSO / OAUTH SIGN-IN (Google Workspace + Microsoft Entra).
 *
 * THE FLOW
 *   1. GET  /auth/{provider}/redirect  - stash a random `state`, send the browser
 *      to the provider's authorization endpoint.
 *   2. GET  /auth/{provider}/callback  - verify `state`, exchange the code for
 *      tokens, read the identity, then MATCH OR CREATE a local user.
 *
 * INSTITUTION SCOPING - the important part
 * ----------------------------------------
 * An institution wants its staff arriving through ITS directory. Two guards make
 * that real:
 *
 *   - `institution` may be passed to the redirect (e.g. from that institution's
 *     login screen). It is remembered in the session and enforced on callback.
 *   - When the target institution has an `sso_domain` configured, the asserted
 *     email MUST belong to that domain. A user from another company's Google
 *     Workspace cannot sign into this workspace just because they have a link.
 *
 * ACCOUNT MATCHING (in order)
 *   1. An existing social link for (provider, provider_user_id) - the normal
 *      returning case.
 *   2. An existing local user with the SAME verified email - linked on first SSO
 *      sign-in, so a user who already has a password history keeps one identity.
 *   3. Otherwise the account is REFUSED unless the institution was resolved from a
 *      valid INVITE CODE. We never silently create a global account, and never one
 *      with a privileged role.
 *
 * INVITE-CODE GATE (the multi-tenant requirement)
 * -----------------------------------------------
 * `/auth/{provider}/redirect?invite_code=ABC123XY` validates the code BEFORE any
 * provider round-trip, so a bad code fails fast with a precise message, and a good
 * one pins the sign-in to that workspace. The resolved institution is remembered in
 * the session and re-checked on the callback (the query string is never trusted).
 *
 * SUPPORTED PROVIDERS: Google Workspace, Microsoft Entra (both OIDC), plus Facebook
 * and X (plain OAuth2, identity read from userinfo).
 *
 * SECURITY
 *   - `state` is a 40-char random string, session-stored and single-use.
 *   - The identity is keyed on the stable subject (`sub`/`oid`/`id`), never the email.
 *   - Roles are assigned through `assignInstitutionRole()`, so SSO can never mint
 *     a Software Super Admin.
 */
class SocialAuthController extends Controller
{
    /** Where we stash the pending SSO attempt between redirect and callback. */
    protected const SESSION_KEY = 'oauth.pending';

    /**
     * Step 1: send the browser to the provider.
     *
     * THE INVITE-CODE GATE
     * --------------------
     * An institution invite code may be supplied (`?invite_code=ABC123XY`). When it
     * is, it is validated BEFORE we ever contact the provider:
     *
     *   - an UNKNOWN code fails immediately, so a typo never costs a full round-trip
     *     through Google/Microsoft/Facebook/X,
     *   - a known code pins the sign-in to THAT workspace, so the user lands in the
     *     correct tenant rather than an ambiguous one,
     *   - the resolved institution id is remembered in the session and re-used on the
     *     callback (never re-read from the query string, which the user controls).
     *
     * This is what makes SSO safe on a multi-institution platform: without it, an
     * unknown external identity could not be placed in any workspace.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        if (! OAuthProviders::isConfigured($provider)) {
            return redirect()->route('login')->with(
                'error',
                OAuthProviders::meta($provider)['label'].' sign-in is not configured on this deployment.'
            );
        }

        /*
         * INSTITUTION RESOLUTION.
         *
         * The code wins when present (it is the strongest signal of intent); an
         * explicit `institution` id is the fallback for a sign-in launched from an
         * institution's own page.
         */
        $inviteCode = trim((string) $request->query('invite_code', ''));
        $institution = null;

        if ($inviteCode !== '') {
            $institution = Institution::findByInviteCode($inviteCode);

            if (! $institution) {
                // Fail BEFORE the provider round-trip, with a message that names the
                // problem precisely so the user can correct the code and retry.
                return redirect()->route('login')->with('error',
                    'That institution invite code is not valid. Check the code with your administrator and try again.'
                );
            }

            if (! $institution->is_active) {
                return redirect()->route('login')->with('error',
                    'That institution is not currently accepting sign-ins.'
                );
            }
        } elseif ($request->filled('institution')) {
            $institution = Institution::find($request->integer('institution'));
        }

        // A random, single-use token that ties the callback to THIS browser.
        $state = Str::random(40);

        // Where to land the user after a successful sign-in.
        $intended = $request->query('intended');

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider,
            'state' => $state,
            'institution_id' => $institution?->id,
            // Remembered so the callback can log WHICH code was used, and so the
            // domain guard knows which workspace was intended.
            'invite_code' => $institution?->invite_code,
            'intended' => $intended,
            'started_at' => now()->timestamp,
        ]);

        return redirect()->away(OAuthProviders::authorizeUrl($provider, $state));
    }

    /**
     * Step 2: handle the provider's callback.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        $pending = (array) $request->session()->pull(self::SESSION_KEY, []);

        // 1. STATE CHECK - the CSRF guard for the redirect. A mismatch means the
        //    callback did not originate from a redirect WE issued.
        $returnedState = (string) $request->query('state', '');

        if (
            empty($pending['state'])
            || $returnedState === ''
            || ! hash_equals((string) $pending['state'], $returnedState)
        ) {
            return $this->fail('Your sign-in session expired or was tampered with. Please try again.');
        }

        // The provider may report a user-facing error (e.g. consent denied).
        if ($request->query('error')) {
            return $this->fail('Sign-in was cancelled: '.$request->query('error_description', $request->query('error')));
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->fail('The identity provider did not return an authorization code.');
        }

        // 2. Resolve the institution the user was heading into, if any.
        //
        //    The id comes from the SESSION (written at redirect time from a
        //    validated invite code), never from the callback query - which the
        //    browser controls and could be tampered with.
        $institution = filled($pending['institution_id'] ?? null)
            ? Institution::find((int) $pending['institution_id'])
            : null;

        try {
            $tokens = OAuthProviders::exchangeCode($provider, $code);
            $identity = OAuthProviders::fetchIdentity($provider, $tokens);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('We could not complete the sign-in with '.OAuthProviders::meta($provider)['short'].'. '.$e->getMessage());
        }

        if (($identity['id'] ?? '') === '') {
            return $this->fail('The identity provider did not return a usable account identifier.');
        }

        /*
         * 2b. INVITE-CODE GUARD (re-checked here).
         *
         * The code was validated before the provider round-trip, but the institution
         * may have been deactivated in the meantime - and a callback can arrive long
         * after the redirect. Re-checking closes that window.
         */
        if ($institution && ! $institution->is_active) {
            return $this->fail('That institution is no longer accepting sign-ins.');
        }

        // 3. DOMAIN GUARD - an institution that names an SSO domain only accepts
        //    identities from it. This is what stops "any Google account" signing
        //    into a specific workspace.
        if ($institution && filled($institution->sso_domain)) {
            if (! $this->emailMatchesDomain($identity['email'], $institution->sso_domain)) {
                AuditLogger::log('updated', "refused an SSO sign-in from outside {$institution->sso_domain}", null, [
                    'provider' => $provider,
                    'email' => $identity['email'],
                    'institution_id' => $institution->id,
                ], ['subject_label' => $identity['email'], 'institution_id' => $institution->id]);

                return $this->fail(
                    'That account is not part of '.$institution->name."'s ".$institution->sso_domain
                    .' domain. Sign in with your institution account, or use your password.'
                );
            }
        }

        // 4. Match or provision the local account.
        [$user, $error] = $this->resolveUser($provider, $identity, $institution, $tokens);

        if ($error !== null) {
            return $this->fail($error);
        }

        // 5. An inactive account must not be let in by ANY route.
        if (! $user->isActive()) {
            return $this->fail('Your account has been deactivated. Please contact your administrator.');
        }

        // 6. Sign in.
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        AuditLogger::log('login', "signed in with {$provider}", $user, [
            'provider' => $provider,
            'email' => $identity['email'],
        ], ['subject_label' => $user->name, 'institution_id' => $user->institution_id]);

        // Honour the page the user originally wanted, else the dashboard.
        $intended = $pending['intended'] ?? null;

        if (is_string($intended) && str_starts_with($intended, '/')) {
            return redirect($intended);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Find, link or refuse the local account for this external identity.
     *
     * @return array{0:?User, 1:?string} [user, errorMessage]
     */
    protected function resolveUser(string $provider, array $identity, ?Institution $institution, array $tokens): array
    {
        // --- 1. An existing link (the returning user). ---------------------
        $link = SocialAccount::withoutTenantScope()
            ->where('provider', $provider)
            ->where('provider_user_id', $identity['id'])
            ->first();

        if ($link) {
            $user = User::find($link->user_id);

            if (! $user) {
                // The local account was deleted; drop the stale link and fall
                // through to the email match below.
                $link->delete();
            } else {
                $this->touchLink($link, $identity, $tokens);

                return [$user, null];
            }
        }

        // --- 2. An existing local account with the same verified email. -----
        if (filled($identity['email'])) {
            $existing = User::where('email', $identity['email'])->first();

            if ($existing) {
                $this->linkAccount($existing, $provider, $identity, $institution, $tokens);

                return [$existing, null];
            }
        }

        // --- 3. No local account: only provision inside a known institution. -
        if (! $institution) {
            return [null, 'We could not find an account for that identity. Ask your administrator to invite you, or sign in with your password.'];
        }

        // The institution must be accepting members at all.
        if (! $institution->is_active) {
            return [null, 'That institution is not currently accepting sign-ins.'];
        }

        // SAFETY: an account is created as a MEMBER only. SSO can never confer a
        // privileged role - an admin must promote them deliberately afterwards.
        $user = User::create([
            'institution_id' => $institution->id,
            'name' => $identity['name'] ?: ($identity['email'] ?: 'SSO user'),
            'email' => $identity['email'],
            'status' => 'active',
            'setup_completed_at' => now(),
            // No password: this account authenticates ONLY through the provider,
            // so there is no credential to leak or guess.
            'password' => null,
        ]);

        $user->assignInstitutionRole('Member');

        $this->linkAccount($user, $provider, $identity, $institution, $tokens);

        AuditLogger::log('created', "provisioned an account from {$provider} SSO", $user, [
            'provider' => $provider,
            'email' => $identity['email'],
        ], ['subject_label' => $user->name, 'institution_id' => $institution->id]);

        return [$user, null];
    }

    /** Create the link row between a local user and an external identity. */
    protected function linkAccount(User $user, string $provider, array $identity, ?Institution $institution, array $tokens): SocialAccount
    {
        return SocialAccount::withoutTenantScope()->updateOrCreate(
            [
                'provider' => $provider,
                'provider_user_id' => $identity['id'],
            ],
            [
                'user_id' => $user->id,
                // Prefer the institution the sign-in targeted, else the user's own.
                'institution_id' => $institution?->id ?? $user->institution_id,
                'email' => $identity['email'],
                'tenant_id' => $identity['tenant'],
                'name' => $identity['name'],
                'avatar_url' => $identity['avatar'],
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'last_login_at' => now(),
            ]
        );
    }

    /** Refresh an existing link's stored tokens / profile snapshot. */
    protected function touchLink(SocialAccount $link, array $identity, array $tokens): void
    {
        $link->forceFill([
            'email' => $identity['email'] ?: $link->email,
            'name' => $identity['name'] ?: $link->name,
            'avatar_url' => $identity['avatar'] ?: $link->avatar_url,
            'tenant_id' => $identity['tenant'] ?: $link->tenant_id,
            'access_token' => $tokens['access_token'] ?? $link->access_token,
            'refresh_token' => $tokens['refresh_token'] ?? $link->refresh_token,
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : $link->token_expires_at,
            'last_login_at' => now(),
        ])->save();
    }

    /** Does the email belong to the given domain (or its subdomains)? */
    protected function emailMatchesDomain(?string $email, ?string $domain): bool
    {
        if (blank($email) || blank($domain)) {
            return false;
        }

        $emailDomain = strtolower(Str::after($email, '@'));
        $expected = strtolower(trim($domain));

        return $emailDomain === $expected || str_ends_with($emailDomain, '.'.$expected);
    }

    /** Guard against an unsupported provider in the URL. */
    protected function ensureProviderIsSupported(string $provider): void
    {
        abort_unless(in_array($provider, OAuthProviders::PROVIDERS, true), 404);
    }

    /** Redirect back to login with a readable error. */
    protected function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }

    /**
     * UNLINK a provider from the signed-in account (Profile Manager).
     *
     * Refused when it is the account's ONLY way in - removing the last sign-in
     * method would lock the user out permanently.
     */
    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        $user = $request->user();

        $link = $user->socialAccounts()->where('provider', $provider)->first();

        if (! $link) {
            return back()->with('error', 'That sign-in method is not linked to your account.');
        }

        // Would removing this leave the user with no way to authenticate?
        $otherLinks = $user->socialAccounts()->where('provider', '!=', $provider)->count();

        if (blank($user->password) && $otherLinks === 0) {
            return back()->with(
                'error',
                'You cannot remove your only sign-in method. Set a password first, then unlink.'
            );
        }

        $link->delete();

        AuditLogger::log('updated', "unlinked {$provider} sign-in", $user, ['provider' => $provider], [
            'subject_label' => $user->name,
            'institution_id' => $user->institution_id,
        ]);

        return back()->with('success', OAuthProviders::meta($provider)['label'].' has been unlinked from your account.');
    }
}
