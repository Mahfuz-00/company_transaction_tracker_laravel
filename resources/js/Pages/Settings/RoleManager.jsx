import React, { useState, useEffect } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, router, useForm } from '@inertiajs/react';
import useCan from '@/Utils/can';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { formatPermissionLabel, formatRoleName } from '@/Utils/roleFormatters';

export default function RoleManager({ auth, roles: initialRoles }) {
    const { can } = useCan();
    const { confirm } = useFeedback();
    const [roles, setRoles] = useState(initialRoles || []);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingRole, setEditingRole] = useState(null);
    const [allPermissions, setAllPermissions] = useState({});
    const [loadingPermissions, setLoadingPermissions] = useState(false);

    const { data, setData, put, processing, reset } = useForm({
        name: '',
        permissions: [],
    });

    useEffect(() => {
        setRoles(initialRoles || []);
    }, [initialRoles]);

    const openEdit = async (role) => {
        setEditingRole(role);
        setData('name', role.name || '');
        setData(
            'permissions',
            (role.permissions || []).map((p) => p.name)
        );

        setLoadingPermissions(true);
        try {
            const res = await fetch(
                route('settings.roles.index').replace(
                    '/settings/roles',
                    '/settings/roles/permissions'
                )
            );
            const json = await res.json();
            setAllPermissions(json);
            setModalOpen(true);
        } catch (e) {
            console.error('Failed to load permissions', e);
        } finally {
            setLoadingPermissions(false);
        }
    };

    const togglePermission = (perm) => {
        const set = new Set(data.permissions || []);
        if (set.has(perm)) set.delete(perm);
        else set.add(perm);
        setData('permissions', Array.from(set));
    };

    const save = (e) => {
        e.preventDefault();
        if (!editingRole) return;

        put(route('settings.roles.update', editingRole.id), {
            preserveState: false,
            onSuccess: () => {
                setModalOpen(false);
                reset();
            },
        });
    };

    const createRole = () => {
        router.get(route('settings.roles.create'));
    };

    const deleteRole = async (roleId) => {
        const ok = await confirm({
            title: 'Delete this role?',
            message: 'Users holding this role will lose its permissions immediately. This cannot be undone.',
            tone: 'danger',
            confirmLabel: 'Delete role',
        });
        if (!ok) return;

        router.delete(route('settings.roles.destroy', roleId));
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Role Manager" />

            <div className="bg-white shadow-sm rounded-xl border border-gray-100 overflow-hidden">
                {/* Header Section */}
                <div className="p-6 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                    <div>
                        <h2 className="text-xl font-bold text-gray-900">Role Manager</h2>
                        <p className="text-sm text-gray-500 mt-0.5">Manage system roles and assign granular module permissions.</p>
                    </div>
                    {can('roles.manage') && (
                        <button
                            onClick={createRole}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-medium text-sm rounded-lg shadow-sm transition-all"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Create Role
                        </button>
                    )}
                </div>

                {/* Table Section */}
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-gray-50 text-gray-400 text-xs uppercase tracking-wider font-semibold border-b border-gray-100">
                                <th className="py-3 px-6">Role Name</th>
                                <th className="py-3 px-6">Permissions</th>
                                <th className="py-3 px-6">Users Assigned</th>
                                <th className="py-3 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 text-sm">
                            {roles.length > 0 ? (
                                roles.map((r) => (
                                    <tr key={r.id} className="hover:bg-gray-50/60 transition-colors">
                                        <td className="py-4 px-6 font-semibold text-gray-900">
                                            {formatRoleName(r.name)}
                                        </td>
                                        <td className="py-4 px-6 max-w-md">
                                            <div className="flex flex-wrap gap-1.5">
                                                {(r.permissions || []).length > 0 ? (
                                                    (r.permissions || []).map((p) => (
                                                        <span 
                                                            key={p.name}
                                                            className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100"
                                                        >
                                                            {formatPermissionLabel(p.name)}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-gray-400 italic text-xs">No permissions assigned</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-4 px-6 text-gray-600 font-medium">
                                            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                                {r.users_count || 0} {r.users_count === 1 ? 'user' : 'users'}
                                            </span>
                                        </td>
                                        <td className="py-4 px-6 text-right space-x-3">
                                            {can('roles.manage') && (
                                                <>
                                                    <button
                                                        onClick={() => openEdit(r)}
                                                        className="text-indigo-600 hover:text-indigo-900 font-medium transition-colors"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => deleteRole(r.id)}
                                                        className="text-red-500 hover:text-red-700 font-medium transition-colors"
                                                    >
                                                        Delete
                                                    </button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="4" className="py-12 text-center text-gray-400">
                                        No roles found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Edit Modal */}
            {modalOpen && (
                <div className="fixed inset-0 bg-gray-900/50 backdrop-blur-xs flex items-center justify-center p-4 z-50 animate-fade-in">
                    <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden border border-gray-100 flex flex-col max-h-[90vh]">
                        
                        {/* Modal Header */}
                        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                            <div>
                                <h3 className="text-lg font-bold text-gray-900">
                                    Edit Role: <span className="text-indigo-600">{formatRoleName(editingRole?.name)}</span>
                                </h3>
                                <p className="text-xs text-gray-500">Modify the role name and adjust module permissions below.</p>
                            </div>
                            <button 
                                onClick={() => setModalOpen(false)}
                                className="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-200/55 transition-colors"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Modal Form Body */}
                        <form onSubmit={save} className="p-6 space-y-6 overflow-y-auto flex-1">
                            <div>
                                <label className="block text-sm font-semibold text-gray-700 mb-1.5">Role Name</label>
                                <input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 px-3.5 py-2 rounded-lg text-sm transition-all outline-none"
                                    placeholder="Enter role name..."
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-semibold text-gray-700 mb-2">Permissions Access</label>
                                {loadingPermissions ? (
                                    <div className="py-8 text-center text-gray-400 text-sm">Loading permissions...</div>
                                ) : (
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        {Object.keys(allPermissions).map((module) => {
                                            const modulePerms = allPermissions[module].map((p) => p.name);
                                            const hasAll = modulePerms.every((p) => (data.permissions || []).includes(p));

                                            return (
                                                <div key={module} className="p-4 border border-gray-200 rounded-xl bg-gray-50/50 space-y-3">
                                                    <div className="flex items-center justify-between pb-2 border-b border-gray-200">
                                                        <strong className="capitalize text-gray-800 text-sm">{module}</strong>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                const set = new Set(data.permissions || []);
                                                                if (hasAll) {
                                                                    modulePerms.forEach((p) => set.delete(p));
                                                                } else {
                                                                    modulePerms.forEach((p) => set.add(p));
                                                                }
                                                                setData('permissions', Array.from(set));
                                                            }}
                                                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors"
                                                        >
                                                            {hasAll ? 'Deselect All' : 'Select All'}
                                                        </button>
                                                    </div>

                                                    <div className="space-y-2">
                                                        {allPermissions[module].map((p) => (
                                                            <label
                                                                key={p.name}
                                                                className="flex items-center gap-3 text-sm text-gray-600 hover:text-gray-900 cursor-pointer select-none"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={(data.permissions || []).includes(p.name)}
                                                                    onChange={() => togglePermission(p.name)}
                                                                    className="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                                                                />
                                                                <span className="font-medium text-xs">{formatPermissionLabel(p.name)}</span>
                                                            </label>
                                                        ))}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </form>

                        {/* Modal Footer */}
                        <div className="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => setModalOpen(false)}
                                className="px-4 py-2 border border-gray-300 hover:bg-gray-100 text-gray-700 font-medium text-sm rounded-lg transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                onClick={save}
                                disabled={processing}
                                className="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50 text-white font-medium text-sm rounded-lg shadow-sm transition-all"
                            >
                                {processing ? 'Saving changes...' : 'Save Changes'}
                            </button>
                        </div>

                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}