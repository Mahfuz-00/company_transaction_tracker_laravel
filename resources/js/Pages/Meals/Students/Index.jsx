import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Spinner } from '@/Components/UI/Loading';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    user_id: '',
    manager_id: '',
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

/**
 * Status column: a clear green (or grey) dot with its label.
 *
 * Replaces the old combined "Active / Account linked" badges, which crammed
 * two different concepts into one pill and read as a wall of text in the table.
 */
function StatusDot({ status }) {
    const active = (status || 'active') === 'active';

    return (
        <span className="inline-flex items-center gap-2 text-xs font-semibold">
            <span className="relative flex h-2.5 w-2.5">
                {active && (
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                )}
                <span
                    className={`relative inline-flex h-2.5 w-2.5 rounded-full ${active ? 'bg-emerald-500' : 'bg-slate-300'}`}
                />
            </span>
            <span className={active ? 'text-emerald-700' : 'text-slate-500'}>
                {active ? 'Active' : 'Inactive'}
            </span>
        </span>
    );
}

/**
 * The per-meal rate calculator, shown as a summary strip. Explains the maths
 * inline: rate = total expense / total meals, with the daily/monthly split and
 * how much of the cost the subsidies cover.
 */
function RateBreakdown({ rate, money }) {
    if (!rate) return null;

    const cells = [
        { label: 'Total Expense', value: money(rate.total_expense, false) },
        { label: 'Total Meals', value: rate.total_meals },
        { label: 'Per-Meal Rate', value: money(rate.per_meal_rate, false), formula: true },
        { label: 'Daily Meals', value: rate.daily_meals },
        { label: 'Daily Cost', value: money(rate.daily_cost, false) },
        { label: 'Meals / Member', value: rate.meals_per_member },
        { label: 'Subsidy Covers', value: `${rate.subsidy_coverage_pct ?? 0}%`, tone: 'text-sky-600' },
        { label: 'Members Cover', value: `${rate.member_funded_pct ?? 0}%`, tone: 'text-emerald-600' },
    ];

    return (
        <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                    Per-Meal Rate Calculation
                </h4>
                <code className="rounded-md bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                    rate = total expense ÷ total meals
                </code>
            </div>

            <div className="mt-3 grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                {cells.map((cell) => (
                    <div key={cell.label} className="rounded-lg border-slate-100 bg-slate-50/60 px-3 py-2">
                        <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                            {cell.label}
                        </div>
                        <div className={`mt-0.5 text-sm font-bold ${cell.tone || 'text-slate-800'}`}>
                            {cell.value}
                        </div>
                    </div>
                ))}
            </div>

            <p className="mt-2 text-[11px] text-slate-400">
                Figures cover <strong className="font-semibold text-slate-500">{rate.month_label}</strong>. Changing
                the month above recalculates every rate and balance on this page.
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function Index({ students, departments, managers, costPerMeal, rateBreakdown, months, month, filters }) {
    const { can } = useCan();
    const { t } = useTerminology();
    const { flash } = usePage().props;
    const money = useMoney();
    const canManage = can('students.manage');
    const canInvite = can('students.invite');
    const canExport = can('exports.download');

    const memberWord = t('member', 'Member');

    const [modalOpen, setModalOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [inviteTarget, setInviteTarget] = useState(null);
    const [inviteEmail, setInviteEmail] = useState('');
    const [inviteProcessing, setInviteProcessing] = useState(false);
    const [inviteError, setInviteError] = useState(null);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = students?.data || [];
    const isEditing = Boolean(editing);

    const departmentOptions = useMemo(
        () => [
            { value: '', label: `— No ${t('department', 'group').toLowerCase()} —` },
            ...(departments || []).map((department) => ({
                value: String(department.id),
                label: department.name,
            })),
        ],
        [departments, t]
    );

    // "Which user or meal manager manages this member record" - deliberately
    // not a login selector. The member's own login is created via invitation.
    const managerOptions = useMemo(() => {
        const list = (managers || []).map((m) => ({
            value: String(m.id),
            label: `${m.name}${m.email ? ` (${m.email})` : ''}`,
        }));

        // Keep the current manager visible even if they fall outside the filter.
        if (editing?.manager_id && !list.some((o) => o.value === String(editing.manager_id))) {
            list.unshift({ value: String(editing.manager_id), label: editing.manager_name || 'Current manager' });
        }

        return [{ value: '', label: '— Unassigned —' }, ...list];
    }, [managers, editing]);

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
            manager_id: student.manager_id ? String(student.manager_id) : '',
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
        if (!confirm(`Remove "${student.name}" from the roster?`)) return;

        router.delete(route('meals.students.destroy', student.id), {
            preserveScroll: true,
        });
    };

    // Invitation flow: capture the email, then let the server issue a signed
    // link. No password is ever set from this screen.
    const openInvite = (student) => {
        setInviteTarget(student);
        setInviteEmail('');
        setInviteError(null);
        setInviteOpen(true);
    };

    const sendInvite = (event) => {
        event.preventDefault();
        setInviteProcessing(true);
        setInviteError(null);

        router.post(
            route('meals.students.invite', inviteTarget.id),
            { email: inviteEmail },
            {
                preserveScroll: true,
                onSuccess: () => setInviteOpen(false),
                onError: (errs) => setInviteError(errs.email || 'Could not send the invitation.'),
                onFinish: () => setInviteProcessing(false),
            }
        );
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
            title={t('members', 'Members')}
            description={`Everyone sharing the ${t('institution', 'mess').toLowerCase()}. Deposit and meal totals update automatically.`}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {canExport && (
                        <div className="flex overflow-hidden rounded-lg border-slate-300">
                            <a
                                href={`${route('meals.students.export')}?format=excel`}
                                className="border-r border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                Excel
                            </a>
                            <a
                                href={`${route('meals.students.export')}?format=pdf`}
                                target="_blank"
                                rel="noreferrer"
                                className="bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                PDF
                            </a>
                        </div>
                    )}
                    {canManage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add {memberWord}
                        </button>
                    )}
                </div>
            }
        >
            <Head title={t('members', 'Members')} />

            <Flash success={flash?.success} error={flash?.error} />

            {/* The per-meal rate calculator, with the daily/monthly split. */}
            <RateBreakdown rate={rateBreakdown} money={money} />

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Filters */}
                <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                    {/* Month selector: every figure below is scoped to it. */}
                    <select
                        value={month || ''}
                        onChange={(event) => applyFilters({ month: event.target.value })}
                        aria-label="Report month"
                        className="rounded-lg border-slate-300 text-sm font-semibold text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent-ring)]"
                    >
                        {(months || []).map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}{m.current ? ' (current)' : ''}
                            </option>
                        ))}
                    </select>

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
                        <option value="">All {t('departments', 'groups').toLowerCase()}</option>
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
                    <table className="w-full min-w-180 border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-4 py-3 sm:px-6">{memberWord}</th>
                                <th className="px-4 py-3 sm:px-6">Roll ID</th>
                                <th className="px-4 py-3 sm:px-6">{t('department', 'Group')}</th>
                                <th className="px-4 py-3 sm:px-6">Status</th>
                                <th className="px-4 py-3 text-right sm:px-6">Meals (mo.)</th>
                                <th className="px-4 py-3 text-right sm:px-6">Meal Cost</th>
                                <th className="px-4 py-3 text-right sm:px-6">Deposited</th>
                                <th className="px-4 py-3 text-right sm:px-6">Balance</th>
                                <th className="px-4 py-3 sm:px-6">Managed By</th>
                                <th className="px-4 py-3 text-right sm:px-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((student) => (
                                    <tr key={student.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-4 py-4 sm:px-6">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-xs font-bold text-white">
                                                    {initials(student.name)}
                                                </div>
                                                <div className="min-w-0">
                                                    <Link
                                                        href={route('meals.students.show', student.id)}
                                                        className="font-semibold text-slate-900 hover:text-[var(--accent)]"
                                                    >
                                                        {student.name}
                                                    </Link>
                                                    {/* A quiet hint about whether a login exists - it is
                                                        supplementary, so it is no longer competing with
                                                        the status indicator. */}
                                                    {student.user_id && (
                                                        <span className="text-[10px] font-medium text-slate-400">
                                                            Has account
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-4 font-medium text-slate-700 sm:px-6">
                                            {student.roll || <span className="text-slate-300">—</span>}
                                        </td>
                                        <td className="px-4 py-4 sm:px-6">
                                            {student.department ? (
                                                <span className="inline-flex items-center rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                                    {student.department.name}
                                                </span>
                                            ) : (
                                                <span className="text-xs italic text-slate-400">Unassigned</span>
                                            )}
                                        </td>
                                        {/* Dedicated status column with a clear dot. */}
                                        <td className="px-4 py-4 sm:px-6">
                                            <StatusDot status={student.status} />
                                        </td>
                                        <td className="px-4 py-4 text-right font-semibold text-slate-700 sm:px-6">
                                            {student.month_meals ?? 0}
                                        </td>
                                        <td className="px-4 py-4 text-right text-slate-600 sm:px-6">
                                            {money(student.meal_cost ?? 0)}
                                        </td>
                                        <td className="px-4 py-4 text-right font-medium text-slate-700 sm:px-6">
                                            {money(student.total_deposits ?? 0)}
                                        </td>
                                        <td className="px-4 py-4 text-right sm:px-6">
                                            {!costPerMeal ? (
                                                <span className="text-xs italic text-slate-400" title="Set a meal rate to compute balances">
                                                    No rate
                                                </span>
                                            ) : (
                                                <span className={`text-sm font-bold ${(student.balance ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
                                                    {money(Math.abs(student.balance ?? 0))}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-4 text-xs text-slate-500 sm:px-6">
                                            {student.manager_name || <span className="text-slate-300">—</span>}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-4 text-right sm:px-6">
                                            <Link
                                                href={route('meals.students.show', student.id)}
                                                className="font-medium text-slate-500 transition-colors hover:text-slate-800"
                                            >
                                                View
                                            </Link>
                                            {canManage && (
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(student)}
                                                    className="ml-3 font-medium text-indigo-600 transition-colors hover:text-indigo-900"
                                                >
                                                    Edit
                                                </button>
                                            )}
                                            {canInvite && !student.user_id && (
                                                <button
                                                    type="button"
                                                    onClick={() => openInvite(student)}
                                                    className="ml-3 font-medium text-amber-600 transition-colors hover:text-amber-800"
                                                >
                                                    Invite
                                                </button>
                                            )}
                                            {canManage && (
                                                <button
                                                    type="button"
                                                    onClick={() => remove(student)}
                                                    className="ml-3 font-medium text-rose-500 transition-colors hover:text-rose-700"
                                                >
                                                    Remove
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="10" className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {hasFilters
                                                ? `No ${memberWord.toLowerCase()}s match these filters.`
                                                : `No ${memberWord.toLowerCase()}s on the roster yet.`}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            {hasFilters
                                                ? 'Try clearing the filters.'
                                                : `Add ${memberWord.toLowerCase()}s so deposits and meals can be tracked.`}
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
                                                Add the first {memberWord.toLowerCase()}
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
                title={isEditing ? `Edit ${editing?.name}` : `Add ${memberWord}`}
                description={
                    isEditing
                        ? `Update this ${memberWord.toLowerCase()}'s profile.`
                        : 'Add someone to the roster.'
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
                            form="member-form"
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
                <form id="member-form" onSubmit={submit} className="space-y-4">
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
                            label="Roll ID"
                            name="roll"
                            value={data.roll}
                            error={errors.roll}
                            placeholder="e.g. CS-2021-045"
                            hint="Shown everywhere instead of the internal ID."
                            onChange={(event) => setData('roll', event.target.value)}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('department', 'Group')}
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
                            label="Managed By"
                            name="manager_id"
                            type="select"
                            value={data.manager_id}
                            error={errors.manager_id}
                            hint="Which user or meal manager is responsible for this record."
                            options={managerOptions}
                            onChange={(event) => setData('manager_id', event.target.value)}
                        />
                    </div>

                    <div className="rounded-lg border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                        <strong className="font-semibold text-slate-700">No password is set here.</strong>{' '}
                        After saving, use <em>Invite</em> on the row to email a secure link so the
                        member chooses their own password and completes their account.
                    </div>
                </form>
            </Modal>

            {/* Invite modal */}
            <Modal
                open={inviteOpen}
                onClose={() => setInviteOpen(false)}
                title={`Invite ${inviteTarget?.name || ''}`}
                description="We email a secure signed link. The member sets their own password - no default password is issued."
                footer={
                    <>
                        <button
                            type="button"
                            onClick={() => setInviteOpen(false)}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            form="invite-form"
                            disabled={inviteProcessing}
                            className="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-amber-600 disabled:opacity-50"
                        >
                            {inviteProcessing && <Spinner className="h-4 w-4" />}
                            {inviteProcessing ? 'Sending...' : 'Send Invitation'}
                        </button>
                    </>
                }
            >
                <form id="invite-form" onSubmit={sendInvite} className="space-y-4">
                    <Field
                        label="Email address"
                        name="invite_email"
                        type="email"
                        required
                        value={inviteEmail}
                        error={inviteError}
                        placeholder="member@example.com"
                        hint="The invitation link expires in 7 days."
                        onChange={(event) => setInviteEmail(event.target.value)}
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
