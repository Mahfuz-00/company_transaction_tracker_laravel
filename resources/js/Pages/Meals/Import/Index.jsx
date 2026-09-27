import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import { Spinner } from '@/Components/UI/Loading';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/**
 * BULK CSV / EXCEL IMPORT (with dry-run validation).
 *
 * The screen is built around ONE idea: the operator must SEE what a file will do
 * before it does it. So the flow is two deliberate steps:
 *
 *   1. "Validate file (dry run)"  -> POST analyse -> writes NOTHING, returns a
 *      per-row report rendered below.
 *   2. "Import N rows"            -> POST commit -> only then is anything written.
 *
 * The commit button is disabled until a dry run has actually passed, so it is
 * impossible to write by accident.
 */
export default function Index({ datasets = [], institution = null }) {
    const { flash } = usePage().props;

    const preview = flash?.importPreview || null;

    const [dataset, setDataset] = useState(datasets[0]?.value || 'members');
    const [file, setFile] = useState(null);

    // Two separate forms: the dry run and the commit upload the SAME file, but
    // keeping them apart makes the "nothing is written yet" contract obvious.
    const analyse = useForm({ dataset, file: null });
    const commit = useForm({ dataset, file: null });

    const activeDataset = datasets.find((d) => d.value === dataset) || datasets[0];

    const runAnalysis = (e) => {
        e.preventDefault();
        if (!file) return;

        analyse.setData({ dataset, file });
        analyse.post(route('meals.import.analyse'), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const runCommit = () => {
        if (!file) return;

        commit.setData({ dataset, file });
        commit.post(route('meals.import.commit'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setFile(null);
                const input = document.getElementById('import-file');
                if (input) input.value = '';
            },
        });
    };

    const canCommit = Boolean(preview) && preview.invalid === 0 && preview.would_create > 0 && !commit.processing;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                        Bulk Import
                    </h2>
                    <p className="mt-0.5 text-xs font-medium text-slate-500">
                        Bring in members, opening balances and historical meals from a spreadsheet.
                    </p>
                </div>
            }
        >
            <Head title="Bulk Import" />

            <div className="space-y-6">
                {/* The persistent explanation of the two-phase contract. */}
                <PageHint title="Nothing is written until you approve it">
                    Upload a CSV, run the <strong className="font-semibold text-slate-700">dry run</strong>, and
                    review the per-row report below. Only when you are happy do you press Import - and the whole
                    file lands in a single transaction, so it can never half-import.
                </PageHint>

                {/* Result banners */}
                {flash?.success && (
                    <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* ---- Upload form ---- */}
                    <form onSubmit={runAnalysis} className="lg:col-span-2">
                        <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-slate-700">
                                    What are you importing?
                                </label>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {datasets.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            data-testid={`import-dataset-${option.value}`}
                                            onClick={() => setDataset(option.value)}
                                            className={`rounded-xl border p-3 text-left transition-all ${dataset === option.value
                                                ? 'border-[var(--accent)] bg-[var(--accent-soft)] ring-1 ring-[var(--accent-ring)]'
                                                : 'border-slate-200 bg-white hover:border-slate-300'}`}
                                        >
                                            <span className="block text-sm font-bold text-slate-800">{option.label}</span>
                                            <span className="mt-0.5 block text-[11px] leading-snug text-slate-500">
                                                {option.description}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Required columns for the chosen dataset, so the
                                operator knows the expected shape before uploading. */}
                            {activeDataset && (
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        Expected columns
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {activeDataset.columns.map((column) => (
                                            <span
                                                key={column.key}
                                                className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${column.required
                                                    ? 'border-slate-300 bg-white text-slate-700'
                                                    : 'border-slate-200 bg-white text-slate-400'}`}
                                            >
                                                {column.label}
                                                {column.required && <span className="text-rose-500">*</span>}
                                            </span>
                                        ))}
                                    </div>
                                    <a
                                        href={route('meals.import.template', dataset)}
                                        data-testid="import-template-link"
                                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--accent)] hover:underline"
                                    >
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        Download a template with these headers
                                    </a>
                                </div>
                            )}

                            <div>
                                <label htmlFor="import-file" className="mb-1.5 block text-sm font-semibold text-slate-700">
                                    CSV file
                                </label>
                                <input
                                    id="import-file"
                                    data-testid="import-file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    onChange={(e) => setFile(e.target.files?.[0] || null)}
                                    className="block w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:opacity-90"
                                />
                                {analyse.errors.file && (
                                    <p className="mt-1.5 text-xs font-medium text-rose-600">{analyse.errors.file}</p>
                                )}
                                <p className="mt-1.5 text-[11px] text-slate-400">
                                    In Excel choose <strong>File → Save As → CSV UTF-8</strong>. The first row must be the headers.
                                </p>
                            </div>

                            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                                <button
                                    type="submit"
                                    disabled={!file || analyse.processing}
                                    data-testid="import-analyse-button"
                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 disabled:opacity-50"
                                >
                                    {analyse.processing && <Spinner className="h-4 w-4" />}
                                    {analyse.processing ? 'Validating...' : 'Validate file (dry run)'}
                                </button>

                                <button
                                    type="button"
                                    onClick={runCommit}
                                    disabled={!canCommit}
                                    data-testid="import-commit-button"
                                    title={canCommit ? undefined : 'Run a clean dry run first'}
                                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--accent)] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {commit.processing && <Spinner className="h-4 w-4" />}
                                    {commit.processing
                                        ? 'Importing...'
                                        : preview
                                            ? `Import ${preview.would_create} row(s)`
                                            : 'Import'}
                                </button>
                            </div>
                        </div>
                    </form>

                    {/* ---- How it works ---- */}
                    <div className="space-y-6">
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-xs text-slate-500 shadow-xs">
                            <p className="font-semibold text-slate-700">How the dry run works</p>
                            <ul className="mt-2 list-disc space-y-1 pl-4">
                                <li>Every row is validated and classified: <strong>create</strong>, <strong>skip</strong> or <strong>error</strong>.</li>
                                <li>Rows that already exist are <strong>skipped</strong>, never duplicated - so re-running a file is safe.</li>
                                <li>Nothing touches the database until you press Import.</li>
                                <li>The import writes in a single transaction: all of it, or none of it.</li>
                            </ul>
                        </div>

                        {institution && (
                            <InfoHint tone="indigo">
                                Everything you import lands in <strong>{institution.name}</strong>.
                            </InfoHint>
                        )}
                    </div>
                </div>

                {/* ---- The dry-run report ---- */}
                {preview && (
                    <div
                        data-testid="import-preview"
                        className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs"
                    >
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 className="text-sm font-bold text-slate-900">
                                        Dry-run preview: {preview.dataset_label}
                                    </h3>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        {preview.filename} · {preview.total} row(s) read
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    <Stat label="Would create" value={preview.would_create} tone="emerald" testid="preview-would-create" />
                                    <Stat label="Already exist" value={preview.would_skip} tone="slate" />
                                    <Stat label="Errors" value={preview.invalid} tone={preview.invalid > 0 ? 'rose' : 'slate'} testid="preview-invalid" />
                                </div>
                            </div>
                        </div>

                        {/* Missing-column errors block everything, so they come first. */}
                        {preview.header_errors?.length > 0 && (
                            <div className="border-b border-rose-100 bg-rose-50 px-6 py-4">
                                <p className="text-xs font-bold text-rose-800">The file cannot be imported yet:</p>
                                <ul className="mt-1.5 list-disc space-y-1 pl-4 text-xs text-rose-700">
                                    {preview.header_errors.map((message, index) => (
                                        <li key={index}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="max-h-[28rem] overflow-y-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="sticky top-0 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    <tr>
                                        <th className="px-6 py-3">Line</th>
                                        <th className="px-6 py-3">What would happen</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {preview.rows.map((row) => (
                                        <tr key={row.line} className={row.status === 'error' ? 'bg-rose-50/40' : ''}>
                                            <td className="px-6 py-3 align-top font-mono text-xs text-slate-400">
                                                {row.line}
                                            </td>
                                            <td className="px-6 py-3 align-top">
                                                <div className="flex items-start gap-2">
                                                    <StatusChip status={row.status} />
                                                    <div className="min-w-0">
                                                        <p className="text-xs text-slate-700">{row.summary}</p>
                                                        {row.messages?.length > 0 && (
                                                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-[11px] text-rose-600">
                                                                {row.messages.map((message, index) => (
                                                                    <li key={index}>{message}</li>
                                                                ))}
                                                            </ul>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {preview.truncated && (
                                <p className="border-t border-slate-100 px-6 py-3 text-[11px] italic text-slate-400">
                                    Only the first rows are shown. The import still processes the whole file.
                                </p>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

/** A compact numeric pillar in the preview header. */
function Stat({ label, value, tone = 'slate', testid = null }) {
    const tones = {
        slate: 'text-slate-700 border-slate-200 bg-white',
        emerald: 'text-emerald-700 border-emerald-200 bg-emerald-50',
        rose: 'text-rose-700 border-rose-200 bg-rose-50',
    };

    return (
        <div className={`rounded-lg border px-3 py-1.5 ${tones[tone] || tones.slate}`}>
            <span className="text-[10px] font-bold uppercase tracking-wider opacity-70">{label}</span>
            <span data-testid={testid} className="ml-2 text-sm font-extrabold">{value}</span>
        </div>
    );
}

/** create / skip / error pill. */
function StatusChip({ status }) {
    const tones = {
        create: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        skip: 'border-slate-200 bg-slate-100 text-slate-500',
        error: 'border-rose-200 bg-rose-50 text-rose-700',
    };

    const labels = { create: 'Create', skip: 'Skip', error: 'Error' };

    return (
        <span className={`inline-flex flex-shrink-0 items-center rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase ${tones[status] || tones.skip}`}>
            {labels[status] || status}
        </span>
    );
}