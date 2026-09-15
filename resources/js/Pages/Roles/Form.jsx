import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Form({ auth, role, permissions }) {
    const existing = role ? {
        name: role.name,
        permissions: role.permissions.map(p => p.name),
    } : { name: '', permissions: [] };

    const { data, setData, post, put, processing, errors } = useForm(existing);

    const togglePermission = (perm) => {
        const set = new Set(data.permissions || []);
        if (set.has(perm)) set.delete(perm);
        else set.add(perm);
        setData('permissions', Array.from(set));
    };

    const submit = (e) => {
        e.preventDefault();
        if (role) {
            put(route('roles.update', role.id));
        } else {
            post(route('roles.store'));
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">{role ? 'Edit Role' : 'Create Role'}</h2>}>
            <Head title={role ? 'Edit Role' : 'Create Role'} />

            <div className="py-6 px-6 w-full">
                <div className="p-6 bg-white rounded shadow">
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">Role name</label>
                            <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full border px-3 py-2 rounded" />
                            {errors.name && <p className="text-red-500 text-xs">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Permissions</label>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                                {Object.keys(permissions).map(module => (
                                    <div key={module} className="p-3 border rounded">
                                        <div className="flex items-center justify-between mb-2">
                                            <strong className="capitalize">{module}</strong>
                                            <button type="button" onClick={() => {
                                                // toggle all
                                                const perms = permissions[module].map(p => p.name);
                                                const hasAll = perms.every(p => (data.permissions || []).includes(p));
                                                const set = new Set(data.permissions || []);
                                                if (hasAll) {
                                                    perms.forEach(p => set.delete(p));
                                                } else {
                                                    perms.forEach(p => set.add(p));
                                                }
                                                setData('permissions', Array.from(set));
                                            }} className="text-xs text-indigo-600">Toggle All</button>
                                        </div>

                                        {permissions[module].map(p => (
                                            <label key={p.name} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={(data.permissions || []).includes(p.name)} onChange={() => togglePermission(p.name)} />
                                                <span>{p.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div>
                            <button type="submit" disabled={processing} className="px-4 py-2 bg-indigo-600 text-white rounded">{processing ? 'Saving...' : 'Save Role'}</button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
