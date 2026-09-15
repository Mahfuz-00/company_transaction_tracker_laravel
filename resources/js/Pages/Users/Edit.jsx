import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Edit({ auth, user, roles }) {
    const { data, setData, post, processing } = useForm({ roles: user.roles.map(r => r.name) });

    const toggle = (r) => {
        const set = new Set(data.roles || []);
        if (set.has(r)) set.delete(r); else set.add(r);
        setData('roles', Array.from(set));
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('users.roles.update', user.id));
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl">Edit User</h2>}>
            <Head title="Edit User" />

            <div className="py-6 px-6 w-full">
                <div className="p-6 bg-white rounded shadow">
                    <h3 className="text-lg font-bold mb-4">{user.name}</h3>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="text-sm font-medium">Assign Roles</label>
                            <div className="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                                {roles.map(r => (
                                    <label key={r.id} className="flex items-center gap-2">
                                        <input type="checkbox" checked={(data.roles || []).includes(r.name)} onChange={() => toggle(r.name)} />
                                        <span>{r.name}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div>
                            <button type="submit" disabled={processing} className="px-4 py-2 bg-indigo-600 text-white rounded">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
