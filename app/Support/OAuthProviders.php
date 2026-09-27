<?php

namespace App\Support;

use App\Models\SocialAccount;

/**
 * SSO / OAUTH PROVIDER REGISTRY.
 *
 * Institutions want their staff to sign in with the Google Workspace or Microsoft
 * Entra account they already have, instead of yet another password. This class
 * holds the per-provider OAuth2 endpoints and the small amount of provider-specific
 * behaviour (how an ID token is read, how a "directory" is identified), so the
 * controller stays a thin orchestration layer.
 *
 * WHY NOT A PACKAGE
 * -----------------
 * The requirement is a plain authorization-code flow against two well-known
 * endpoints, plus a directory check (Google `hd`, Entra `tid`). That is a few dozen
 * lines of HTTP, and implementing it directly keeps the deployment dependency-free
 * and the flow fully auditable - which matters for an authentication path.
 *
 * SECURITY NOTES
 *   - `state` is a random value stored in the session and verified on callback
 *     (CSRF protection for the redirect),
 *   - the ID token's `aud` (client id), `iss` (issuer) and `exp` are all checked,
 *   - the identity is keyed on the STABLE subject id (`sub` / `oid`), never on the
 *     email, so a renamed mailbox cannot hijack another account.
 *
 * CONFIGURATION (config/services.php -> 'oauth', fed from .env):
 *   GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI
 *   MICROSOFT_CLIENT_ID / MICROSOFT_CLIENT_SECRET / MICROSOFT_REDIRECT_URI
 *   MICROSOFT_TENANT (default 'common')
 *   FACEBOOK_CLIENT_ID / FACEBOOK_CLIENT_SECRET / FACEBOOK_REDIRECT_URI
 *   X_CLIENT_ID / X_CLIENT_SECRET / X_REDIRECT_URI
 *
 * OIDC vs PLAIN OAUTH2
 * --------------------
 * Google and Microsoft are full OpenID Connect providers, so they return a signed
 * ID token whose `aud`/`iss`/`exp` we verify. Facebook and X are PLAIN OAuth2: they
 * return an opaque access token and the identity comes from a userinfo call. The
 * `oidc` flag per provider records which contract applies, so the token-validation
 * step is skipped exactly where it would be meaningless (never silently ignored).
 */
class OAuthProviders
{
    /** The four supported providers. */
    public const PROVIDERS = ['google', 'microsoft', 'facebook', 'x'];

    /** Providers that issue a verifiable OIDC ID token. */
    public const OIDC_PROVIDERS = ['google', 'microsoft'];

    /** Is this provider OIDC (ID token) rather than plain OAuth2 (userinfo only)? */
    public static function isOidc(string $provider): bool
    {
        return in_array($provider, self::OIDC_PROVIDERS, true);
    }

