<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\FinanceCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
            // Institution types power the "New Institution" form's picker.
            'types' => collect(Institution::TYPES)
                ->map(fn ($preset, $key) => [
                    'value' => $key,
                    'label' => $preset['label'],
                    'description' => $preset['description'],
                ])
                ->values(),
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

    /**
     * Create an institution AND its first Institution Admin in one step.
     *
     * A workspace with no administrator is unusable, so the two are provisioned
     * together inside a transaction - if either fails, neither is left behind.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Institution::TYPES))],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            // The institution admin account provisioned alongside the workspace.
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $institution = Institution::create([
                'name' => $data['name'],
                'subtitle' => $data['subtitle'] ?? null,
                'type' => $data['type'],
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'address' => $data['address'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'is_active' => true,
            ]);

            // The institution acts as its own hub vendor from day one.
            $institution->ensureHubVendor();

            // Provision the Institution Admin, scoped to the new workspace.
            $admin = User::create([
                'institution_id' => $institution->id,
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'status' => 'active',
                'designation' => 'Institution Admin',
            ]);

            // SAFE ROLE ASSIGNMENT: route through the whitelist guard so the new
            // institution admin can only ever receive an institution-scoped role
            // (here explicitly Institution Admin). It can never become a global
            // Software Super Admin through this path.
            $admin->assignInstitutionRole('Institution Admin');

            AuditLogger::log('created', "created institution \"{$institution->name}\" with admin {$admin->name}", $institution, [
                'type' => $institution->type,
                'admin_email' => $admin->email,
            ], ['subject_label' => $institution->name, 'institution_id' => $institution->id]);

            return $institution;
        });

        return redirect()
            ->route('settings.institutions.index')
            ->with('success', "Institution \"{$result->name}\" created with its administrator account.");
    }

    /**
     * Switch the Software Super Admin into an institution's workspace.
     *
     * THE FIX for the 404 / mismatched-institution bug:
     * the active tenant is stored in THIS USER'S SESSION, not by flipping a
     * global `is_active` column. Consequences:
     *   - switching never deactivates any other institution,
     *   - two SSAs (or an SSA and an admin) can view different workspaces at
     *     once without clobbering each other,
     *   - Institution::current() reads the same session value, so every module
     *     filters to exactly this institution's rows.
     *
     * Only an SSA may switch at all; a hard-bound user is refused.
     */
    public function switchTo(Request $request, Institution $institution)
    {
        $user = $request->user();

        // Tenancy guard: only the global Super Admin may enter another workspace.
        if (! $user->isSuperAdmin()) {
            return back()->with('error', 'Only a Software Super Admin can switch between institutions.');
        }

        // The workspace must actually exist and be reachable. (Route-model
        // binding already resolved it; this is a defensive, explicit check.)
        if (! $institution->exists) {
            return back()->with('error', 'That institution no longer exists.');
        }

        // Session-scoped switch: ephemeral, per-user, no DB mutation.
        $request->session()->put('tenant_id', $institution->id);
        $request->session()->save();

        AuditLogger::log('updated', "switched view into institution \"{$institution->name}\"", $institution, [], [
            'subject_label' => $institution->name,
            'institution_id' => $institution->id,
        ]);

        // Land on the roster for the workspace just entered. The route exists for
        // every institution, so a freshly-created one no longer 404s.
        return redirect()
            ->route('meals.students.index')
            ->with('success', "Now viewing \"{$institution->name}\".");
    }

    /**
     * Return the Software Super Admin to the global (platform) view by clearing
     * the session tenant. Institution::current() then falls back to the default.
     */
    public function exitTenant(Request $request)
    {
        $request->session()->forget('tenant_id');

        return redirect()
            ->route('settings.institutions.index')
            ->with('success', 'Returned to the platform view.');
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
