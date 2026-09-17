<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Models\Institution;
use App\Models\User;
use App\Support\AuditLogger;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List users with their currently assigned roles and status.
     *
     * Strictly institution-scoped: an Institution Admin sees only their own
     * institution's users. A Software Super Admin sees the users of whichever
     * institution they have switched into via "Access Dashboard" - they are
     * never shown a flat, unscoped list of every user on the platform.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        $search = (string) $request->query('search', '');
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->with('roles:id,name')
            // Every listing is limited to the active institution.
            ->where('institution_id', $institution?->id)
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
            // Surfaced so the UI can name the workspace users are being created
            // in, and warn when there is none.
            'scopeInstitution' => $institution ? [
                'id' => $institution->id,
                'name' => $institution->name,
            ] : null,
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Create a new user, strictly scoped to the ACTIVE institution.
     *
     * A user is never created globally. The institution is resolved from
     * Institution::current(), which is whichever workspace is active - either
     * the Institution Admin's own institution, or the one a Software Super
     * Admin switched into via the registry's "Access Dashboard" button.
     *
     * A Software Super Admin who has NOT switched into an institution has no
     * scope, and is refused here with a clear instruction rather than silently
     * creating an orphaned, institution-less user.
     */
    public function store(Request $request)
    {
        $institution = Institution::current();
        $actor = $request->user();

        // No active workspace => no scope => cannot create. (Almost always an SSA
        // who has not yet picked an institution through Access Dashboard.)
        if (! $institution) {
            return back()->with(
                'error',
                'Select an institution first. Open the Institution Registry and use "Access Dashboard" to enter a workspace, then add its users there.'
            );
        }

        // An Institution Admin may only create users in their OWN institution.
        // (SSAs pass this check because belongsToInstitution() is global for them.)
        if (! $actor->belongsToInstitution($institution->id)) {
            return back()->with('error', 'You can only create users within your own institution.');
        }

        /*
         * Two creation modes:
         *
         *   'invite'  - email a signed invitation link; no password is set here
         *               (the invitee chooses their own). This is the target state
         *               and the default once SMTP is configured.
         *   'password'- assign a temporary ("demo") password immediately, and force
         *               a change on first login. This is the SMTP-free fallback.
         *
         * The mode is chosen by the admin when SMTP is unavailable; the code path
         * is identical in every other respect, so switching to invite-only later
         * is a one-line default change.
         */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'creation_mode' => ['required', Rule::in(['invite', 'password'])],
            // Password is required only in password mode; checked below.
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // Does this email already have an account? In 'invite' mode that is fine
        // (we send a reset link instead). In 'password' mode we must not create a
        // duplicate, so it is rejected.
        $existingUser = User::where('email', $data['email'])->first();

        if ($existingUser && $data['creation_mode'] === 'password') {
            return back()->withErrors([
                'email' => 'That email already has an account. Use "Send invitation" to send them a reset link.',
            ]);
        }

        // --- Invitation path: send a signed link (create OR reset). ---
        if ($data['creation_mode'] === 'invite') {
            $invitationController = app(MemberInvitationController::class);

            // SAFE ROLE: coerce the requested role to an institution-scoped one
            // before it is stored on the invitation, so the invited account can
            // never be created as a Software Super Admin by mistake.
            $requestedRole = $data['roles'][0] ?? null;
            $safeRole = User::safeInstitutionRole($requestedRole) ?: 'Member';

            // Reuse the invitation flow. If the account already exists the
            // controller sends a reset link instead of a first-time invite.
            return $invitationController->store($request->merge([
                'email' => $data['email'],
                'name' => $data['name'],
                'role' => $safeRole,
            ]));
        }

        // --- Password path: create now, force a change on first login. ---
        if (blank($data['password'] ?? null)) {
            return back()->withErrors([
                'password' => 'Provide a temporary password, or switch to "Send invitation" mode.',
            ]);
        }

        $user = User::create([
            // THE scoping rule: every created user is bound to the active
            // institution, so we always know which workspace they belong to.
            'institution_id' => $institution->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
            'password' => Hash::make($data['password']),
            // The user must replace this temporary password before doing anything.
            'must_change_password' => true,
            // Mark setup complete: they have a password already, even if it is
            // temporary. This keeps the record in a coherent state and stops it
            // looking 'orphaned'/pending in listings.
            'setup_completed_at' => now(),
        ]);

        // SAFE ROLE ASSIGNMENT. Two guarantees enforced here:
        //   1. A global role ('Software Super Admin') can NEVER be granted
        //      through this institution-scoped flow - it is stripped outright.
        //   2. The account is never left role-less; it falls back to Member.
        $user->syncInstitutionRoles($data['roles'] ?? []);

        AuditLogger::log('created', "created user \"{$user->name}\"", $user, [
            'email' => $user->email,
            'roles' => $user->getRoleNames()->all(),
            'mode' => 'password',
        ], ['subject_label' => $user->name, 'institution_id' => $institution->id]);

        return redirect()
            ->route('settings.users.index')
            ->with('success', "User \"{$user->name}\" created in {$institution->name}. They must change the temporary password on first sign-in.");
    }

    /**
     * Update an existing user's details and roles.
     */
    public function update(Request $request, User $user)
    {
        // Strict tenancy: an admin may only touch users inside their institution.
        if (! $this->canManageUser($request, $user)) {
            return back()->with('error', 'That user belongs to another institution.');
        }

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

        /*
         * ROLE WHITELIST ENFORCEMENT.
         *
         * Only a Software Super Admin may grant the global role. For everyone
         * else we route through syncInstitutionRoles(), which strips any
         * 'Software Super Admin' and keeps only institution-scoped roles. This
         * closes the hole where an Institution Admin could PUT a crafted
         * `roles[]=Software Super Admin` payload and escalate a user.
         */
        if ($request->user()->isSuperAdmin()) {
            $user->syncRoles($selectedRoles);
        } else {
            $user->syncInstitutionRoles($selectedRoles);
        }

        return redirect()
            ->route('settings.users.index')
            ->with('success', "User \"{$user->name}\" updated.");
    }

    /**
     * Deactivate a user (keeps their history intact).
     */
    public function deactivate(Request $request, User $user)
    {
        if (! $this->canManageUser($request, $user)) {
            return back()->with('error', 'That user belongs to another institution.');
        }

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
        if (! $this->canManageUser($request, $user)) {
            return back()->with('error', 'That user belongs to another institution.');
        }

        $user->update(['status' => 'active']);

        return back()->with('success', "User \"{$user->name}\" activated.");
    }

    /**
     * Permanently delete a user.
     */
    public function destroy(Request $request, User $user)
    {
        if (! $this->canManageUser($request, $user)) {
            return back()->with('error', 'That user belongs to another institution.');
        }

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

        // Same whitelist rule as update(): a non-SSA cannot mint super admins.
        if ($request->user()->isSuperAdmin()) {
            $user->syncRoles($data['roles'] ?? []);
        } else {
            $user->syncInstitutionRoles($data['roles'] ?? []);
        }

        return redirect()->back()->with('success', 'User roles updated.');
    }

    /**
     * Can the acting user manage the target user?
     *
     * Strict tenancy rule: a Software Super Admin may manage anyone (they hold
     * global reach); everyone else may only manage users in their own
     * institution. A user with no institution is only manageable by an SSA.
     */
    protected function canManageUser(Request $request, User $user): bool
    {
        $actor = $request->user();

        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $user->institution_id !== null
            && (int) $actor->institution_id === (int) $user->institution_id;
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
        $keepsRole = collect($roles)->contains(
            fn ($name) => strtolower($name) === 'software super admin'
        );

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
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', 'Software Super Admin'))
            ->count();
    }
}
