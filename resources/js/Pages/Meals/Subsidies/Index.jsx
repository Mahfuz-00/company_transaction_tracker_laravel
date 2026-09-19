import React, { useMemo, useState } from 'react';
import MealsLayout from '@/Layouts/MealsLayout';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import useMoney from '@/Utils/useMoney';
import useTerminology from '@/Utils/useTerminology';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const EMPTY_FORM = {
    source: '',
    source_label: '',
    amount: '',
    percentage: '',
    apply_mode: 'pool',
    department_id: '',
    student_id: '',
    period_month: '',
    notes: '',
};

function Flash({ success, error }) {
    if (!success && !error) return null;
    const isError = Boolean(error);

    return (
        <div
            role="status"
            className={`flex items-center gap-2 rounded-lg border p-3 text-sm font-medium ${isError ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}
        >
            {error || success}
        </div>
    );
}

function StatCard({ label, value, tone = 'text-slate-900', hint }) {
    return (
        <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</div>
            <div className={`mt-1 text-xl font-bold ${tone}`}>{value}</div>
            {hint && <div className="mt-0.5 text-[11px] text-slate-400">{hint}</div>}
        </div>
    );
}

export default function Index({ subsidies, sources, applyModes, departments, students, totals, sourceTotals, months, month, filters }) {
    const { can } = useCan();
    // Terminology-aware nouns, so labels follow the institution type.
    const { tTitle } = useTerminology();
    const { flash } = usePage().props;
    const money = useMoney();
    const { confirm } = useFeedback();
    const canManage = can('subsidies.manage');
    const canExport = can('exports.download');

    const [modalOpen, setModalOpen] = useState(false);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ ...EMPTY_FORM });

    const rows = subsidies?.data || [];

    const modeOptions = useMemo(() => applyModes || [], [applyModes]);

    // Sources now come from settings, so the form follows whatever the admin
    // has configured for this institution.
    const sourceOptions = useMemo(
        () => (sources || []).map((s) => ({ value: s.value, label: s.label })),
        [sources]
    );

    const openModal = () => {
        clearErrors();
        reset();
        // Default the funding source to the first configured one, and the
        // period to the month currently in view.
        setData({
            ...EMPTY_FORM,
            source: sourceOptions[0]?.value || '',
            period_month: month || '',
        });
        setModalOpen(true);
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('meals.subsidies.store'), {
            preserveScroll: true,
            onSuccess: () => setModalOpen(false),
        });
    };

    const reverse = async (subsidy) => {
        const ok = await confirm({
            title: 'Reverse this subsidy?',
            message: 'This posts a matching cash-out to the ledger and removes any per-member allocations it created.',
            tone: 'danger',
            confirmLabel: 'Reverse subsidy',
        });
        if (!ok) return;

        router.patch(route('meals.subsidies.reverse', subsidy.id), {}, { preserveScroll: true });
    };

    const applyFilters = (next) => {
        router.get(route('meals.subsidies.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    // Selecting a source pre-fills its configured default percentage.
    const onSourceChange = (value) => {
        const chosen = (sources || []).find((s) => s.value === value);
        setData({
            ...data,
            source: value,
            percentage: chosen?.percentage ? String(chosen.percentage) : data.percentage,
        });
    };

    const departmentOptions = useMemo(
        () => [
            { value: '', label: '— Whole institution —' },
            ...(departments || []).map((d) => ({ value: String(d.id), label: d.name })),
        ],
        [departments]
    );

    const memberOptions = useMemo(
        () => [
            { value: '', label: '— All members —' },
            ...(students || []).map((s) => ({
                value: String(s.id),
                label: s.roll ? `${s.name} (${s.roll})` : s.name,
            })),
        ],
        [students]
    );

    return (
        <MealsLayout
            title="Institutional Subsidies"
            description="Funds injected by the university, company, or college administration - tracked separately from member deposits."
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {canExport && (
                        <a
                            href={`${route('meals.deposits.export')}?kind=subsidy&format=excel`}
                            className="rounded-lg border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Export allocations
                        </a>
                    )}
                    {canManage && (
                        <button
                            type="button"
                            onClick={openModal}
                            className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Record Subsidy
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Subsidies" />

            <Flash success={flash?.success} error={flash?.error} />

            {/* Totals for the SELECTED MONTH only - subsidies are month-scoped. */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total This Month" value={money(totals?.all ?? 0, false)} tone="text-[var(--accent)]" hint="Active subsidies in the selected month" />
                <StatCard label="Into Common Pool" value={money(totals?.pool ?? 0, false)} tone="text-emerald-600" hint="Shared by everyone" />
                <StatCard label="Split Per Member" value={money(totals?.per_member ?? 0, false)} tone="text-sky-600" hint="Distributed across the roster" />
                <StatCard label="Reserve Credit" value={money(totals?.credit_behind ?? 0, false)} tone="text-amber-600" hint="Applied only after member funds run out" />
            </div>

            {/* Funding sources for the month: target share vs real share. */}
            {(sourceTotals || []).length > 0 && (
                <div className="rounded-xl border-slate-200 bg-white p-4 shadow-sm">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                        Funding Sources This Month
                    </h4>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {sourceTotals.map((s) => (
                            <div key={s.label} className="rounded-lg border-slate-100 bg-slate-50/60 p-3">
                                <div className="truncate text-xs font-semibold text-slate-700">{s.label}</div>
                                <div className="mt-1 text-base font-bold text-slate-800">{money(s.total, false)}</div>
                                <div className="mt-0.5 text-[11px] text-slate-400">
                                    Target share: <strong className="font-semibold">{s.percentage ?? 0}%</strong>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border-slate-200 bg-white shadow-sm">
                <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                    {/* Month filter - replaces any date range. */}
                    <select
                        value={month || ''}
                        onChange={(e) => applyFilters({ month: e.target.value })}
                        aria-label="Report month"
                        className="rounded-lg border-slate-300 text-sm font-semibold text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent-ring)]"
                    >
                        {(months || []).map((m) => (
                            <option key={m.value} value={m.value}>{m.label}{m.current ? ' (current)' : ''}</option>
                        ))}
                    </select>

                    <select value={filters?.source || ''} onChange={(e) => applyFilters({ source: e.target.value })} className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All sources</option>
                        {sourceOptions.map((s) => (<option key={s.value} value={s.value}>{s.label}</option>))}
                    </select>

                    <select value={filters?.status || ''} onChange={(e) => applyFilters({ status: e.target.value })} className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="reversed">Reversed</option>
                    </select>

                    {(filters?.source || filters?.status || filters?.month) && (
                        <button type="button" onClick={() => router.get(route('meals.subsidies.index'), {}, { replace: true })} className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 sm:ml-auto">
                            Clear filters
                        </button>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-180 border-collapse text-left">
                        <thead>
                            <tr className="border-b border-slate-100 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-400">
                                <th className="px-4 py-3 sm:px-6">Source</th>
                                <th className="px-4 py-3 sm:px-6">Amount</th>
                                <th className="px-4 py-3 sm:px-6">Share</th>
                                <th className="px-4 py-3 sm:px-6">Applies To</th>
                                <th className="px-4 py-3 sm:px-6">Mode</th>
                                <th className="px-4 py-3 sm:px-6">Recorded By</th>
                                <th className="px-4 py-3 sm:px-6">Date</th>
                                <th className="px-4 py-3 text-right sm:px-6">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-sm">
                            {rows.length > 0 ? rows.map((s) => (
                                <tr key={s.id} className={`transition-colors hover:bg-slate-50/60 ${s.status === 'reversed' ? 'opacity-50' : ''}`}>
                                    <td className="px-4 py-4 font-semibold text-slate-800 sm:px-6">
                                        {s.source_label}
                                        {s.status === 'reversed' && (
                                            <span className="ml-2 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-600">REVERSED</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-4 font-bold text-[var(--accent)] sm:px-6">+{money(s.amount, false)}</td>
                                    <td className="px-4 py-4 text-xs text-slate-600 sm:px-6">
                                        {s.percentage !== null && s.percentage !== undefined
                                            ? `${s.percentage}%`
                                            : <span className="text-slate-300">—</span>}
                                    </td>
                                    <td className="px-4 py-4 text-xs text-slate-500 sm:px-6">
                                        {s.department?.name || s.student?.name || 'Whole institution'}
                                    </td>
                                    <td className="px-4 py-4 text-xs text-slate-600 sm:px-6">{s.apply_mode_label}</td>
                                    <td className="px-4 py-4 text-xs text-slate-500 sm:px-6">{s.recorder?.name || '—'}</td>
                                    <td className="px-4 py-4 text-xs text-slate-500 sm:px-6">
                                        {s.period_month || new Date(s.created_at).toLocaleDateString()}
                                    </td>
                                    <td className="px-4 py-4 text-right sm:px-6">
                                        {canManage && s.status === 'active' && (
                                            <button type="button" onClick={() => reverse(s)} className="text-xs font-semibold text-rose-500 transition-colors hover:text-rose-700">
                                                Reverse
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan="7" className="py-14 text-center">
                                        <p className="text-sm font-semibold text-slate-600">No subsidies recorded yet.</p>
                                        <p className="mt-1 text-xs text-slate-400">Subsidies are funds injected by an authority, separate from member deposits.</p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {subsidies?.links?.length > 3 && (
                    <div className="flex items-center justify-between gap-4 border-t border-slate-100 px-4 py-4 sm:px-6">
                        <p className="text-xs text-slate-500">Showing <strong>{subsidies.from}</strong>–<strong>{subsidies.to}</strong> of <strong>{subsidies.total}</strong></p>
                        <div className="flex flex-wrap gap-1">
                            {subsidies.links.map((link, index) => (
                                <Link key={index} href={link.url || '#'} preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : link.url ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' : 'pointer-events-none border-slate-100 bg-white text-slate-300'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }} />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title="Record Institutional Subsidy"
                description="Money injected by an authority. It is tracked separately from personal member deposits."
                footer={
                    <>
                        <button type="button" onClick={() => setModalOpen(false)} className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Cancel</button>
                        <button type="submit" form="subsidy-form" disabled={processing} className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                            {processing && <Spinner className="h-4 w-4" />}
                            {processing ? 'Saving...' : 'Record Subsidy'}
                        </button>
                    </>
                }
            >
                <form id="subsidy-form" onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Funding Source"
                            name="source"
                            type="select"
                            required
                            value={data.source}
                            error={errors.source}
                            hint="Managed under Settings → Subsidy Sources."
                            options={sourceOptions}
                            onChange={(e) => onSourceChange(e.target.value)}
                        />
                        <Field label="Amount" name="amount" type="number" required step="0.01" min="0.01" value={data.amount} error={errors.amount} placeholder="e.g. 50000" onChange={(e) => setData('amount', e.target.value)} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Funding Percentage"
                            name="percentage"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            value={data.percentage}
                            error={errors.percentage}
                            placeholder="e.g. 20"
                            hint="Share of the pool this funder covers."
                            onChange={(e) => setData('percentage', e.target.value)}
                        />
                        <Field
                            label="Period (month)"
                            name="period_month"
                            type="month"
                            required
                            value={data.period_month}
                            error={errors.period_month}
                            hint="Subsidies are tracked strictly by month."
                            onChange={(e) => setData('period_month', e.target.value)}
                        />
                    </div>

                    <Field
                        label="How it is applied"
                        name="apply_mode"
                        type="select"
                        required
                        value={data.apply_mode}
                        error={errors.apply_mode}
                        hint="Reserve credit is only used after a member's own deposits are exhausted."
                        options={modeOptions}
                        onChange={(e) => setData('apply_mode', e.target.value)}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Scope (optional)" name="department_id" type="select" value={data.department_id} error={errors.department_id} options={departmentOptions} onChange={(e) => setData('department_id', e.target.value)} />
                        <Field label={`${tTitle('member', 'Member')} (optional)`} name="student_id" type="select" value={data.student_id} error={errors.student_id} options={memberOptions} onChange={(e) => setData('student_id', e.target.value)} />
                    </div>

                    <Field label="Notes" name="notes" type="textarea" value={data.notes} error={errors.notes} placeholder="Reference number, grant details..." onChange={(e) => setData('notes', e.target.value)} />
                </form>
            </Modal>
        </MealsLayout>
    );
}
