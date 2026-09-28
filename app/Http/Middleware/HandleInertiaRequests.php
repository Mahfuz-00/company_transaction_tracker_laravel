<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Cached for the request so every page shares one lookup rather than
     * re-querying the institutions table per prop.
     */
    protected ?Institution $institution = null;

    protected function institution(): ?Institution
    {
        return $this->institution ??= Institution::current();
    }

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatarUrl(),
                    'designation' => $user->designation,
                    // Drives the two-tier admin UI (global vs institution).
                    'institution_id' => $user->institution_id,
                    'is_super_admin' => $user->isSuperAdmin(),
                    // The DATABASE half of the dual-persistence theme. ThemeProvider
                    // re-hydrates from this on any new device; localStorage keeps
                    // the instant, PC-local copy. Both speak the same tokens.
                    'theme' => $user->themeSettings(),
                ] : null,
                'roles' => $user ? $user->getRoleNames()->toArray() : [],
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->toArray() : [],
            ],

            // In-app notifications, shared so the header bell shows the unread
            // badge on every page without an extra request. Kept small - the full
            // list lives on the notifications page.
            'notifications' => function () use ($user) {
                if (! $user) {
                    return ['unreadCount' => 0, 'items' => []];
                }

                return [
                    'unreadCount' => $user->unreadNotifications()->count(),
                    'items' => $user->notifications()
                        ->orderByDesc('created_at')
                        ->limit(6)
                        ->get()
                        ->map(fn ($n) => [
                            'id' => $n->id,
                            'kind' => $n->data['kind'] ?? 'notice',
                            'title' => $n->data['title'] ?? 'Notification',
                            'body' => $n->data['body'] ?? '',
                            'url' => $n->data['meta']['url'] ?? null,
                            'read' => $n->read_at !== null,
                            'created_human' => $n->created_at?->diffForHumans(),
                        ])
                        ->all(),
                ];
            },

            // Flash messages, so "Deposit recorded" style feedback reaches the
            // UI. Without this, every ->with('success', ...) was invisible.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],

            // PLATFORM BRANDING (single source of truth).
            //
            // The master software name + chrome labels, read by the landing
            // header, the login top bar and the SSA sidebar through the
            // usePlatformBranding() hook. Changing config/platform.php updates
            // all three at once.
            'platform' => fn () => array_merge(\App\Support\PlatformBranding::toArray(), [
                // SSA credential guardrails (see config/platform.php). The Profile
                // Manager reads these to hide/lock the password form when an
                // operator has bound SSA credentials to the CLI/seeder path.
                'ssa_profile_editable' => (bool) config('platform.profile_editable', true),
                'ssa_self_service_password' => (bool) config('platform.allow_self_service_password', true),
            ]),

            // Tenant context: whether the current user is viewing a workspace via
            // an SSA "switched view" (so the UI can show / clear it).
            'tenant' => fn () => [
                'active_id' => $this->institution()?->id,
                // True only when a session tenant is set AND it differs from the
                // user's own institution - i.e. an SSA is looking at another
                // workspace and can "exit" back to the platform view.
                'switched' => Institution::sessionTenantId() !== null
                    && Institution::sessionTenantId() !== $request->user()?->institution_id,
                'can_switch' => (bool) $request->user()?->isSuperAdmin(),
            ],

            /*
             * MANAGEMENT CONTEXT (context-scope guard).
             *
             * The reported leak: an SSA who switches INTO a workspace and then
             * opens that institution's admin / profile / management sections saw
             * their OWN name, email and institution pre-filled in the target
             * workspace's forms - because those forms read the shared `auth.user`
             * prop, which is ALWAYS the signed-in operator.
             *
             * This block tells the UI, unambiguously, whose identity the shared
             * `auth.user` represents, and whether the current view is a management
             * view of ANOTHER workspace. Components that must show the TARGET
             * workspace's data (never the operator's) key off `acting_for_tenant`
             * and stop rendering operator credentials into tenant-scoped forms.
             */
            'viewingAs' => fn () => [
                // The signed-in operator is the SSA and has switched into a tenant
                // that is NOT their own institution.
                'is_impersonating' => $request->user()?->isSuperAdmin() === true
                    && Institution::sessionTenantId() !== null
                    && Institution::sessionTenantId() !== $request->user()?->institution_id,
                // The identity the shared `auth.user` actually belongs to.
                'operator_id' => $request->user()?->id,
                'operator_name' => $request->user()?->name,
                // The workspace being managed (null on the platform view).
                'target_institution_id' => $this->institution()?->id,
                'target_institution_name' => $this->institution()?->name,
            ],

            // Institution identity + resolved terminology, so any component can
            // render "Employees" instead of "Students" without its own lookup.
            'institution' => fn () => $this->institution()
                ? [
                    'id' => $this->institution()->id,
                    'name' => $this->institution()->name,
                    'subtitle' => $this->institution()->subtitle,
                    'type' => $this->institution()->type,
                    'type_label' => $this->institution()->typeLabel(),
                    'currency_code' => $this->institution()->currency_code,
                    // The workspace timezone, so any page can render dates in the
                    // institution's own local time (and the Settings dropdown can
                    // show the currently-saved value).
                    'timezone' => $this->institution()->timezone,
                    'terms' => $this->institution()->terminologyMap(),
                    'logo_url' => $this->institution()->logoUrl(),
                    'banner_url' => $this->institution()->bannerUrl(),
                    // Accent/mode/radius, applied as CSS variables by the app shell.
                    'theme' => $this->institution()->themeSettings(),
                    'accent' => $this->institution()->accentPalette(),
                ]
                : null,

            // Global currency config managed by the Software Super Admin.
            // Shared on every response so formatting is identical everywhere
            // without a per-page lookup or a localStorage round-trip.
            'currency' => fn () => $this->institution()
                ? $this->institution()->currencySettings()
                : Institution::DEFAULT_CURRENCY_SETTINGS,

            /*
             * FIRST-TIME ONBOARDING.
             *
             * `show` is true only until the account has completed (or dismissed)
             * the guide, so the modal appears exactly once per user. The guide
             * content is ROLE-SPECIFIC (SSA / Institution Admin / Meal Manager /
             * Member) and shipped in the same payload, so the modal renders on the
             * first paint with no extra request.
             */
            'onboarding' => fn () => [
                'show' => (bool) ($user && $user->shouldSeeOnboarding()),
                'role' => $user?->onboardingRole(),
                'guide' => $user ? \App\Support\OnboardingGuide::for($user) : null,
            ],

            /*
             * SSO / OAUTH PROVIDERS.
             *
             * The login screen renders a button per ENTRY HERE - and the list only
             * contains providers that are actually configured (client id + secret),
             * so a deployment without Google/Microsoft credentials shows no dead
             * buttons.
             */
            'oauth' => fn () => collect(\App\Support\OAuthProviders::available())
                ->map(fn (string $provider) => [
                    'provider' => $provider,
                    'label' => \App\Support\OAuthProviders::meta($provider)['label'],
                    'short' => \App\Support\OAuthProviders::meta($provider)['short'],
                    'icon' => \App\Support\OAuthProviders::meta($provider)['icon'],
                    'redirect_url' => route('oauth.redirect', ['provider' => $provider]),
                ])
                ->values()
                ->all(),

            /*
             * The signed-in user's linked external identities, so the Profile
             * Manager can show what is connected and offer an unlink action.
             */
            'socialAccounts' => fn () => $user
                ? $user->socialAccounts()->get()->map(fn ($link) => [
                    'id' => $link->id,
                    'provider' => $link->provider,
                    'label' => \App\Models\SocialAccount::label($link->provider),
                    'email' => $link->email,
                    'tenant_id' => $link->tenant_id,
                    'linked_at' => $link->created_at?->format('j M Y'),
                ])->all()
                : [],
        ]);
    }
}
