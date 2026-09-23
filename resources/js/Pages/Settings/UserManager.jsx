import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, router } from '@inertiajs/react';
import useCan from '@/Utils/can';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import UserFormModal from './UserFormModal';
import { formatRoleName } from '@/Utils/roleFormatters';
import {
    roleBadgeClasses,
    initials,
    statusBadgeClasses,
    formatStatus,
} from '@/Utils/userFormatters';

/* ------------------------------------------------------------------ *
 * Small presentational helpers
 * ------------------------------------------------------------------ */

function RoleBadge({ name }) {
    return (
        <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${roleBadgeClasses(
                name
            )}`}
        >
            {formatRoleName(name)}
        </span>
    );
}

function StatusBadge({ status }) {
    const isActive = (status || 'active') === 'active';
    return (
        <span
            className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border ${statusBadgeClasses(
                status
            )}`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${isActive ? 'bg-emerald-500' : 'bg-slate-400'
                    }`}
            />
            {formatStatus(status)}
        </span>
    );
}

function Avatar({ name }) {
    return (
        <div className="h-9 w-9 flex-shrink-0 rounded-lg bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
            {initials(name)}
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

/**
 * User Manager — the Settings → Users screen.
 *
 * Inertia page component for listing, filtering, creating, editing, activating /
 * deactivating and deleting user accounts. It runs in one of two scopes: a single
 * workspace, or a GLOBAL directory for the Software Super Admin.
 *
 * PROPS
 *   - `users`            : paginated user list (Laravel paginator shape: `.data`
 *                          rows plus `.links` page controls).
 *   - `roles` (as
 *     `availableRoles`)  : the roles that can be assigned in the modal.
 *   - `filters`          : current search / role / status / institution filters,
 *                          used to seed the controls and re-issue queries.
 *   - `scopeInstitution` : the workspace being managed, or null.
 *   - `globalScope`      : TRUE for the SSA — turns on the institution column, the
 *                          institution filter and a target-workspace picker.
 *   - `institutions`     : workspaces selectable in the SSA institution filter.
 *
 * Inertia / React concepts on show:
 *   - `router.get/patch/delete` are PROGRAMMATIC Inertia visits (used from event
 *     handlers) as opposed to declarative <Link>s.
 *   - `preserveState` / `preserveScroll` / `replace` tune how a visit behaves, so
 *     filtering neither resets the scroll position nor wipes component state.
 *   - `useState` mirrors the search box; `useCan()` gates create/edit/delete;
 *     `useFeedback().confirm` collects confirmation before destructive actions.
 *   - the create/edit form is delegated to the self-contained UserFormModal.
 */
export default function UserManager({
    users,
    roles: availableRoles,
    filters,
    scopeInstitution = null,
    // TRUE for the Software Super Admin: the module becomes a GLOBAL directory -
    // an institution column, an institution filter, and a target-institution
    // selector when creating a user.
    globalScope = false,
    institutions = [],
}) {
    const { can } = useCan();
    const { confirm } = useFeedback();

    const [modalOpen, setModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const rows = users?.data || [];

    const canCreate = can('users.create');
    const canEdit = can('users.edit');
    const canDelete = can('users.delete');

    /* ---------------- modal (state only; the modal owns its form) ---------------- */

    const openCreate = () => {
        setEditingUser(null);
        setModalOpen(true);
    };

    const openEdit = (user) => {
        setEditingUser(user);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingUser(null);
    };

    /* ---------------- row actions ---------------- */

    const toggleStatus = async (user) => {
        const isActive = (user.status || 'active') === 'active';
        const label = isActive ? 'deactivate' : 'activate';
        const ok = await confirm({
            title: isActive ? `Deactivate ${user.name}?` : `Activate ${user.name}?`,
            message: isActive
                ? 'They will no longer be able to sign in. Their history is kept intact.'
                : 'They will regain access to the workspace.',
            tone: isActive ? 'warning' : 'info',
            confirmLabel: isActive ? 'Deactivate' : 'Activate',
        });
        if (!ok) return;

        router.patch(route(`settings.users.${label}`, user.id), {}, { preserveScroll: true });
    };

    const deleteUser = async (user) => {
        const ok = await confirm({
            title: `Permanently delete ${user.name}?`,
            message: 'This cannot be undone. Consider deactivating the account instead to keep their history.',
            tone: 'danger',
            confirmLabel: 'Delete user',
        });
        if (!ok) return;

        router.delete(route('settings.users.destroy', user.id), {
            preserveScroll: true,
        });
    };

    /* ---------------- filtering ---------------- */

    const applyFilters = (next) => {
        router.get(
            route('settings.users.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const submitSearch = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const clearFilters = () => {
        setSearch('');
        router.get(route('settings.users.index'), {}, { replace: true });
    };

    const hasFilters =
        Boolean(filters?.search) || Boolean(filters?.role) || Boolean(filters?.status);

    return (
        <SettingsLayout title="Settings">
            <Head title="User Manager" />

            {/* Scope banner: global directory for the SSA, workspace scope for an admin. */}
            {globalScope ? (
                <div className="mb-4 flex items-center gap-2 rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <svg className="h-4 w-4 flex-shrink-0 text-[var(--accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>
                        <strong className="font-semibold text-slate-800">Global directory</strong> — showing
                        users across <strong className="font-semibold text-slate-800">every institution</strong>.
                        You can create a user or admin and assign them to any workspace.
                    </span>
                </div>
            ) : scopeInstitution ? (
                <div className="mb-4 flex items-center gap-2 rounded-lg border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <svg className="h-4 w-4 flex-shrink-0 text-[var(--accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    Managing users in <strong className="font-semibold text-slate-800">{scopeInstitution.name}</strong>.
                    Every account you create here belongs to this institution.
                </div>
            ) : (
                <div className="mb-4 flex items-start gap-2 rounded-lg border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                    <svg className="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <span>
                        No institution is selected. Users are always created inside a workspace - open the{' '}
                        <Link href={route('settings.institutions.index')} className="font-semibold underline">
                            Institution Registry
                        </Link>{' '}
                        and use <strong className="font-semibold">Access Dashboard</strong> to enter one.
                    </span>
                </div>
            )}

            <div className="bg-white shadow-sm rounded-xl border-gray-100 overflow-hidden">
                {/* Header */}
                <div className="p-6 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-gray-50/50">
                    <div>
                        <h2 className="text-xl font-bold text-gray-900">User Manager</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Manage system accounts and assign the roles that control their access.
                        </p>
                    </div>
                    {canCreate && (
                        <button
                            onClick={openCreate}
                            // The SSA's global module always has a target institution
                            // available (chosen in the form); an admin needs an
                            // active workspace.
                            disabled={!globalScope && !scopeInstitution}
                            title={globalScope || scopeInstitution ? undefined : 'Select an institution first'}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-medium text-sm rounded-lg shadow-sm transition-all disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Create User
                        </button>
                    )}
                </div>

                {/* Filters */}
                <div className="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center gap-3">
                    <form onSubmit={submitSearch} className="relative flex-1 max-w-sm">
                        <svg
                            className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name, email or phone..."
                            className="w-full pl-9 pr-3 py-2 border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 rounded-lg text-sm transition-all outline-none"
                        />
                    </form>

                    <select
                        value={filters?.role || ''}
                        onChange={(e) => applyFilters({ role: e.target.value })}
                        className="border-gray-300 rounded-lg text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">All roles</option>
                        {(availableRoles || []).map((r) => (
                            <option key={r.id} value={r.name}>
                                {formatRoleName(r.name)}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters?.status || ''}
                        onChange={(e) => applyFilters({ status: e.target.value })}
                        className="border-gray-300 rounded-lg text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    {/* SSA only: narrow the global directory to one workspace. */}
                    {globalScope && (
                        <select
                            value={filters?.institution || ''}
                            onChange={(e) => applyFilters({ institution: e.target.value })}
                            className="border-gray-300 rounded-lg text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All institutions</option>
                            {(institutions || []).map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.name}
                                </option>
                            ))}
                            <option value="none">Platform users (no institution)</option>
                        </select>
                    )}

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors sm:ml-auto"
                        >
                            Clear filters
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-gray-50 text-gray-400 text-xs uppercase tracking-wider font-semibold border-b border-gray-100">
                                <th className="py-3 px-6">User</th>
                                <th className="py-3 px-6">Contact</th>
                                {/* Global directory only: which workspace each user belongs to. */}
                                {globalScope && <th className="py-3 px-6">Institution</th>}
                                <th className="py-3 px-6">Roles</th>
                                <th className="py-3 px-6">Status</th>
                                <th className="py-3 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((u) => (
                                    <tr key={u.id} className="hover:bg-gray-50/60 transition-colors">
                                        <td className="py-4 px-6">
                                            <div className="flex items-center gap-3">
                                                <Avatar name={u.name} />
                                                <span className="font-semibold text-gray-900">
                                                    {u.name}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="py-4 px-6 text-gray-600">
                                            <div className="font-medium">{u.email}</div>
                                            {u.phone && (
                                                <div className="text-xs text-gray-400">{u.phone}</div>
                                            )}
                                        </td>
                                        {globalScope && (
                                            <td className="py-4 px-6">
                                                {u.institution?.name ? (
                                                    <span className="inline-flex items-center rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                                        {u.institution.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs italic text-gray-400">Platform</span>
                                                )}
                                            </td>
                                        )}
                                        <td className="py-4 px-6">
                                            <div className="flex flex-wrap gap-1.5">
                                                {(u.roles || []).length > 0 ? (
                                                    (u.roles || []).map((r) => (
                                                        <RoleBadge key={r.name} name={r.name} />
                                                    ))
                                                ) : (
                                                    <span className="text-gray-400 italic text-xs">
                                                        No role assigned
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-4 px-6">
                                            <StatusBadge status={u.status} />
                                        </td>
                                        <td className="py-4 px-6 text-right space-x-3 whitespace-nowrap">
                                            {canEdit && (
                                                <>
                                                    <button
                                                        onClick={() => openEdit(u)}
                                                        className="text-indigo-600 hover:text-indigo-900 font-medium transition-colors"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => toggleStatus(u)}
                                                        className="text-amber-600 hover:text-amber-800 font-medium transition-colors"
                                                    >
                                                        {(u.status || 'active') === 'active'
                                                            ? 'Deactivate'
                                                            : 'Activate'}
                                                    </button>
                                                </>
                                            )}
                                            {canDelete && (
                                                <button
                                                    onClick={() => deleteUser(u)}
                                                    className="text-red-500 hover:text-red-700 font-medium transition-colors"
                                                >
                                                    Delete
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={globalScope ? 6 : 5} className="py-12 text-center text-gray-400">
                                        No users found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {users?.links?.length > 3 && (
                    <div className="px-6 py-4 border-t border-gray-100 flex items-center justify-between gap-4">
                        <p className="text-xs text-gray-500">
                            Showing <strong>{users.from}</strong>–<strong>{users.to}</strong> of{' '}
                            <strong>{users.total}</strong> users
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {users.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors ${link.active
                                            ? 'bg-indigo-600 border-indigo-600 text-white'
                                            : link.url
                                                ? 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
                                                : 'bg-white border-gray-100 text-gray-300 pointer-events-none'
                                        }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Create / Edit modal (self-contained form component). */}
            <UserFormModal
                open={modalOpen}
                onClose={closeModal}
                editing={editingUser}
                availableRoles={availableRoles}
                // SSA global mode: the form shows a target-institution selector.
                globalScope={globalScope}
                institutions={institutions}
                defaultInstitutionId={scopeInstitution?.id || null}
            />
        </SettingsLayout>
    );
}
