import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Edit({ auth, department }) {
    const { data, setData, put, processing, errors } = useForm({ name: department.name || '', slug: department.slug || '', description: department.description || '' });

    const submit = (e) => {
        e.preventDefault();
        put(route('meals.departments.update', department.id));
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="font-bold text-lg">Edit Department</h2>}>
            <Head title="Edit Department" />
            <form onSubmit={submit} className="p-6 bg-white rounded-2xl shadow-sm">
                <div className="space-y-3">
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Name</label>
                        <input value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full mt-1 p-2 border rounded-lg" />
                        {errors.name && <div className="text-xs text-red-600">{errors.name}</div>}
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Slug (optional)</label>
                        <input value={data.slug} onChange={(e) => setData('slug', e.target.value)} className="w-full mt-1 p-2 border rounded-lg" />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Description</label>
                        <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full mt-1 p-2 border rounded-lg" />
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="submit" disabled={processing} className="px-4 py-2 bg-indigo-600 text-white rounded-lg">Save</button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
