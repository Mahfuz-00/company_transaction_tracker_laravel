import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ auth, roles }) {
    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Roles</h2>}>
            <Head title="Roles" />

            <div className="py-6 px-6 w-full">
                <div className="p-6 bg-white rounded shadow">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-bold">Roles</h3>
                        <Link href={route('roles.create')} className="px-3 py-2 bg-indigo-600 text-white rounded">Create Role</Link>
                    </div>

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
                                    <td className="p-2 text-xs text-gray-600">{r.permissions.map(p => p.name).join(', ')}</td>
                                    <td className="p-2">{r.users_count || 0}</td>
                                    <td className="p-2">
                                        <Link href={route('roles.edit', r.id)} className="text-indigo-600">Edit</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
