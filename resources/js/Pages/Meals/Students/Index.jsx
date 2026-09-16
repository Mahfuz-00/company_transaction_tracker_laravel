import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import formatNumber from '@/Utils/numberFormatter';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    user_id: '',
    name: '',
    roll: '',
    department_id: '',
    join_date: '',
    status: 'active',
};

const initials = (name) =>
    name
        ? name
            .trim()
            .split(/\s+/)
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase()
        : '?';

/* ------------------------------------------------------------------ *
 * Small presentational pieces
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

function StatusPill({ status }) {
    const active = (status || 'active') === 'active';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${active
                    ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                    : 'border-slate-200 bg-slate-100 text-slate-500'
                }`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
            {active ? 'Active' : 'Inactive'}
        </span>
    );
}

/** Balance is money owed (negative) or credit left (positive). */
function BalanceCell({ balance, costPerMeal }) {
    if (!costPerMeal) {
        return (
            <span
                className="text-xs italic text-slate-400"
                title="Set a meal rate to compute balances"
            >
                No meal rate set
            </span>
        );
    }

    const value = Number(balance || 0);
    const owed = value < 0;

    return (
        <div className="text-right">
            <div className={`text-sm font-bold ${owed ? 'text-rose-600' : 'text-emerald-600'}`}>
                {formatNumber(Math.abs(value))}
            </div>
            <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                {owed ? 'Due' : 'Credit'}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function Index({ students, departments, users, costPerMeal, filters }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const canManage = can('students.manage');

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = students?.data || [];
    const isEditing = Boolean(editing);

    const departmentOptions = useMemo(
        () => [
            { value: '', label: '— No department —' },
            ...(departments || []).map((department) => ({
                value: String(department.id),
                label: department.name,
            })),
        ],
        [departments]
    );

    // A user already linked to this student stays selectable while editing.
    const userOptions = useMemo(() => {
        const options = [{ value: '', label: '— Not linked —' }];
        const list = (users || []).map((user) => ({
            value: String(user.id),
            label: `${user.name} (${user.email})`,
        }));

        if (editing?.user && !list.some((option) => option.value === String(editing.user.id))) {
            list.unshift({
                value: String(editing.user.id),
                label: `${editing.user.name} (${editing.user.email})`,
            });
        }

        return [...options, ...list];
    }, [users, editing]);

    const openCreate = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setEditing(null);
        setModalOpen(true);
    };

    const openEdit = (student) => {
        clearErrors();
        setEditing(student);
        setData({
            user_id: student.user_id ? String(student.user_id) : '',
            name: student.name || '',
            roll: student.roll || '',
            department_id: student.department_id ? String(student.department_id) : '',
            join_date: student.join_date ? String(student.join_date).slice(0, 10) : '',
            status: student.status || 'active',
        });
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
            put(route('meals.students.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('meals.students.store'), {
                preserveScroll: true,
                onSuccess: () => closeModal(),
            });
        }
    };

    const remove = (student) => {
        if (!confirm(`Remove "${student.name}" from the meal roster?`)) return;

        router.delete(route('meals.students.destroy', student.id), {
            preserveScroll: true,
        });
    };

    const applyFilters = (next) => {
        router.get(
            route('meals.students.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const submitSearch = (event) => {
        event.preventDefault();
        applyFilters({ search });
    };

    const hasFilters =
        Boolean(filters?.search) || Boolean(filters?.department) || Boolean(filters?.status);

    return (
        <MealsLayout
            title="Students"
            description="Everyone sharing the mess. Deposit and meal totals update automatically."
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
                        Add Student
                    </button>
                )
            }
        >
            <Head title="Students" />

            <Flash success={flash?.success} error={flash?.error} />

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Filters */}
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
                            placeholder="Search by name or roll..."
                            className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    <select
                        value={filters?.department || ''}
                        onChange={(event) => applyFilters({ department: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All departments</option>
                        {(departments || []).map((department) => (
                            <option key={department.id} value={department.id}>
                                {department.name}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters?.status || ''}
                        onChange={(event) => applyFilters({ status: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('meals.students.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800 sm:ml-auto"
                        >
                            Clear filters
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">Student</th>
                                <th className="px-6 py-3">Roll</th>
                                <th className="px-6 py-3">Department</th>
                                <th className="px-6 py-3 text-right">Meals</th>
                                <th className="px-6 py-3 text-right">Deposited</th>
                                <th className="px-6 py-3 text-right">Balance</th>
                                <th className="px-6 py-3">Status</th>
                                <th className="px-6 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((student) => (
                                    <tr key={student.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-xs font-bold text-white">
                                                    {initials(student.name)}
                                                </div>
                                                <div className="min-w-0">
                                                    <Link
                                                        href={route('meals.students.show', student.id)}
                                                        className="font-semibold text-slate-900 hover:text-indigo-600"
                                                    >
                                                        {student.name}
                                                    </Link>
                                                    {student.user?.email && (
                                                        <div className="truncate text-xs text-slate-400">
                                                            {student.user.email}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {student.roll || <span className="text-slate-300">—</span>}
                                        </td>
                                        <td className="px-6 py-4">
                                            {student.department ? (
                                                <span className="inline-flex items-center rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {student.department.name}
                                                </span>
                                            ) : (
                                                <span className="text-xs italic text-slate-400">Unassigned</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-right font-semibold text-slate-700">
                                            {student.total_meals ?? 0}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium text-slate-700">
                                            {formatNumber(student.total_deposits ?? 0)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <BalanceCell
                                                balance={student.balance}
                                                costPerMeal={costPerMeal}
                                            />
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusPill status={student.status} />
                                        </td>
                                        <td className="whitespace-nowrap px-6 py-4 text-right">
                                            <Link
                                                href={route('meals.students.show', student.id)}
                                                className="font-medium text-slate-500 transition-colors hover:text-slate-800"
                                            >
                                                View
                                            </Link>
                                            {canManage && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEdit(student)}
                                                        className="ml-4 font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => remove(student)}
                                                        className="ml-4 font-medium text-rose-500 transition-colors hover:text-rose-700"
                                                    >
                                                        Remove
                                                    </button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="8" className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {hasFilters
                                                ? 'No students match these filters.'
                                                : 'No students on the roster yet.'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            {hasFilters
                                                ? 'Try clearing the filters.'
                                                : 'Add students so deposits and meals can be tracked.'}
                                        </p>
                                        {!hasFilters && canManage && (
                                            <button
                                                type="button"
                                                onClick={openCreate}
                                                className="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                                            >
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                                                </svg>
                                                Add the first student
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {students?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{students.from}</strong>–<strong>{students.to}</strong> of{' '}
                            <strong>{students.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {students.links.map((link, index) => (
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
                title={isEditing ? `Edit ${editing?.name}` : 'Add Student'}
                description={
                    isEditing
                        ? 'Update this student\'s profile and department.'
                        : 'Add someone to the meal roster.'
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
                            form="student-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : isEditing ? 'Save Changes' : 'Add Student'}
                        </button>
                    </>
                }
            >
                <form id="student-form" onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Full Name"
                            name="name"
                            required
                            value={data.name}
                            error={errors.name}
                            placeholder="e.g. Farhan Hossain"
                            onChange={(event) => setData('name', event.target.value)}
                        />

                        <Field
                            label="Roll / ID"
                            name="roll"
                            value={data.roll}
                            error={errors.roll}
                            placeholder="e.g. CS-2021-045"
                            onChange={(event) => setData('roll', event.target.value)}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Department"
                            name="department_id"
                            type="select"
                            value={data.department_id}
                            error={errors.department_id}
                            options={departmentOptions}
                            onChange={(event) => setData('department_id', event.target.value)}
                        />

                        <Field
                            label="Join Date"
                            name="join_date"
                            type="date"
                            value={data.join_date}
                            error={errors.join_date}
                            onChange={(event) => setData('join_date', event.target.value)}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Status"
                            name="status"
                            type="select"
                            required
                            value={data.status}
                            error={errors.status}
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                            onChange={(event) => setData('status', event.target.value)}
                        />

                        <Field
                            label="Linked User Account"
                            name="user_id"
                            type="select"
                            value={data.user_id}
                            error={errors.user_id}
                            hint="Optional. Lets this student sign in and see their own balance."
                            options={userOptions}
                            onChange={(event) => setData('user_id', event.target.value)}
                        />
                    </div>
                </form>
            </Modal>
        </MealsLayout>
    );
}
