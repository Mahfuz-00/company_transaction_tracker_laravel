import React, { useState, useEffect } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, router, useForm } from '@inertiajs/react';
import useCan from '@/Utils/can';

export default function RoleManager({ auth, roles: initialRoles }) {
    const { can } = useCan();
    const [roles, setRoles] = useState(initialRoles || []);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingRole, setEditingRole] = useState(null);
    const [allPermissions, setAllPermissions] = useState({});
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

    const deleteRole = (roleId) => {
        if (!confirm('Delete this role?')) return;
        router.delete(route('settings.roles.destroy', roleId));
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Role Manager" />

            <div className="bg-white p-6 rounded-lg border">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-bold">Roles</h2>
                    {can('roles.manage') && (
                        <button
                            onClick={createRole}
                            className="px-3 py-2 bg-indigo-600 text-white rounded"
                        >
                            Create Role
                        </button>
                    )}
                </div>

                <div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-gray-500">
                                <th className="p-2">Name</th>
                                <th className="p-2">Permissions</th>
                                <th className="p-2">Users</th>
                                <th className="p-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((r) => (
                                <tr key={r.id} className="border-t">
                                    <td className="p-2 font-medium">{r.name}</td>
                                    <td className="p-2 text-xs text-gray-600">
                                        {(r.permissions || [])
                                            .map((p) => p.name)
                                            .join(', ')}
                                    </td>
                                    <td className="p-2">{r.users_count || 0}</td>
                                    <td className="p-2">
                                        {can('roles.manage') && (
                                            <>
                                                <button
                                                    onClick={() => openEdit(r)}
                                                    className="text-indigo-600 mr-3"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    onClick={() => deleteRole(r.id)}
                                                    className="text-red-600"
                                                >
                                                    Delete
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Edit Modal */}
            {modalOpen && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
                    <div className="bg-white rounded-lg w-full max-w-2xl p-6">
                        <h3 className="text-lg font-bold mb-4">
                            Edit Role: {editingRole?.name}
                        </h3>
                        <form onSubmit={save} className="space-y-4">
                            <div>
                                <label className="text-sm font-medium">Name</label>
                                <input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full border px-3 py-2 rounded"
                                />
                            </div>

                            <div>
                                <label className="text-sm font-medium">Permissions</label>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
                                    {Object.keys(allPermissions).map((module) => (
                                        <div key={module} className="p-3 border rounded">
                                            <div className="flex items-center justify-between mb-2">
                                                <strong className="capitalize">{module}</strong>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        const perms = allPermissions[module].map(
                                                            (p) => p.name
                                                        );
                                                        const hasAll = perms.every((p) =>
                                                            (data.permissions || []).includes(p)
                                                        );
                                                        const set = new Set(data.permissions || []);
                                                        if (hasAll) {
                                                            perms.forEach((p) => set.delete(p));
                                                        } else {
                                                            perms.forEach((p) => set.add(p));
                                                        }
                                                        setData('permissions', Array.from(set));
                                                    }}
                                                    className="text-xs text-indigo-600"
                                                >
                                                    Toggle All
                                                </button>
                                            </div>

                                            {allPermissions[module].map((p) => (
                                                <label
                                                    key={p.name}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={(data.permissions || []).includes(
                                                            p.name
                                                        )}
                                                        onChange={() => togglePermission(p.name)}
                                                    />
                                                    <span>{p.name}</span>
                                                </label>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setModalOpen(false)}
                                    className="px-3 py-2 border rounded"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded"
                                >
                                    {processing ? 'Saving...' : 'Save'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}