<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP Live Connection Check
    |--------------------------------------------------------------------------
    |
    | Whether the SMTP settings module may open a REAL socket to the relay when
    | saving or when the SSA clicks "Check connection". Enabled by default so an
    | operator gets a truthful verdict; disabled by the automated test suite so a
    | save never performs live network I/O (see SmtpConnectionChecker::enabled).
    */
    'smtp' => [
        'live_check' => env('SMTP_LIVE_CHECK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSO / OAUTH (Google Workspace + Microsoft Entra)
    |--------------------------------------------------------------------------
    |
    | Per-provider OAuth2 credentials for institutional single sign-on. A
    | provider is only OFFERED on the login screen when BOTH its client id and
    | secret are present, so an unconfigured deployment simply hides the button
    | rather than presenting a dead end.
    |
    | Register the callback URL with each provider exactly as:
    |   {APP_URL}/auth/{provider}/callback
    */
    'oauth' => [
        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        ],
        'microsoft' => [
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
            'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
            // 'common' accepts any work/school account; narrow it to a single
            // directory id to lock authentication to one organization.
            'tenant' => env('MICROSOFT_TENANT', 'common'),
        ],
        // Facebook and X are plain OAuth2 (no OIDC ID token): the identity comes
        // from a userinfo call, so their client id/secret are all that is needed.
        'facebook' => [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        ],
        'x' => [
            'client_id' => env('X_CLIENT_ID'),
            'client_secret' => env('X_CLIENT_SECRET'),
            'redirect_uri' => env('X_REDIRECT_URI'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI / RAG Forecasting
    |--------------------------------------------------------------------------
    |
    | The forecaster converts historical days into vector embeddings and
    | retrieves the most similar ones (RAG). `min_history_months` is the point
    | below which there is not enough data to retrieve from and the engine falls
    | back to country benchmarks instead of inventing a number.
    */
    'forecasting' => [
        'min_history_months' => env('FORECAST_MIN_HISTORY_MONTHS', 3),
        'history_months' => env('FORECAST_HISTORY_MONTHS', 6),
        'dimensions' => env('FORECAST_VECTOR_DIMENSIONS', 8),
        'default_country' => env('FORECAST_DEFAULT_COUNTRY', 'BD'),
    ],

];
