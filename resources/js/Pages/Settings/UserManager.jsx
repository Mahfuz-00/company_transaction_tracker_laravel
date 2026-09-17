import React, { useState, useMemo } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import useCan from '@/Utils/can';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
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

function FieldError({ message }) {
    if (!message) return null;
    return (
        <div role="alert" className="text-red-500 text-xs mt-1">
            {message}
        </div>
    );
}

const EMPTY_FORM = {
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    status: 'active',
    roles: [],
    // 'invite'   -> email an invitation link (needs SMTP)
    // 'password' -> assign a temporary password now (SMTP-free fallback)
    creation_mode: 'password',
};

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function UserManager({ users, roles: availableRoles, filters, scopeInstitution = null }) {
    const { can } = useCan();
    const { confirm } = useFeedback();

    const [modalOpen, setModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = users?.data || [];
    const isEditing = Boolean(editingUser);

    const canCreate = can('users.create');
    const canEdit = can('users.edit');
    const canDelete = can('users.delete');

    /* ---------------- modal ---------------- */

    const openCreate = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setEditingUser(null);
        setModalOpen(true);
    };

    const openEdit = (user) => {
        clearErrors();
        setEditingUser(user);
        setData({
            name: user.name || '',
            email: user.email || '',
            phone: user.phone || '',
            password: '',
            password_confirmation: '',
            status: user.status || 'active',
            roles: (user.roles || []).map((r) => r.name),
        });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditingUser(null);
        reset();
    };

    const toggleRole = (roleName) => {
        const set = new Set(data.roles || []);
        if (set.has(roleName)) set.delete(roleName);
        else set.add(roleName);
        setData('roles', Array.from(set));
    };

    const submit = (e) => {
        e.preventDefault();

        if (isEditing) {
            put(route('settings.users.update', editingUser.id), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('settings.users.store'), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        }
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

    /* ---------------- client-side guardrails ---------------- */

    const passwordMismatch =
        data.password.length > 0 && data.password !== data.password_confirmation;

    const selectedRoleNames = useMemo(
        () => new Set(data.roles || []),
        [data.roles]
    );

    return (
        <SettingsLayout title="Settings">
            <Head title="User Manager" />

            {/* Scope banner: which institution users are created into. */}
            {scopeInstitution ? (
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
                <div className="p-6 border-b border-gray-100 flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-gray-50/50">
                    <div>
                        <h2 className="text-xl font-bold text-gray-900">User Manager</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Manage system accounts and assign the roles that control their access.
                        </p>
                    </div>
                    {canCreate && (
                        <button
                            onClick={openCreate}
                            disabled={!scopeInstitution}
                            title={scopeInstitution ? undefined : 'Select an institution first'}
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
                                    <td colSpan="5" className="py-12 text-center text-gray-400">
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

            {/* Create / Edit Modal */}
            {modalOpen && (
                <div className="fixed inset-0 bg-gray-900/50 backdrop-blur-xs flex items-center justify-center p-4 z-50">
                    <div className="bg-white rounded-2xl shadow-xl w-full max-w-3xl overflow-hidden border-gray-100 flex flex-col max-h-[90vh]">
                        {/* Modal header */}
                        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                            <div>
                                <h3 className="text-lg font-bold text-gray-900">
                                    {isEditing ? (
                                        <>
                                            Edit User:{' '}
                                            <span className="text-indigo-600">{editingUser?.name}</span>
                                        </>
                                    ) : (
                                        'Create New User'
                                    )}
                                </h3>
                                <p className="text-xs text-gray-500">
                                    {isEditing
                                        ? 'Update account details and role assignments.'
                                        : 'Add a system account and choose which roles it holds.'}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={closeModal}
                                className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-200/55 transition-colors"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Modal body */}
                        <form onSubmit={submit} className="flex-1 overflow-y-auto">
                            {/* Creation mode: invitation vs temporary password.
                                Only relevant when creating (not editing). */}
                            {!isEditing && (
                                <div className="border-b border-gray-100 bg-gray-50/60 px-6 py-4">
                                    <p className="mb-2 text-xs font-bold uppercase tracking-wider text-indigo-600">
                                        How should this user get access?
                                    </p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {[
                                            { value: 'password', title: 'Set a temporary password', desc: 'No email needed. They must change it on first sign-in.' },
                                            { value: 'invite', title: 'Email an invitation', desc: 'Sends a link so they choose their own password (needs email configured).' },
                                        ].map((mode) => (
                                            <button
                                                key={mode.value}
                                                type="button"
                                                onClick={() => setData('creation_mode', mode.value)}
                                                className={`rounded-xl border p-3 text-left transition-all ${data.creation_mode === mode.value ? 'border-indigo-300 bg-indigo-50/60 ring-1 ring-indigo-200' : 'border-gray-200 bg-white hover:border-gray-300'}`}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <span className={`h-3.5 w-3.5 rounded-full border-2 ${data.creation_mode === mode.value ? 'border-indigo-600 bg-indigo-600' : 'border-gray-300'}`} />
                                                    <span className="text-sm font-semibold text-gray-900">{mode.title}</span>
                                                </div>
                                                <p className="mt-1 text-xs leading-relaxed text-gray-500">{mode.desc}</p>
                                            </button>
                                        ))}
                                    </div>
                                    <FieldError message={errors.creation_mode} />
                                </div>
                            )}

                            <div className="p-6 grid grid-cols-1 lg:grid-cols-5 gap-6">
                                {/* Left: details */}
                                <div className="lg:col-span-3 space-y-4">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">
                                        Account Details
                                    </h4>

                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Full Name
                                        </label>
                                        <input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                            placeholder="e.g. Farhan Hossain"
                                        />
                                        <FieldError message={errors.name} />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Email Address
                                        </label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            className="w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                            placeholder="name@example.com"
                                        />
                                        <FieldError message={errors.email} />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Phone
                                            <span className="text-gray-400 font-normal"> (optional)</span>
                                        </label>
                                        <input
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                            className="w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                            placeholder="+8801XXXXXXXXX"
                                        />
                                        <FieldError message={errors.phone} />
                                    </div>

                                    {/* Password fields show when editing, or when
                                        creating in 'password' mode. In invite mode
                                        the user sets their own password via email,
                                        so these are hidden. */}
                                    {(isEditing || data.creation_mode === 'password') && (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                                {isEditing ? 'New Password' : 'Temporary Password'}
                                                {isEditing && (
                                                    <span className="text-gray-400 font-normal">
                                                        {' '}
                                                        (leave blank to keep)
                                                    </span>
                                                )}
                                            </label>
                                            <input
                                                type="password"
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                                className="w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                                placeholder="At least 8 characters"
                                                autoComplete="new-password"
                                            />
                                            <FieldError message={errors.password} />
                                        </div>

                                        <div>
                                            <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                                Confirm Password
                                            </label>
                                            <input
                                                type="password"
                                                value={data.password_confirmation}
                                                onChange={(e) =>
                                                    setData('password_confirmation', e.target.value)
                                                }
                                                className="w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                                placeholder="Repeat password"
                                                autoComplete="new-password"
                                            />
                                            {passwordMismatch && (
                                                <div role="alert" className="text-red-500 text-xs mt-1">
                                                    Passwords do not match.
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    )}

                                    {!isEditing && data.creation_mode === 'password' && (
                                        <div className="rounded-lg border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                            This user will be asked to change this temporary password the first time they sign in.
                                        </div>
                                    )}

                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1.5">
                                            Account Status
                                        </label>
                                        <select
                                            value={data.status}
                                            onChange={(e) => setData('status', e.target.value)}
                                            className="w-full border-gray-300 rounded-lg text-sm text-gray-900 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                        <p className="text-xs text-gray-400 mt-1">
                                            Inactive users keep their history but cannot sign in.
                                        </p>
                                        <FieldError message={errors.status} />
                                    </div>
                                </div>

                                {/* Right: roles */}
                                <div className="lg:col-span-2 space-y-3">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">
                                            Assigned Roles
                                        </h4>
                                        <span className="text-xs font-semibold text-gray-400">
                                            {(data.roles || []).length} selected
                                        </span>
                                    </div>

                                    <div className="space-y-2 max-h-80 overflow-y-auto pr-1">
                                        {(availableRoles || []).length > 0 ? (
                                            (availableRoles || []).map((role) => {
                                                const checked = selectedRoleNames.has(role.name);
                                                return (
                                                    <label
                                                        key={role.id}
                                                        className={`flex items-center gap-3 p-3 rounded-xl border cursor-pointer select-none transition-all ${checked
                                                                ? 'border-indigo-200 bg-indigo-50/60'
                                                                : 'border-gray-200 bg-gray-50/50 hover:bg-gray-100/60'
                                                            }`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => toggleRole(role.name)}
                                                            className="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                                                        />
                                                        <span className="flex-1 min-w-0">
                                                            <span className="block text-sm font-semibold text-gray-800">
                                                                {formatRoleName(role.name)}
                                                            </span>
                                                        </span>
                                                        <span
                                                            className={`h-2.5 w-2.5 rounded-full border ${roleBadgeClasses(
                                                                role.name
                                                            )}`}
                                                        />
                                                    </label>
                                                );
                                            })
                                        ) : (
                                            <p className="text-xs text-gray-400 italic py-4 text-center">
                                                No roles available. Create one in the Role Manager first.
                                            </p>
                                        )}
                                    </div>

                                    <FieldError message={errors.roles} />
                                    <FieldError message={errors['roles.0']} />

                                    <p className="text-xs text-gray-400 pt-1">
                                        Users inherit every permission granted to their roles.
                                    </p>
                                </div>
                            </div>

                            {/* Modal footer */}
                            <div className="sticky bottom-0 px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={closeModal}
                                    className="px-4 py-2 border-gray-300 hover:bg-gray-100 text-gray-700 font-medium text-sm rounded-lg transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing || passwordMismatch}
                                    className="inline-flex items-center gap-2 px-5 py-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50 text-white font-medium text-sm rounded-lg shadow-sm transition-all"
                                >
                                    {processing && (
                                        <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                            <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                        </svg>
                                    )}
                                    {processing
                                        ? 'Saving...'
                                        : isEditing
                                            ? 'Save Changes'
                                            : 'Create User'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}
