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
                ] : null,
                'roles' => $user ? $user->getRoleNames()->toArray() : [],
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->toArray() : [],
            ],

            // Flash messages, so "Deposit recorded" style feedback reaches the
            // UI. Without this, every ->with('success', ...) was invisible.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
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