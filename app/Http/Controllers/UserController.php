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
use App\Support\PasswordGuard;
use App\Support\TenantManager;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List users with their currently assigned roles and status.
     *
     * TWO MODES, decided by the actor's role:
     *
     *   SOFTWARE SUPER ADMIN - the GLOBAL DIRECTORY.
     *     The SSA is a platform operator, so the User Manager is a GLOBAL module
     *     listing users across EVERY institution (plus platform-level accounts).
     *     Each row carries its institution so the SSA can see the whole estate at
     *     a glance, filter by institution, and create users directly against any
     *     chosen institution without having to "switch in" first.
     *     `globalScope` is passed to the UI so it can show the institution column,
     *     the institution filter and the target-institution selector on create.
     *
     *   INSTITUTION ADMIN - strictly institution-scoped.
     *     Sees only their own institution's users, and can never see (or act on) a
     *     global Super Admin account. `scopeInstitution` is passed so the UI can
     *     name the workspace.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $institution = Institution::current();

        // Only Admins and the SSA may open the User Manager at all. The route is
        // also permission-gated (`users.view`), but this is defence in depth: a
        // manager (or a crafted request) can never reach the roster.
        abort_unless(
            $user->isSuperAdmin() || $user->isInstitutionAdmin(),
            403,
            'The User Manager is available to administrators only.'
        );

        // The SSA sees the platform-wide directory; everyone else is tenant-scoped.
        $globalScope = $user->isSuperAdmin();

        $search = (string) $request->query('search', '');
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');
        // Only meaningful in the SSA's global mode.
        $institutionFilter = (string) $request->query('institution', '');

        // The SSAs directory is a cross-tenant read, so it must run outside the
        // tenant scope (otherwise Institution::current() would hide every other
        // workspace). Institution Admins stay inside their own scope.
        $query = app(TenantManager::class)->runGlobally(function () use (
            $globalScope,
            $institution,
            $search,
            $role,
            $status,
            $institutionFilter
        ) {
            return User::query()
                ->with(['roles:id,name', 'institution:id,name'])
                // INSTITUTION ADMIN: limited to the active institution.
                // SSA (global): no institution restriction - but they may still
                // narrow to one via the filter below.
                ->when(! $globalScope, fn ($q) => $q->where('institution_id', $institution?->id))
                // GLOBAL-ROLE GUARD: a Software Super Admin account is a platform
                // record, not a tenant member. It must NEVER appear in an
                // Institution Admin's roster (and cannot be searched, edited or
                // deactivated from here). Only the SSA sees other SSAs.
                ->when(! $globalScope, function ($q) {
                    $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Software Super Admin'))
                        ->whereNotNull('institution_id');
                })
                // SSA-only institution filter.
                ->when($globalScope && $institutionFilter !== '', function ($q) use ($institutionFilter) {
                    if ($institutionFilter === 'none') {
                        $q->whereNull('institution_id');
                    } else {
                        $q->where('institution_id', (int) $institutionFilter);
                    }
                })
                ->when($search !== '', function ($query) use ($search) {
                    $term = '%' . $search . '%';
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
        });

        return Inertia::render('Settings/UserManager', [
            'users' => $query,
            // Only roles an admin may confer are offered - the global role is
            // never listed to an Institution Admin.
            'roles' => Role::query()
                ->when(! $globalScope, fn ($q) => $q->where('name', '!=', 'Software Super Admin'))
                ->orderBy('name')
                ->get(['id', 'name']),
            // TRUE only for the SSA: switches the module into the global-directory
            // mode (institution column + institution filter + target selector).
            'globalScope' => $globalScope,
            // The institutions the SSA may assign a new user to.
            'institutions' => $globalScope
                ? Institution::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Institution $i) => ['id' => $i->id, 'name' => $i->name])
                    ->all()
                : [],
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
                'institution' => $institutionFilter,
            ],
        ]);
    }

    /**
     * Create a new user, scoped to a target institution.
     *
     * TWO CALLERS:
     *
     *   INSTITUTION ADMIN - the institution is the ACTIVE one (their own). A
     *     tenant admin can only ever create users in their own workspace.
     *
     *   SOFTWARE SUPER ADMIN - the GLOBAL module. The SSA explicitly chooses the
     *     TARGET institution for the new account (posted as `institution_id`),
     *     so they can create a user or an institution admin for ANY workspace
     *     directly from the global directory, without switching in first. When
     *     the SSA posts no institution, the active one (if any) is used, so the
     *     old "switch then create" flow still works.
     */
    public function store(Request $request)
    {
        $actor = $request->user();

        // Resolve the TARGET institution.
        //   - SSA: their explicit choice wins; otherwise fall back to the active
        //     tenant so the legacy "Access Dashboard then add user" flow works.
        //   - Institution Admin: always their own (active) institution.
        $institution = $actor->isSuperAdmin()
            ? ($request->filled('institution_id')
                ? Institution::findOrFail($request->input('institution_id'))
                : Institution::current())
            : Institution::current();

        // No active/target workspace => no scope => cannot create.
        if (! $institution) {
            return back()->with(
                'error',
                'Select an institution first - pick a target workspace in the create form, or open the Institution Registry and use "Access Dashboard".'
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
            // SSA-only: the TARGET institution for the new account. Ignored for
            // an Institution Admin (their own workspace is always used).
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
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

        /*
         * CREDENTIAL GUARDRAIL.
         *
         * A generic user-update MUST NOT be able to change a Software Super
         * Admin's password - that was the credential-integrity hole. An SSA may
         * only change their own password via self-service or the dedicated reset
         * action; here we refuse anything else with a clear message.
         */
        $passwordChangeRequested = ! empty($data['password']);

        if ($passwordChangeRequested && ! PasswordGuard::mayChangePassword($user, $request->user())) {
            return back()->with('error', 'A Super Admin password can only be changed by that account itself, through a dedicated reset.');
        }

        $user->save();

        // Apply the password through the authorised guard (stamps the change
        // time + audits it) rather than writing the column directly.
        if ($passwordChangeRequested) {
            PasswordGuard::changePassword(
                $user,
                $data['password'],
                'admin_user_update',
                forceSsa: $request->user()->isSuperAdmin() && $request->user()->id === $user->id,
            );
        }

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

        // HARD BOUNDARY: a non-SSA may never act on a global Super Admin account
        // (nor on any account that belongs to no institution), no matter which
        // institution id the target happens to carry.
        if ($user->isSuperAdmin()) {
            return false;
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
