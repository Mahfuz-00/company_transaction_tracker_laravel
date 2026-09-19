<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Branding (single source of truth)
    |--------------------------------------------------------------------------
    |
    | The master software name shown on the PUBLIC pages (landing + login) and in
    | the Software Super Admin's sidebar header. Change these values here and the
    | landing header, login top bar and SSA sidebar all update - they all read
    | this file through App\Support\PlatformBranding (shared to the frontend via
    | the `platform` Inertia prop and the usePlatformBranding() hook).
    |
    | These are PLATFORM values, deliberately distinct from any tenant
    | institution's own name/logo: the SSA and public visitors must never see a
    | single institution's branding in the global chrome.
    |
    */

    'name' => env('PLATFORM_NAME', 'SaaS Control Center'),

    // The short line under the name in headers.
    'tagline' => env('PLATFORM_TAGLINE', 'Multi-institution meal & expense platform'),

    // The label for the SSA's own portal subtitle.
    'admin_subtitle' => env('PLATFORM_ADMIN_SUBTITLE', 'Super Admin Portal'),

    // The SSA sidebar heading (kept separate so it can read differently from the
    // public marketing name if you ever want that).
    'control_center' => env('PLATFORM_CONTROL_CENTER', 'SaaS Control Center'),

    // Optional platform-wide logo path (public disk). Null falls back to the app
    // logo component.
    'logo_path' => env('PLATFORM_LOGO_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Software Super Admin credential guardrails
    |--------------------------------------------------------------------------
    |
    | The SSA always has a working Profile Manager (name, avatar, password) - the
    | normal, self-service path. These switches let an operator LOCK THAT DOWN when
    | the platform is regulated or hosted by a third party and SSA credentials must
    | move ONLY through environment/CLI control.
    |
    | Everything here is enforced centrally by App\Support\PasswordGuard, so there
    | is no per-controller code to audit.
    |
    |   profile_editable
    |     true  (default) - the SSA may edit their profile + change their password
    |                       from the Profile Manager.
    |     false           - the SSA's profile fields are read-only in the UI, and a
    |                       self-service password change is refused. Rotate the
    |                       credential with `php artisan ssa:reset-password` (or a
    |                       seeder) instead.
    |
    |   allow_self_service_password
    |     true  (default) - the SSA may change their own password.
    |     false           - the SSA password can ONLY move via the authorised CLI
    |                       reset. Even a self-service request is refused.
    |
    */

    'profile_editable' => env('PLATFORM_SSA_PROFILE_EDITABLE', true),

    'allow_self_service_password' => env('PLATFORM_SSA_ALLOW_SELF_SERVICE_PASSWORD', true),

];
