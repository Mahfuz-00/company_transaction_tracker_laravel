<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List users with their currently assigned roles and status.
     */
    public function index(Request $request)
    {
        $search = (string) $request->query('search', '');
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->with('roles:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($role !== '', function ($query) use ($role) {
                $query->role($role);
            })
            ->when($status !== '', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Settings/UserManager', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Create a new user and assign an initial role.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
            'password' => Hash::make($data['password']),
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()
            ->route('settings.users.index')
            ->with('success', "User \"{$user->name}\" created.");
    }

    /**
     * Update an existing user's details and roles.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $selectedRoles = $data['roles'] ?? [];

        // Guard: never lock yourself out of the system.
        if ($this->wouldRemoveLastSuperAdmin($user, $data['status'], $selectedRoles)) {
            return back()->with('error', 'You cannot deactivate or demote the only active Super Admin.');
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        $user->syncRoles($selectedRoles);

        return redirect()
            ->route('settings.users.index')
            ->with('success', "User \"{$user->name}\" updated.");
    }

    /**
     * Deactivate a user (keeps their history intact).
     */
    public function deactivate(Request $request, User $user)
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($this->wouldRemoveLastSuperAdmin($user, 'inactive', $user->getRoleNames()->all())) {
            return back()->with('error', 'You cannot deactivate the only active Super Admin.');
        }

        $user->update(['status' => 'inactive']);

        return back()->with('success', "User \"{$user->name}\" deactivated.");
    }

    /**
     * Reactivate a user.
     */
    public function activate(Request $request, User $user)
    {
        $user->update(['status' => 'active']);

        return back()->with('success', "User \"{$user->name}\" activated.");
    }

    /**
     * Permanently delete a user.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($this->wouldRemoveLastSuperAdmin($user, 'inactive', [])) {
            return back()->with('error', 'You cannot delete the only active Super Admin.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()
            ->route('settings.users.index')
            ->with('success', "User \"{$name}\" deleted.");
    }

    /**
     * Backwards-compatible helper: sync roles from the standalone Users/Edit page.
     */
    public function updateRoles(Request $request, User $user)
    {
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()->back()->with('success', 'User roles updated.');
    }

    /**
     * Would applying the given status/roles leave the system without an active Super Admin?
     */
    protected function wouldRemoveLastSuperAdmin(User $user, string $status, array $roles): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        $stillActive = $status === 'active';
        $keepsRole = collect($roles)->contains(fn ($name) => in_array(strtolower($name), ['super admin', 'superadmin'], true));

        if ($stillActive && $keepsRole) {
            return false;
        }

        return $this->activeSuperAdminCount() <= 1;
    }

    /**
     * How many active users currently hold the Super Admin role.
     */
    protected function activeSuperAdminCount(): int
    {
        return User::role('Super Admin')->where('status', 'active')->count();
    }
}
