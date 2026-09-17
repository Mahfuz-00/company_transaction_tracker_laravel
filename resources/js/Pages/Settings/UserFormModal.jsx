import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { formatRoleName } from '@/Utils/roleFormatters';
import { roleBadgeClasses } from '@/Utils/userFormatters';

/**
 * User create / edit modal, extracted from the (once 700-line) UserManager page.
 *
 * Self-contained: owns its form state, the invite-vs-temporary-password toggle,
 * and the role checklist. The page just controls `open` and `editing`.
 */

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

function FieldError({ message }) {
    if (!message) return null;
    return (
        <div role="alert" className="text-red-500 text-xs mt-1">
            {message}
        </div>
    );
}

export default function UserFormModal({ open, onClose, editing = null, availableRoles = [] }) {
    const isEditing = Boolean(editing);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    // Load / reset whenever the modal opens.
    useEffect(() => {
        if (!open) return;

        clearErrors();

        if (editing) {
            setData({
                name: editing.name || '',
                email: editing.email || '',
                phone: editing.phone || '',
                password: '',
                password_confirmation: '',
                status: editing.status || 'active',
                roles: (editing.roles || []).map((r) => r.name),
                creation_mode: 'password',
            });
        } else {
            reset();
            setData({ ...EMPTY_FORM });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    const selectedRoleNames = new Set(data.roles || []);

    const toggleRole = (roleName) => {
        const set = new Set(data.roles || []);
        if (set.has(roleName)) set.delete(roleName);
        else set.add(roleName);
        setData('roles', Array.from(set));
    };

    const passwordMismatch =
        data.password_confirmation.length > 0 && data.password !== data.password_confirmation;

    const submit = (e) => {
        e.preventDefault();
        if (passwordMismatch) return;

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (isEditing) {
            put(route('settings.users.update', editing.id), options);
        } else {
            post(route('settings.users.store'), options);
        }
    };

    if (!open) return null;

    const inputClass =
        'w-full border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-xs">
            <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border-gray-100 bg-white shadow-xl">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-6 py-4">
                    <div>
                        <h3 className="text-lg font-bold text-gray-900">
                            {isEditing ? (
                                <>Edit User: <span className="text-indigo-600">{editing?.name}</span></>
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
                        onClick={onClose}
                        className="rounded-lg p-1 text-gray-400 transition-colors hover:bg-gray-200/55 hover:text-gray-600"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form onSubmit={submit} className="flex-1 overflow-y-auto">
                    {/* Creation mode: invitation vs temporary password. */}
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

                    <div className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-5">
                        {/* Left: details */}
                        <div className="space-y-4 lg:col-span-3">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">Account Details</h4>

                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700">Full Name</label>
                                <input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className={inputClass}
                                    placeholder="e.g. Farhan Hossain"
                                />
                                <FieldError message={errors.name} />
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700">Email Address</label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className={inputClass}
                                    placeholder="name@example.com"
                                />
                                <FieldError message={errors.email} />
                            </div>

                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700">
                                    Phone<span className="font-normal text-gray-400"> (optional)</span>
                                </label>
                                <input
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    className={inputClass}
                                    placeholder="+8801XXXXXXXXX"
                                />
                                <FieldError message={errors.phone} />
                            </div>

                            {/* Password fields: editing, or creating in 'password' mode. */}
                            {(isEditing || data.creation_mode === 'password') && (
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1.5 block text-sm font-semibold text-gray-700">
                                            {isEditing ? 'New Password' : 'Temporary Password'}
                                            {isEditing && <span className="font-normal text-gray-400"> (leave blank to keep)</span>}
                                        </label>
                                        <input
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            className={inputClass}
                                            placeholder="At least 8 characters"
                                            autoComplete="new-password"
                                        />
                                        <FieldError message={errors.password} />
                                    </div>

                                    <div>
                                        <label className="mb-1.5 block text-sm font-semibold text-gray-700">Confirm Password</label>
                                        <input
                                            type="password"
                                            value={data.password_confirmation}
                                            onChange={(e) => setData('password_confirmation', e.target.value)}
                                            className={inputClass}
                                            placeholder="Repeat password"
                                            autoComplete="new-password"
                                        />
                                        {passwordMismatch && (
                                            <div role="alert" className="mt-1 text-xs text-red-500">Passwords do not match.</div>
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
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700">Account Status</label>
                                <select
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    className="w-full rounded-lg border-gray-300 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <p className="mt-1 text-xs text-gray-400">Inactive users keep their history but cannot sign in.</p>
                                <FieldError message={errors.status} />
                            </div>
                        </div>

                        {/* Right: roles */}
                        <div className="space-y-3 lg:col-span-2">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">Assigned Roles</h4>
                                <span className="text-xs font-semibold text-gray-400">{(data.roles || []).length} selected</span>
                            </div>

                            <div className="max-h-80 space-y-2 overflow-y-auto pr-1">
                                {(availableRoles || []).length > 0 ? (
                                    availableRoles.map((role) => {
                                        const checked = selectedRoleNames.has(role.name);
                                        return (
                                            <label
                                                key={role.id}
                                                className={`flex cursor-pointer select-none items-center gap-3 rounded-xl border p-3 transition-all ${checked ? 'border-indigo-200 bg-indigo-50/60' : 'border-gray-200 bg-gray-50/50 hover:bg-gray-100/60'}`}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleRole(role.name)}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-semibold text-gray-800">{formatRoleName(role.name)}</span>
                                                </span>
                                                <span className={`h-2.5 w-2.5 rounded-full border ${roleBadgeClasses(role.name)}`} />
                                            </label>
                                        );
                                    })
                                ) : (
                                    <p className="py-4 text-center text-xs italic text-gray-400">
                                        No roles available. Create one in the Role Manager first.
                                    </p>
                                )}
                            </div>

                            <FieldError message={errors.roles} />
                            <FieldError message={errors['roles.0']} />

                            <p className="pt-1 text-xs text-gray-400">
                                Users inherit every permission granted to their roles.
                            </p>
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="sticky bottom-0 flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing || passwordMismatch}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : isEditing ? 'Save Changes' : 'Create User'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
