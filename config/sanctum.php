<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Sanctum (mobile API tokens) — published configuration
|--------------------------------------------------------------------------
|
| This file was previously ABSENT, so the framework's built-in defaults were
| used silently — including `expiration => null`, i.e. personal access tokens
| that NEVER expire. Publishing it pins every value explicitly so the token
| lifetime is a deliberate production decision rather than an accident.
|
| The mobile client authenticates with bearer tokens (it does NOT use the
| stateful SPA cookie flow), so only `guard` and `expiration` really matter
| here. `stateful` is kept for parity with the framework default in case a
| first-party web SPA is added later.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these domains/hosts get stateful (cookie) API auth. The
    | mobile app never relies on this — it sends `Authorization: Bearer ...` —
    | but keeping it correct means a future Inertia SPA could use the same API.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Guards checked before falling back to the bearer token. `web` is the only
    | guard registered in config/auth.php; the `sanctum` guard is injected by
    | the framework at runtime.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | How long an issued token stays valid. DEFAULT: 30 days (43,200 minutes).
    |
    | This is a deliberate SECURITY change from the framework default of `null`
    | (never expires): a leaked mobile token can no longer be replayed forever.
    | Override per environment with SANCTUM_EXPIRATION (set `null` to opt back
    | into non-expiring tokens — NOT recommended).
    |
    */

    'expiration' => env('SANCTUM_EXPIRATION', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Optional prefix so tokens are recognisable to secret-scanning tools.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware Sanctum uses when authenticating a first-party SPA. Left at the
    | framework defaults; the mobile bearer-token flow does not exercise these.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
