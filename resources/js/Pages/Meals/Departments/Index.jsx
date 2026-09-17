import React, { useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useTerminology from '@/Utils/useTerminology';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

/* ------------------------------------------------------------------ *
 * Shared bits
 * ------------------------------------------------------------------ */

function Flash({ success, error }) {
    if (!success && !error) return null;

    const isError = Boolean(error);

    return (
        <div
            role="status"
            className={`flex items-center gap-2 rounded-lg border p-3 text-sm font-medium ${isError
                    ? 'border-rose-200 bg-rose-50 text-rose-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                }`}
        >
            <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {isError ? (
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                ) : (
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                )}
            </svg>
            {error || success}
        </div>
    );
}

function EmptyState({ search, canManage, onCreate, groupNoun, groupPlural, memberNoun }) {
    return (
        <div className="py-14 text-center">
            <svg className="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            <p className="mt-3 text-sm font-semibold text-slate-600">
                {search
                    ? `No ${groupPlural.toLowerCase()} match your search.`
                    : `No ${groupPlural.toLowerCase()} yet.`}
            </p>
            <p className="mt-1 text-xs text-slate-400">
                {search
                    ? 'Try a different term.'
                    : `${groupPlural} group ${memberNoun.toLowerCase()} together.`}
            </p>
            {!search && canManage && (
                <button
                    type="button"
                    onClick={onCreate}
                    className="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                >
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add the first {groupNoun.toLowerCase()}
                </button>
            )}
        </div>
    );
}

const EMPTY_FORM = { name: '', slug: '', description: '' };

const slugify = (value) =>
    value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function Index({ departments, filters }) {
    const { can } = useCan();
    const { t } = useTerminology();
    const { flash } = usePage().props;
    const { confirm } = useFeedback();
    const canManage = can('departments.manage');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [slugTouched, setSlugTouched] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = departments?.data || [];
    const isEditing = Boolean(editing);

    const openCreate = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setSlugTouched(false);
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (department) => {
        clearErrors();
        setEditing(department);
        setData({
            name: department.name || '',
            slug: department.slug || '',
            description: department.description || '',
        });
        setSlugTouched(true);
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditing(null);
        reset();
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('meals.departments.update', editing.slug), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('meals.departments.store'), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        }
    };

    const remove = async (department) => {
        const ok = await confirm({
            title: `Delete "${department.name}"?`,
            message: 'This cannot be undone. Groups that still have members cannot be deleted - reassign them first.',
            tone: 'danger',
            confirmLabel: 'Delete group',
        });
        if (!ok) return;

        router.delete(route('meals.departments.destroy', department.slug), {
            preserveScroll: true,
        });
    };

    const submitSearch = (event) => {
        event.preventDefault();
        router.get(
            route('meals.departments.index'),
            { search },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const clearSearch = () => {
        setSearch('');
        router.get(route('meals.departments.index'), {}, { replace: true });
    };

    return (
        <MealsLayout
            title={t('departments', 'Groups')}
            description={`Group ${t('members', 'members').toLowerCase()} so meal costs can be attributed accurately.`}
            actions={
                canManage && (
                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        New {t('department', 'Group')}
                    </button>
                )
            }
        >
            <Head title={t('departments', 'Groups')} />

            <Flash success={flash?.success} error={flash?.error} />

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Search */}
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center">
                    <form onSubmit={submitSearch} className="relative max-w-sm flex-1">
                        <svg
                            className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={`Search ${t('departments', 'departments').toLowerCase()}...`}
                            className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    {filters?.search && (
                        <button
                            type="button"
                            onClick={clearSearch}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800 sm:ml-auto"
                        >
                            Clear search
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">{t('department', 'Group')}</th>
                                <th className="px-6 py-3">Slug</th>
                                <th className="px-6 py-3">{t('members', 'Members')}</th>
                                <th className="px-6 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((department) => (
                                    <tr key={department.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-6 py-4">
                                            <div className="font-semibold text-slate-900">
                                                {department.name}
                                            </div>
                                            {department.description && (
                                                <div className="mt-0.5 line-clamp-1 max-w-md text-xs text-slate-500">
                                                    {department.description}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <code className="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                                {department.slug}
                                            </code>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="inline-flex items-center gap-1.5 rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                                {department.students_count || 0}
                                                {department.active_students_count > 0 && (
                                                    <span className="font-normal text-emerald-600">
                                                        ({department.active_students_count} active)
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right">
                                            <Link
                                                href={route('meals.students.index', { department: department.id })}
                                                className="font-medium text-slate-500 transition-colors hover:text-slate-800"
                                            >
                                                View {t('members', 'members').toLowerCase()}
                                            </Link>
                                            {canManage && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(department)}
                                                        className="ml-4 font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => remove(department)}
                                                        className="ml-4 font-medium text-rose-500 transition-colors hover:text-rose-700"
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
                                    <td colSpan="4">
                                        <EmptyState
                                            search={filters?.search}
                                            canManage={canManage}
                                            onCreate={openCreate}
                                            groupNoun={t('department', 'Group')}
                                            groupPlural={t('departments', 'Groups')}
                                            memberNoun={t('members', 'members')}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {departments?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{departments.from}</strong>–<strong>{departments.to}</strong> of{' '}
                            <strong>{departments.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {departments.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : link.url
                                                ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                : 'pointer-events-none border-slate-100 bg-white text-slate-300'
                                        }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Create / Edit modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title={isEditing ? `Edit "${editing?.name}"` : `New ${t('department', 'Group')}`}
                description={
                    isEditing
                        ? 'Update the name, slug, or description. Changes apply immediately.'
                        : `Create a ${t('department', 'group').toLowerCase()} to group ${t('members', 'members').toLowerCase()} under.`
                }
                footer={
                    <>
                        <button
                            type="button"
                            onClick={closeModal}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="department-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : isEditing ? 'Save Changes' : `Create ${t('department', 'Group')}`}
                        </button>
                    </>
                }
            >
                <form id="department-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label={`${t('department', 'Group')} Name`}
                        name="name"
                        required
                        value={data.name}
                        error={errors.name}
                        placeholder="e.g. Computer Science"
                        onChange={(event) => {
                            const value = event.target.value;
                            setData((current) => ({
                                ...current,
                                name: value,
                                // Auto-fill the slug until the user edits it manually.
                                slug: slugTouched ? current.slug : slugify(value),
                            }));
                        }}
                    />

                    <Field
                        label="Slug"
                        name="slug"
                        value={data.slug}
                        error={errors.slug}
                        placeholder="computer-science"
                        hint="Used in the URL. Generated from the name if left blank."
                        onChange={(event) => {
                            setSlugTouched(true);
                            setData('slug', event.target.value);
                        }}
                    />

                    <Field
                        label="Description"
                        name="description"
                        type="textarea"
                        value={data.description}
                        error={errors.description}
                        placeholder={`Short note about this ${t('department', 'group').toLowerCase()}...`}
                        onChange={(event) => setData('description', event.target.value)}
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
