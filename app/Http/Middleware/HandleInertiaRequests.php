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
                    'type' => $this->institution()->type,
                    'type_label' => $this->institution()->typeLabel(),
                    'currency_code' => $this->institution()->currency_code,
                    'terms' => $this->institution()->terminologyMap(),
                ]
                : null,
        ]);
    }
}