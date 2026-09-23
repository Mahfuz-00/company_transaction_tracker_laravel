import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

/**
 * Roles list page — a read-only overview of every role in the workspace.
 *
 * Inertia page component. Laravel hands it everything it needs as PROPS, so the
 * page itself holds no state and makes no requests: it renders the table and
 * links out to the create/edit screens.
 *
 * PROPS
 *   - `auth`  : the shared auth prop (here only `auth.user`), passed to the
 *               layout so the chrome can show who is signed in.
 *   - `roles` : roles from the server. Each item carries `id`, `name`, a nested
 *               `permissions` array (each with a `name`) and a `users_count`.
 *
 * Inertia / React concepts on show:
 *   - `<Head title="Roles" />` sets the document <title> for this page.
 *   - `route('roles.create' | 'roles.edit', id)` is Ziggy URL building.
 *   - `.map()` over `roles` renders one <tr> per row; React needs the `key` prop
 *     so it can identify rows across re-renders (the mobile equivalent of a
 *     stable list-diff id).
 */
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
                            {/* One <tr> per role; the `key` prop keeps React's list
                                diff stable across re-renders. */}
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
