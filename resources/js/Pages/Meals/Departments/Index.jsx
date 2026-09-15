import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ auth, departments }) {
    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-bold text-lg">Departments</h2>}>
            <Head title="Departments" />
            <div className="p-6 bg-white rounded-2xl shadow-sm">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="font-semibold">Departments</h3>
                    <Link href={route('meals.departments.create')} className="text-sm text-indigo-600 font-medium">New Department</Link>
                </div>

                <ul className="space-y-2">
                    {departments.data?.map((d) => (
                        <li key={d.id} className="flex items-center justify-between p-3 border rounded-lg">
                            <div>
                                <div className="font-medium">{d.name}</div>
                                <div className="text-xs text-slate-500">{d.description}</div>
                            </div>
                            <div>
                                <Link href={route('meals.departments.edit', d.id)} className="text-sm text-indigo-600">Edit</Link>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </AuthenticatedLayout>
    );
}
