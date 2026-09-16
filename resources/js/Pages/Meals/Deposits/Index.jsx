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
    student_id: '',
    amount: '',
    payment_method: 'Cash',
    notes: '',
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

export default function Index({ deposits, students, kinds, filteredTotal, personalTotal, subsidyAllocated, subsidyGrants, filters }) {
    const { can } = useCan();
    const { t } = useTerminology();
    const { flash } = usePage().props;
    const money = useMoney();
    const canRecord = can('meals.deposit');
    const canExport = can('exports.download');

    const [modalOpen, setModalOpen] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({ ...EMPTY_FORM });

    const rows = deposits?.data || [];

    const studentOptions = useMemo(
        () => [
            { value: '', label: '— Select a student —' },
            ...(students || []).map((student) => ({
                value: String(student.id),
                label: student.roll ? `${student.name} (${student.roll})` : student.name,
            })),
        ],
        [students]
    );

    const openModal = () => {
        clearErrors();
        reset();
        setData({ ...EMPTY_FORM });
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        reset();
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('meals.deposits.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    };

    const applyFilters = (next) => {
        router.get(
            route('meals.deposits.index'),
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const hasFilters =
        Boolean(filters?.search) ||
        Boolean(filters?.student) ||
        Boolean(filters?.kind) ||
        Boolean(filters?.from) ||
        Boolean(filters?.to);

    const exportUrl = (format) => {
        const params = new URLSearchParams({ format });
        if (filters?.from) params.set('from', filters.from);
        if (filters?.to) params.set('to', filters.to);
        if (filters?.kind) params.set('kind', filters.kind);
        if (filters?.student) params.set('student', filters.student);
        return `${route('meals.deposits.export')}?${params.toString()}`;
    };

    return (
        <MealsLayout
            title="Deposits"
            description="Money each student pays into the common pool. Every deposit is also recorded as a cash-in transaction."
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {canExport && (
                        <div className="flex overflow-hidden rounded-lg border-slate-300">
                            <a href={exportUrl('excel')} className="border-r border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50">Excel</a>
                            <a href={exportUrl('pdf')} target="_blank" rel="noreferrer" className="bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50">PDF</a>
                        </div>
                    )}
                    {canRecord && (
                        <button
                            type="button"
                            onClick={openModal}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Record Deposit
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Deposits" />

            <Flash success={flash?.success} error={flash?.error} />

            {/* Totals: personal deposits and subsidies reported separately. */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {hasFilters ? 'Total for current filter' : 'Total collected (all time)'}
                    </div>
                    <div className="mt-1 text-2xl font-bold text-emerald-600">
                        {money(filteredTotal, false)}
                    </div>
                </div>
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Personal Deposits
                    </div>
                    <div className="mt-1 text-2xl font-bold text-slate-800">
                        {money(personalTotal ?? 0, false)}
                    </div>
                    <div className="mt-0.5 text-[11px] text-slate-400">Paid in by {t('members', 'members').toLowerCase()}</div>
                </div>
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Institutional Subsidies
                    </div>
                    <div className="mt-1 text-2xl font-bold text-sky-600">
                        {money(subsidyGrants ?? subsidyAllocated ?? 0, false)}
                    </div>
                    <div className="mt-0.5 text-[11px] text-slate-400">Injected by an authority - tracked separately</div>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                {/* Filters */}
                <div className="flex flex-col gap-3 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({ search });
                        }}
                        className="relative max-w-xs flex-1"
                    >
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
                            placeholder="Search student or note..."
                            className="w-full rounded-lg border-slate-300 py-2 pl-9 pr-3 text-sm outline-none transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                        />
                    </form>

                    <select
                        value={filters?.student || ''}
                        onChange={(event) => applyFilters({ student: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All students</option>
                        {(students || []).map((student) => (
                            <option key={student.id} value={student.id}>
                                {student.name}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters?.kind || ''}
                        onChange={(event) => applyFilters({ kind: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">All types</option>
                        {(kinds || []).map((k) => (
                            <option key={k.value} value={k.value}>{k.label}</option>
                        ))}
                    </select>

                    <input
                        type="date"
                        value={filters?.from || ''}
                        onChange={(event) => applyFilters({ from: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        aria-label="From date"
                    />

                    <input
                        type="date"
                        value={filters?.to || ''}
                        onChange={(event) => applyFilters({ to: event.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                        aria-label="To date"
                    />

                    {hasFilters && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('meals.deposits.index'), {}, { replace: true });
                            }}
                            className="text-xs font-semibold text-indigo-600 transition-colors hover:text-indigo-800"
                        >
                            Clear
                        </button>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-6 py-3">{t('member', 'Member')}</th>
                                <th className="px-6 py-3">Amount</th>
                                <th className="px-6 py-3">Type</th>
                                <th className="px-6 py-3">Method</th>
                                <th className="px-6 py-3">Recorded By</th>
                                <th className="px-6 py-3">Date</th>
                                <th className="px-6 py-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? (
                                rows.map((deposit) => (
                                    <tr key={deposit.id} className="transition-colors hover:bg-slate-50/60">
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-[10px] font-bold text-white">
                                                    {initials(deposit.student?.name)}
                                                </div>
                                                <div className="min-w-0">
                                                    <Link
                                                        href={route('meals.students.show', deposit.student_id)}
                                                        className="font-semibold text-slate-900 hover:text-indigo-600"
                                                    >
                                                        {deposit.student?.name || 'Unknown'}
                                                    </Link>
                                                    {deposit.student?.roll && (
                                                        <div className="text-xs text-slate-400">
                                                            {deposit.student.roll}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className={`px-6 py-4 font-bold ${deposit.kind === 'subsidy' ? 'text-sky-600' : 'text-emerald-600'}`}>
                                            +{money(deposit.amount, false)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${deposit.kind === 'subsidy' ? 'border-sky-100 bg-sky-50 text-sky-700' : 'border-emerald-100 bg-emerald-50 text-emerald-700'}`}>
                                                {deposit.kind === 'subsidy' ? 'Subsidy' : deposit.kind === 'credit' ? 'Credit' : 'Personal'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {deposit.payment_method || 'Cash'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-500">
                                            {deposit.recorder?.name || '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-500">
                                            {new Date(deposit.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="max-w-xs px-6 py-4 text-xs text-slate-500">
                                            {deposit.notes || <span className="text-slate-300">—</span>}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan="7" className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">
                                            {hasFilters ? 'No deposits match these filters.' : 'No deposits recorded yet.'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-400">
                                            Deposits are what {t('members', 'members').toLowerCase()} pay into the shared fund.
                                        </p>
                                        {!hasFilters && (
                                            <Link href={route('meals.subsidies.index')} className="mt-3 inline-block text-xs font-semibold text-indigo-600 hover:text-indigo-800">
                                                Managing institutional subsidies? Open the Subsidies module →
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {deposits?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-6 py-4">
                        <p className="text-xs text-slate-500">
                            Showing <strong>{deposits.from}</strong>–<strong>{deposits.to}</strong> of{' '}
                            <strong>{deposits.total}</strong>
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {deposits.links.map((link, index) => (
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

            {/* Record deposit modal */}
            <Modal
                open={modalOpen}
                onClose={closeModal}
                title="Record Deposit"
                description="This creates a matching cash-in transaction in the ledger."
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
                            form="deposit-form"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && <Spinner className="h-4 w-4" />}
                            {processing ? 'Saving...' : 'Record Deposit'}
                        </button>
                    </>
                }
            >
                <form id="deposit-form" onSubmit={submit} className="space-y-4">
                    <Field
                        label="Student"
                        name="student_id"
                        type="select"
                        required
                        value={data.student_id}
                        error={errors.student_id}
                        options={studentOptions}
                        onChange={(event) => setData('student_id', event.target.value)}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Amount"
                            name="amount"
                            type="number"
                            required
                            step="0.01"
                            min="0.01"
                            value={data.amount}
                            error={errors.amount}
                            placeholder="e.g. 3000"
                            onChange={(event) => setData('amount', event.target.value)}
                        />

                        <Field
                            label="Payment Method"
                            name="payment_method"
                            type="select"
                            value={data.payment_method}
                            error={errors.payment_method}
                            options={[
                                { value: 'Cash', label: 'Cash' },
                                { value: 'bKash', label: 'bKash' },
                                { value: 'Nagad', label: 'Nagad' },
                                { value: 'Bank Transfer', label: 'Bank Transfer' },
                            ]}
                            onChange={(event) => setData('payment_method', event.target.value)}
                        />
                    </div>

                    <Field
                        label="Notes"
                        name="notes"
                        type="textarea"
                        value={data.notes}
                        error={errors.notes}
                        placeholder="Optional note about this payment..."
                    />
                </form>
            </Modal>
        </MealsLayout>
    );
}