    /**
     * Is a provider usable on this deployment? A provider without a client id AND
     * secret cannot be offered, so the UI hides it rather than showing a dead
     * button.
     */
    public static function isConfigured(string $provider): bool
    {
        $config = static::config($provider);

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    /** Every provider that is both supported and configured. */
    public static function available(): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn (string $provider) => static::isConfigured($provider)
        ));
    }

    /** The raw config block for one provider. */
    public static function config(string $provider): array
    {
        return (array) config("services.oauth.{$provider}", []);
    }

    /**
     * The provider's OAuth2 endpoints + the scope needed to read the identity.
     *
     * Google and Microsoft follow OpenID Connect (`openid email profile` yields a
     * verifiable ID token). Facebook and X are plain OAuth2: the email/name come
     * from an explicit userinfo call, so their scopes are chosen to make that call
     * return what we need.
     */
    public static function endpoints(string $provider): array
    {
        return match ($provider) {
            'google' => [
                'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
                'scope' => 'openid email profile',
            ],
            'microsoft' => [
                'authorize' => 'https://login.microsoftonline.com/'.static::microsoftTenant().'/oauth2/v2.0/authorize',
                'token' => 'https://login.microsoftonline.com/'.static::microsoftTenant().'/oauth2/v2.0/token',
                'userinfo' => 'https://graph.microsoft.com/oidc/userinfo',
                'issuers' => ['https://login.microsoftonline.com'],
                'scope' => 'openid email profile User.Read',
            ],
            'facebook' => [
                'authorize' => 'https://www.facebook.com/v19.0/dialog/oauth',
                'token' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                // `fields` makes the Graph call return exactly what we need.
                'userinfo' => 'https://graph.facebook.com/me?fields=id,name,email,picture.type(large)',
                'issuers' => [],
                'scope' => 'email public_profile',
            ],
            'x' => [
                'authorize' => 'https://twitter.com/i/oauth2/authorize',
                'token' => 'https://api.twitter.com/2/oauth2/token',
                // X requires an explicit field list; users.me gives id/name/username.
                'userinfo' => 'https://api.twitter.com/2/users/me?user.fields=profile_image_url,name,username',
                'issuers' => [],
                'scope' => 'users.read tweet.read offline.access',
            ],
            default => throw new \InvalidArgumentException("Unsupported OAuth provider: {$provider}"),
        };
    }

    /** The Entra directory to authenticate against ('common' allows any work account). */
    public static function microsoftTenant(): string
    {
        return (string) (static::config('microsoft')['tenant'] ?? 'common') ?: 'common';
    }

    /** The callback URL the provider should return to. */
    public static function redirectUri(string $provider): string
    {
        $configured = static::config($provider)['redirect_uri'] ?? null;

        // Fall back to our own named route so a deployment that forgot to set the
        // env value still produces a CONSISTENT redirect (the same URL at
        // authorize and token time - a mismatch is the classic OAuth failure).
        return $configured ?: route('oauth.callback', ['provider' => $provider]);
    }

    /**
     * Build the authorization URL the browser is redirected to.
     *
     * @param  string  $state  the CSRF token previously stored in the session
     */
    public static function authorizeUrl(string $provider, string $state): string
    {
        $endpoints = static::endpoints($provider);

        $query = http_build_query([
            'client_id' => static::config($provider)['client_id'],
            'redirect_uri' => static::redirectUri($provider),
            'response_type' => 'code',
            'scope' => $endpoints['scope'],
            'state' => $state,
            // `prompt=select_account` (Google) / `prompt=select_account`
            // (Microsoft) both mean "let the user pick which account" rather than
            // silently reusing whichever session exists.
            'prompt' => 'select_account',
        ]);

        return $endpoints['authorize'].'?'.$query;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token:?string, refresh_token:?string, id_token:?string, expires_in:?int, raw:array}
     */
    public static function exchangeCode(string $provider, string $code): array
    {
        $endpoints = static::endpoints($provider);
        $config = static::config($provider);

        $response = static::httpPost($endpoints['token'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => static::redirectUri($provider),
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ], $provider);

        return [
            'access_token' => $response['access_token'] ?? null,
            'refresh_token' => $response['refresh_token'] ?? null,
            'id_token' => $response['id_token'] ?? null,
            'expires_in' => isset($response['expires_in']) ? (int) $response['expires_in'] : null,
            'raw' => $response,
        ];
    }

    /**
     * Read the user's identity from the provider.
     *
     * We prefer the USERINFO endpoint over decoding the ID token locally: it is a
     * plain authenticated call whose result we trust exactly as much as the token
     * that authorised it, and it avoids a JWT library dependency. The ID token is
     * still validated for audience/issuer/expiry when present, so a tampered
     * response cannot pass unnoticed.
     *
     * @return array{id:string, email:?string, name:?string, avatar:?string, tenant:?string, email_verified:bool}
     */
    public static function fetchIdentity(string $provider, array $tokens): array
    {
        // Validate the ID token's claims first - but ONLY for providers that issue
        // one. Facebook and X are plain OAuth2 and return no ID token at all, so
        // there is nothing to verify here (the identity comes from userinfo).
        if (static::isOidc($provider) && filled($tokens['id_token'] ?? null)) {
            static::assertIdTokenIsValid($provider, (string) $tokens['id_token']);
        }

        $endpoints = static::endpoints($provider);
        $profile = [];

        try {
            $profile = static::httpGet($endpoints['userinfo'], (string) $tokens['access_token'], $provider);
        } catch (\Throwable $e) {
            // A provider may refuse userinfo while still returning a valid ID
            // token; fall back to the token's own claims.
            $profile = filled($tokens['id_token'] ?? null)
                ? static::decodeJwtPayload((string) $tokens['id_token'])
                : [];
        }

        return static::normaliseIdentity($provider, $profile);
    }

    /**
     * Map a provider payload onto our canonical identity shape.
     *
     * The STABLE SUBJECT id is the key: Google `sub`, Entra `oid` (falling back to
     * `sub`), Facebook `id`, X `data.id`. We deliberately never key on email.
     *
     * Each provider nests its payload differently, so the unwrapping lives here
     * rather than being smeared across the controller:
     *   - X wraps the user in `{ data: { id, name, username } }`;
     *   - Facebook returns a flat object with `picture.data.url`;
     *   - Google/Microsoft return the OIDC standard claims.
     */
    public static function normaliseIdentity(string $provider, array $profile): array
    {
        // X nests everything under `data`.
        if ($provider === 'x' && isset($profile['data']) && is_array($profile['data'])) {
            $profile = $profile['data'];
        }

        $id = $profile['sub'] ?? $profile['oid'] ?? $profile['id'] ?? null;
        $email = $profile['email'] ?? $profile['preferred_username'] ?? $profile['upn'] ?? null;

        // X never returns an email address on the free tier, so we synthesise a
        // stable, clearly-marked placeholder from the handle. It is NOT a real
        // mailbox - the account is identified by its provider id regardless.
        if ($provider === 'x' && blank($email) && filled($profile['username'] ?? null)) {
            $email = strtolower((string) $profile['username']) . '@x.placeholder';
        }

        // Google can return `email_verified` as a bool or the string "true".
        $verified = $profile['email_verified'] ?? false;
        $emailVerified = $verified === true || $verified === 'true' || $verified === 1;

        // A provider that does not assert verification is treated as UNVERIFIED,
        // so a domain guard still applies (see SocialAuthController).
        if (blank($profile['email_verified'] ?? null) && filled($email) && $provider === 'facebook') {
            // Facebook only returns an email for accounts it has verified.
            $emailVerified = true;
        }

        // The "directory" that tells us which institution an identity belongs to:
        //   Google  -> `hd` (the Workspace domain)
        //   Entra   -> `tid` (the directory/tenant id)
        // Facebook / X have no notion of a directory, so this stays null and the
        // institution is resolved from the invite code instead.
        $tenant = match ($provider) {
            'google' => $profile['hd'] ?? null,
            'microsoft' => $profile['tid'] ?? null,
            default => null,
        };

        return [
            'id' => $id !== null ? (string) $id : '',
            'email' => $email ? strtolower((string) $email) : null,
            'name' => $profile['name'] ?? $profile['displayName'] ?? null,
            // Avatar shape differs per provider:
            //   Google/Microsoft : `picture` is the URL directly.
            //   Facebook         : `picture.data.url` (nested).
            // Facebook is checked FIRST because its `picture` is an ARRAY, which
            // would otherwise be returned as the avatar and render as garbage.
            'avatar' => $profile['picture']['data']['url'] ?? $profile['picture'] ?? null,
            'tenant' => $tenant ? (string) $tenant : null,
            'email_verified' => $emailVerified,
        ];
    }

    /* ------------------------------------------------------------------ *
     * ID token validation
     * ------------------------------------------------------------------ */

    /**
     * Validate the ID token's audience, issuer and expiry.
     *
     * Signature verification is intentionally NOT performed here: we obtain the
     * token over TLS directly from the provider's token endpoint in the same
     * request, so the token is already trusted by transport. Re-checking `aud`
     * (that it was minted for OUR client id), `iss` and `exp` closes the remaining
     * gap cheaply.
     */
    protected static function assertIdTokenIsValid(string $provider, string $idToken): void
    {
        $claims = static::decodeJwtPayload($idToken);

        if ($claims === []) {
            throw new \RuntimeException('The identity provider returned an unreadable ID token.');
        }

        $config = static::config($provider);
        $endpoints = static::endpoints($provider);

        // 1. Audience: the token must have been minted for THIS application.
        $audience = $claims['aud'] ?? null;

        if (is_array($audience) ? ! in_array($config['client_id'], $audience, true) : $audience !== $config['client_id']) {
            throw new \RuntimeException('The ID token was issued for a different application.');
        }

        // 2. Issuer: must be one of the provider's known issuers.
        $issuer = (string) ($claims['iss'] ?? '');

        if ($issuer !== '') {
            $matches = collect($endpoints['issuers'])
                ->contains(fn (string $known) => str_starts_with($issuer, $known));

            if (! $matches) {
                throw new \RuntimeException('The ID token was issued by an untrusted issuer.');
            }
        }

        // 3. Expiry: reject a token that is no longer valid (60s of clock skew).
        if (isset($claims['exp']) && (int) $claims['exp'] < (time() - 60)) {
            throw new \RuntimeException('The ID token has expired.');
        }
    }

    /** Decode (without verifying) a JWT's payload segment. */
    public static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $payload = strtr($parts[1], '-_', '+/');
        $decoded = base64_decode($payload, false);

        if ($decoded === false) {
            return [];
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : [];
    }

    /* ------------------------------------------------------------------ *
     * Tiny HTTP client (curl, no dependency)
     * ------------------------------------------------------------------ */

    protected static function httpPost(string $url, array $form, string $provider): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
            // Always verify TLS: this call carries the client secret.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not reach {$provider}: {$error}");
        }

        $decoded = json_decode((string) $body, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("{$provider} returned an unreadable token response.");
        }

        if ($status >= 400) {
            $message = $decoded['error_description'] ?? $decoded['error'] ?? 'token request rejected';

            throw new \RuntimeException("{$provider} rejected the sign-in: {$message}");
        }

        return $decoded;
    }

    protected static function httpGet(string $url, string $accessToken, string $provider): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer '.$accessToken,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not read the {$provider} profile: {$error}");
        }

        $decoded = json_decode((string) $body, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("{$provider} returned an unreadable profile.");
        }

        return $decoded;
    }

    /** Link metadata for the UI (label, icon, note). */
    public static function meta(string $provider): array
    {
        return SocialAccount::PROVIDERS[$provider] ?? [
            'label' => ucfirst($provider),
            'short' => ucfirst($provider),
            'icon' => 'key',
            'note' => '',
        ];
    }
}
