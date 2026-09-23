<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

/**
 * Role & permission management (built on Spatie's laravel-permission package).
 *
 * WHAT THIS IS
 * ------------
 * The screen an administrator uses to define named roles ("Institution Admin",
 * "Meal Manager", "Member", ...) and to attach the fine-grained permissions each
 * role grants. A user is then given a role, and every `@can` / `Gate::allows()`
 * check elsewhere in the app resolves through it.
 *
 * WHY IT LOOKS DIFFERENT FROM THE TENANT-OWNED CONTROLLERS
 * -------------------------------------------------------
 * Roles and permissions are PLATFORM reference data, NOT institution rows. They
 * therefore do not use the `BelongsToInstitution` trait and are not touched by
 * its global tenant scope: there is ONE shared catalogue of roles and
 * permissions for the whole platform. Institutional isolation applies to the
 * business models (Student, Transaction, Vendor), never to these rows.
 *
 * HOW SPATIE WORKS (for readers coming from mobile)
 * -------------------------------------------------
 * `Role` and `Permission` are Eloquent models shipped by the package. The link
 * between a role and its permissions is a pivot table that the package manages
 * for you via `syncPermissions()`. Unlike roles baked into a native app at
 * compile time, authorisation here is just database rows, editable at runtime -
 * which is why this controller exists at all.
 */
class RoleController extends Controller
{
    /**
     * List every role with its user count and attached permissions.
     *
     * `with('permissions')` eager-loads the pivot so the view does not fire one
     * query per role. `withCount('users')` adds a `users_count` attribute using a
     * single correlated subquery rather than hydrating every user.
     */
    public function index(Request $request)
    {
        $roles = Role::withCount('users')->with('permissions')->get();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
        ]);
    }

    /**
     * Render the empty create form. Permissions are grouped by their `module`
     * column so the UI can draw one checkbox section per module (Meals, Members,
     * Finance, ...) instead of one flat, unreadable list.
     */
    public function create()
    {
        $permissions = Permission::all()->groupBy('module');
        return Inertia::render('Roles/Form', [
            'permissions' => $permissions,
            'role' => null,
        ]);
    }

    /**
     * Persist a new role and attach the selected permissions.
     *
     * Note the deliberate split: only `name`/`description` go into the
     * `Role::create()` payload, because permissions are NOT mass-assignable
     * columns on the role - they live in a pivot table. They are applied through
     * the package's `syncPermissions()` AFTER the row exists.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'permissions' => 'array',
        ]);

        $role = Role::create(['name' => $data['name']]);
        // syncPermissions() means "make the attached set exactly this". Skipped
        // only when nothing was submitted; a brand-new role already has none.
        if (!empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return redirect()->route('roles.index')->with('success', 'Role created.');
    }

    /**
     * Edit form, pre-filled for one role.
     *
     * `$role` arrives via ROUTE-MODEL BINDING: Laravel reads the `{role}` route
     * parameter and runs `Role::findOrFail($id)` for us, returning a 404
     * automatically when the id does not exist - so this method never has to
     * guard against a missing record. `load('permissions')` eager-loads the pivot
     * so the checkboxes can be pre-checked.
     */
    public function edit(Role $role)
    {
        $permissions = Permission::all()->groupBy('module');
        $role->load('permissions');

        return Inertia::render('Roles/Form', [
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Update the role and REPLACE its permission set.
     *
     * Unlike store(), `syncPermissions()` is always called here - defaulting to an
     * empty array - so un-ticking every checkbox genuinely REVOKES all
     * permissions. Omitting the call would silently leave the old permissions
     * attached, which is the classic "I removed access but nothing changed" bug.
     */
    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'permissions' => 'array',
        ]);

        $role->name = $data['name'];
        $role->save();
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    /**
     * Delete a role, with one hard guard.
     *
     * The Super Admin role is protected by NAME (matched case-insensitively, so
     * "Super Admin" and "superadmin" both hit) because it underpins the Software
     * Super Admin's own access. Deleting it would lock the platform owner out of
     * the system, so the check runs before the row is touched.
     */
    public function destroy(Role $role)
    {
        // prevent deleting Super Admin role
        if (strtolower($role->name) === 'super admin' || strtolower($role->name) === 'superadmin') {
            return redirect()->back()->with('error', 'Cannot delete Super Admin role.');
        }

        $role->delete();
        return redirect()->route('roles.index')->with('success', 'Role deleted.');
    }
}
