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
        ]);
    }
}
