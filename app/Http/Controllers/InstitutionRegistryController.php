<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\User;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Software Super Admin only: a registry of every institution on the platform,
 * each with its administrators and headline figures.
 */
class InstitutionRegistryController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $institutions = Institution::query()
            ->withCount([
                'students as members_count',
                'vendors as vendors_count',
                'subsidies as subsidies_count',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)
                    ->orWhere('type', 'like', $term));
            })
            ->orderBy('name')
            ->get()
            ->map(function (Institution $institution) {
                // Admins attached to this institution.
                $admins = User::query()
                    ->where('institution_id', $institution->id)
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Institution Admin', 'Meal Manager']))
                    ->get(['id', 'name', 'email', 'status', 'designation'])
                    ->map(fn (User $u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'status' => $u->status,
                        'designation' => $u->designation,
                        'avatar_url' => $u->avatarUrl(),
                        'role' => $u->getRoleNames()->first(),
                    ]);

                return [
                    'id' => $institution->id,
                    'name' => $institution->name,
                    'subtitle' => $institution->subtitle,
                    'slug' => $institution->slug,
                    'type' => $institution->type,
                    'type_label' => $institution->typeLabel(),
                    'is_active' => (bool) $institution->is_active,
                    'currency_code' => $institution->currency_code,
                    'logo_url' => $institution->logoUrl(),
                    'accent' => $institution->themeSettings()['accent'],
                    'accent_hex' => $institution->accentPalette()['hex'],
                    'members_count' => $institution->members_count,
                    'vendors_count' => $institution->vendors_count,
                    'subsidies_count' => $institution->subsidies_count,
                    'admins' => $admins,
                    'admin_count' => $admins->count(),
                    // Headline money figures for the current month.
                    'month_summary' => $this->monthSummary($institution),
                ];
            });

        return Inertia::render('Settings/InstitutionRegistry', [
            'institutions' => $institutions,
            'filters' => ['search' => $search],
            'totals' => [
                'institutions' => $institutions->count(),
                'active' => $institutions->where('is_active', true)->count(),
                'members' => (int) $institutions->sum('members_count'),
                'admins' => (int) $institutions->sum('admin_count'),
            ],
        ]);
    }

    /**
     * A compact month snapshot for one institution. Uses the shared finance
     * calculator with the institution resolved explicitly so the registry can
     * show several institutions side by side.
     */
    protected function monthSummary(Institution $institution): array
    {
        $month = now()->format('Y-m');

        try {
            $calculator = new FinanceCalculator($institution);
            $snapshot = $calculator->monthSnapshot($month);

            return [
                'meals' => $snapshot['meals'],
                'expenses' => $snapshot['expenses'],
                'subsidies' => $snapshot['subsidies'],
                'deposits' => $snapshot['deposits'],
                'per_meal_rate' => $snapshot['per_meal_rate'],
                'pool_balance' => $snapshot['pool_balance'],
            ];
        } catch (\Throwable $e) {
            // A brand-new institution may have no config yet; report zeroes
            // rather than taking the whole registry down.
            return [
                'meals' => 0, 'expenses' => 0.0, 'subsidies' => 0.0,
                'deposits' => 0.0, 'per_meal_rate' => 0.0, 'pool_balance' => 0.0,
            ];
        }
    }

    /** Toggle an institution's active state. */
    public function toggle(Institution $institution)
    {
        $institution->update(['is_active' => ! $institution->is_active]);

        return back()->with(
            'success',
            "\"{$institution->name}\" is now ".($institution->is_active ? 'active' : 'inactive').'.'
        );
    }
}
