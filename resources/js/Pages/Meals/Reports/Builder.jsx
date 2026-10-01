import React, { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * DYNAMIC REPORTS BUILDER.
 *
 * Compose a report (dataset + measure + grouping + filters), RUN it, and SAVE it
 * for reuse. Every option is drawn from the server's whitelist (`catalogue`), so
 * the user composes a query by SELECTION rather than by typing SQL - which is what
 * makes a user-defined report safe.
 */
export default function Builder({ catalogue = [], reports = [], options = {} }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();

    const firstDataset = catalogue[0];

    const [definition, setDefinition] = useState({
        dataset: firstDataset?.value || 'members',
        metric: 'sum',
        measure: firstDataset?.measures?.[0]?.value || 'records',
        group_by: 'none',
        from: '',
        to: '',
        limit: 50,
    });

    const [filters, setFilters] = useState({});
    const [saveOpen, setSaveOpen] = useState(false);

    const result = flash?.reportResult || null;

    const activeDataset = useMemo(
        () => catalogue.find((d) => d.value === definition.dataset) || firstDataset,
        [catalogue, definition.dataset, firstDataset]
    );

    const save = useForm({ name: '', description: '', is_shared: false, definition: {} });

    /** Change dataset: reset the measure/grouping to that dataset's first options. */
    const setDataset = (value) => {
        const dataset = catalogue.find((d) => d.value === value);

        setDefinition((prev) => ({
            ...prev,
            dataset: value,
            measure: dataset?.measures?.[0]?.value || 'records',
            group_by: 'none',
        }));

        setFilters({});
    };

    const run = () => {
        router.post(route('meals.report-builder.run'), {
            definition: { ...definition, filters },
        }, { preserveScroll: true });
    };

    const submitSave = (e) => {
        e.preventDefault();
        save.setData('definition', { ...definition, filters });
        save.post(route('meals.report-builder.store'), {
            preserveScroll: true,
            onSuccess: () => { setSaveOpen(false); save.reset(); },
        });
    };

    const runSaved = (report) => {
        router.get(route('meals.report-builder.run-saved', report.id), {}, { preserveScroll: true });
    };

    const deleteSaved = async (report) => {
        const ok = await confirm({
            title: `Delete "${report.name}"?`,
            message: 'The saved definition will be removed. This cannot be undone.',
            tone: 'danger',
            confirmLabel: 'Delete report',
        });
        if (!ok) return;

        router.delete(route('meals.report-builder.destroy', report.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <p className="text-xs font-medium text-slate-500">
                        Build the report you actually need, then save it for next time.
                    </p>
                </div>
            }
        >
            <Head title="Report Builder" />

            <div className="space-y-6">
                <PageHint title="Compose, run, save">
                    Pick what to look at, what to measure and how to slice it - then run it. Every option comes
                    from a vetted list, so a saved report can never ask for something the platform does not
                    explicitly support.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="builder-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* ---- Builder ---- */}
                    <div className="lg:col-span-2">
                        <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <h3 className="text-base font-bold text-slate-900">Build a report</h3>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="What to look at"
                                    name="dataset"
                                    type="select"
                                    required
                                    value={definition.dataset}
                                    options={catalogue.map((d) => ({ value: d.value, label: d.label }))}
                                    onChange={(e) => setDataset(e.target.value)}
                                />
                                <Field
                                    label="What to measure"
                                    name="measure"
                                    type="select"
                                    required
                                    value={definition.measure}
                                    options={activeDataset?.measures || []}
                                    onChange={(e) => setDefinition((p) => ({ ...p, measure: e.target.value }))}
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field
                                    label="How to group"
                                    name="group_by"
                                    type="select"
                                    required
                                    value={definition.group_by}
                                    options={activeDataset?.groupings || []}
                                    onChange={(e) => setDefinition((p) => ({ ...p, group_by: e.target.value }))}
                                />
                                <Field
                                    label="From"
                                    name="from"
                                    type="date"
                                    value={definition.from}
                                    onChange={(e) => setDefinition((p) => ({ ...p, from: e.target.value }))}
                                />
                                <Field
                                    label="To"
                                    name="to"
                                    type="date"
                                    value={definition.to}
                                    onChange={(e) => setDefinition((p) => ({ ...p, to: e.target.value }))}
                                />
                            </div>

                            {/* ---- Filters, only the ones this dataset supports ---- */}
                            {activeDataset?.filters?.length > 0 && (
                                <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        Narrow it down
                                    </p>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {activeDataset.filters.includes('member') && (
                                            <Field
                                                label="Member"
                                                name="filter_member"
                                                type="select"
                                                value={filters.member || ''}
                                                options={[{ value: '', label: 'All members' }, ...(options.members || [])]}
                                                onChange={(e) => setFilters((f) => ({ ...f, member: e.target.value }))}
                                            />
                                        )}
                                        {activeDataset.filters.includes('department') && (
                                            <Field
                                                label="Department"
                                                name="filter_department"
                                                type="select"
                                                value={filters.department || ''}
                                                options={[{ value: '', label: 'All departments' }, ...(options.departments || [])]}
                                                onChange={(e) => setFilters((f) => ({ ...f, department: e.target.value }))}
                                            />
                                        )}
                                        {activeDataset.filters.includes('status') && (
                                            <Field
                                                label="Status"
                                                name="filter_status"
                                                type="select"
                                                value={filters.status || ''}
                                                options={[
                                                    { value: '', label: 'Any status' },
                                                    { value: 'active', label: 'Active' },
                                                    { value: 'inactive', label: 'Inactive' },
                                                ]}
                                                onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))}
                                            />
                                        )}
                                        {activeDataset.filters.includes('method') && (
                                            <Field
                                                label="Payment method"
                                                name="filter_method"
                                                type="select"
                                                value={filters.method || ''}
                                                options={[{ value: '', label: 'Any method' }, ...(options.methods || [])]}
                                                onChange={(e) => setFilters((f) => ({ ...f, method: e.target.value }))}
                                            />
                                        )}
                                        {activeDataset.filters.includes('category') && (
                                            <Field
                                                label="Category"
                                                name="filter_category"
                                                type="select"
                                                value={filters.category || ''}
                                                options={[{ value: '', label: 'All categories' }, ...(options.categories || [])]}
                                                onChange={(e) => setFilters((f) => ({ ...f, category: e.target.value }))}
                                            />
                                        )}
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setSaveOpen(true)}
                                    data-testid="builder-save-open"
                                    className="rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                                >
                                    Save this report
                                </button>
                                <button
                                    type="button"
                                    onClick={run}
                                    data-testid="builder-run"
                                    className="rounded-xl bg-[var(--accent)] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90"
                                >
                                    Run report
                                </button>
                            </div>
                        </div>

                        {/* ---- Result ---- */}
                        {result && (
                            <div data-testid="builder-result" className="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                                <div className="flex flex-col gap-2 border-b border-slate-100 bg-slate-50/70 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900">
                                            {result.report_name || 'Report result'}
                                        </h3>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {result.columns?.label} · {result.columns?.value}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <form method="POST" action={route('meals.report-builder.export')}>
                                            <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content || ''} />
                                            <input type="hidden" name="definition" value={JSON.stringify({ ...definition, filters })} />
                                            <button
                                                type="submit"
                                                data-testid="builder-export-csv"
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                            >
                                                Export CSV
                                            </button>
                                        </form>
                                        <p className="text-lg font-extrabold text-slate-900">
                                            Total: {result.total}
                                        </p>
                                    </div>
                                </div>

                                <div className="max-h-[30rem] overflow-y-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="sticky top-0 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                            <tr>
                                                <th className="px-6 py-3">{result.labels?.group || 'Group'}</th>
                                                <th className="px-6 py-3 text-right">{result.labels?.value || 'Value'}</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {result.rows?.length > 0 ? result.rows.map((row, index) => (
                                                <tr key={index} data-testid="builder-row">
                                                    <td className="px-6 py-2.5 text-xs text-slate-700">{row.label}</td>
                                                    <td className="px-6 py-2.5 text-right text-xs font-bold text-slate-900">
                                                        {row.value}
                                                    </td>
                                                </tr>
                                            )) : (
                                                <tr>
                                                    <td colSpan={2} className="px-6 py-10 text-center text-xs text-slate-400">
                                                        No data matched this report.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* ---- Saved reports ---- */}
                    <div className="space-y-4">
                        <h3 className="text-sm font-bold text-slate-900">Saved reports</h3>

                        {reports.length > 0 ? reports.map((report) => (
                            <div
                                key={report.id}
                                data-testid="saved-report-card"
                                className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-bold text-slate-800">{report.name}</p>
                                        {report.description && (
                                            <p className="mt-0.5 text-[11px] text-slate-500">{report.description}</p>
                                        )}
                                        <p className="mt-1 text-[10px] text-slate-400">
                                            {report.owner} · run {report.run_count} time(s)
                                            {report.is_shared && ' · shared'}
                                        </p>
                                    </div>
                                </div>

                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => runSaved(report)}
                                        data-testid="saved-report-run"
                                        className="rounded-lg bg-slate-900 px-3 py-1.5 text-[11px] font-semibold text-white transition-opacity hover:opacity-90"
                                    >
                                        Run
                                    </button>
                                    {report.can_edit && (
                                        <button
                                            type="button"
                                            onClick={() => deleteSaved(report)}
                                            data-testid="saved-report-delete"
                                            className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-[11px] font-semibold text-rose-700 transition-colors hover:bg-rose-100"
                                        >
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </div>
                        )) : (
                            <div className="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-xs">
                                <p className="text-xs text-slate-400">
                                    No saved reports yet. Build one and save it for next time.
                                </p>
                            </div>
                        )}

                        <InfoHint tone="indigo">
                            Share a report to make it visible to your whole workspace. A private report is
                            visible only to you.
                        </InfoHint>
                    </div>
                </div>
            </div>

            {/* ---- Save dialog ---- */}
            {saveOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm">
                    <form onSubmit={submitSave} className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <h3 className="text-base font-bold text-slate-900">Save this report</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Give it a name so you (and your team) can run it again.
                        </p>

                        <div className="mt-4 space-y-4">
                            <Field
                                label="Report name"
                                name="name"
                                required
                                value={save.data.name}
                                error={save.errors.name}
                                placeholder="e.g. Monthly deposits by member"
                                onChange={(e) => save.setData('name', e.target.value)}
                            />
                            <Field
                                label="Description"
                                name="description"
                                value={save.data.description}
                                error={save.errors.description}
                                placeholder="What this report shows"
                                onChange={(e) => save.setData('description', e.target.value)}
                            />
                            <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={save.data.is_shared}
                                    onChange={(e) => save.setData('is_shared', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-[var(--accent)]"
                                />
                                Share with my workspace
                            </label>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => setSaveOpen(false)}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={save.processing}
                                data-testid="builder-save-submit"
                                className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                            >
                                {save.processing && <Spinner className="h-4 w-4" />}
                                {save.processing ? 'Saving...' : 'Save report'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}