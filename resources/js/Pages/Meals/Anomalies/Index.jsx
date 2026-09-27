import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint } from '@/Components/Help/HelpHint';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * ANOMALY MONITOR.
 *
 * The review queue for automatically detected financial and operational problems:
 * duplicate deposits, meal spikes, large negative balances and unusual expenses.
 *
 * Each finding explains itself in plain language and carries a review workflow
 * (acknowledge / dismiss / resolve), so a false positive can be cleared once and
 * stays cleared - the scan re-runs without resurrecting it.
 */
export default function Index({ anomalies, kinds = [], filters = {}, summary = {}, institution = null }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();

    const [scanning, setScanning] = useState(false);
    const [reviewing, setReviewing] = useState(null);

    const rows = anomalies?.data || [];

    const runScan = () => {
        setScanning(true);
        router.post(route('meals.anomalies.scan'), {}, {
            preserveScroll: true,
            onFinish: () => setScanning(false),
        });
    };

    const applyFilter = (next) => {
        router.get(route('meals.anomalies.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const review = async (anomaly, status) => {
        const labels = {
            acknowledged: 'Acknowledge',
            dismissed: 'Dismiss',
            resolved: 'Resolve',
        };

        const ok = await confirm({
            title: `${labels[status]} this finding?`,
            message: status === 'dismissed'
                ? 'A dismissed finding will not reappear even if the scan runs again.'
                : 'This records your decision against the finding.',
            tone: status === 'dismissed' ? 'warning' : 'info',
            confirmLabel: labels[status],
        });

        if (!ok) return;

        setReviewing(anomaly.id);
        router.patch(route('meals.anomalies.review', anomaly.id), { status }, {
            preserveScroll: true,
            onFinish: () => setReviewing(null),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        {/* Title lives in the fixed top bar; this is the description. */}
                        <p className="text-xs font-medium text-slate-500">
                            Unusual deposits, meal patterns, balances and expenses flagged for review.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={runScan}
                        disabled={scanning}
                        data-testid="anomaly-scan-button"
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {scanning && <Spinner className="h-4 w-4" />}
                        {scanning ? 'Scanning...' : 'Scan now'}
                    </button>
                </div>
            }
        >
            <Head title="Anomaly Monitor" />

            <div className="space-y-5">
                <PageHint title="What this flags, and why">
                    The scanner compares each record against the member's own history and the
                    category's own norm - so a big eater is not flagged for eating, but a sudden spike
                    is. Re-scanning is safe: findings you dismiss stay dismissed.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="anomaly-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* Summary pillars */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Open', value: summary.open ?? 0, tone: 'text-slate-900' },
                        { label: 'Critical', value: summary.critical ?? 0, tone: 'text-rose-600' },
                        { label: 'Acknowledged', value: summary.acknowledged ?? 0, tone: 'text-sky-600' },
                        { label: 'Dismissed', value: summary.dismissed ?? 0, tone: 'text-slate-400' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{card.label}</p>
                            <p className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</p>
                        </div>
                    ))}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <select
                        value={filters.status || 'open'}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        data-testid="anomaly-status-filter"
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="open">Open</option>
                        <option value="acknowledged">Acknowledged</option>
                        <option value="dismissed">Dismissed</option>
                        <option value="resolved">Resolved</option>
                        <option value="all">All</option>
                    </select>

                    <select
                        value={filters.kind || ''}
                        onChange={(e) => applyFilter({ kind: e.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All types</option>
                        {kinds.map((kind) => (
                            <option key={kind.value} value={kind.value}>{kind.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.severity || ''}
                        onChange={(e) => applyFilter({ severity: e.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All severities</option>
                        <option value="critical">Critical</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info</option>
                    </select>
                </div>

                {/* Findings */}
                <div className="space-y-3">
                    {rows.length > 0 ? rows.map((anomaly) => (
                        <FindingCard
                            key={anomaly.id}
                            anomaly={anomaly}
                            reviewing={reviewing === anomaly.id}
                            onReview={review}
                        />
                    )) : (
                        <div className="rounded-2xl border border-slate-200 bg-white py-14 text-center shadow-sm">
                            <p className="text-sm font-semibold text-slate-600">Nothing to review here.</p>
                            <p className="mt-1 text-xs text-slate-400">
                                Run a scan to check the latest activity, or switch the filter above.
                            </p>
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {anomalies?.links?.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {anomalies.links.map((link, index) => (
                            <Link
                                key={index}
                                href={link.url || '#'}
                                preserveScroll
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${link.active
                                    ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                                    : link.url
                                        ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                        : 'border-slate-100 bg-white text-slate-300 pointer-events-none'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

const TONES = {
    rose: { wrap: 'border-rose-200 bg-rose-50/50', chip: 'border-rose-200 bg-rose-100 text-rose-700' },
    amber: { wrap: 'border-amber-200 bg-amber-50/50', chip: 'border-amber-200 bg-amber-100 text-amber-700' },
    slate: { wrap: 'border-slate-200 bg-white', chip: 'border-slate-200 bg-slate-100 text-slate-600' },
};

function FindingCard({ anomaly, reviewing, onReview }) {
    const tone = TONES[anomaly.severity_tone] || TONES.slate;

    return (
        <div
            data-testid="anomaly-card"
            className={`rounded-2xl border p-5 shadow-xs ${tone.wrap}`}
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${tone.chip}`}>
                            {anomaly.severity}
                        </span>
                        <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-[10px] font-semibold text-slate-600">
                            {anomaly.kind_label}
                        </span>
                        {anomaly.student && (
                            <span className="text-[11px] font-medium text-slate-500">
                                {anomaly.student.name} ({anomaly.student.roll || 'no roll'})
                            </span>
                        )}
                        {anomaly.detected_for && (
                            <span className="text-[11px] text-slate-400">{anomaly.detected_for}</span>
                        )}
                    </div>

                    <h4 className="mt-2 text-sm font-bold text-slate-900">{anomaly.title}</h4>
                    <p className="mt-1 text-xs leading-relaxed text-slate-600">{anomaly.detail}</p>

                    {anomaly.amount !== null && anomaly.amount !== undefined && (
                        <p className="mt-1.5 text-xs font-semibold text-slate-700">
                            Amount: {anomaly.amount}
                        </p>
                    )}

                    {anomaly.review_note && (
                        <p className="mt-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[11px] text-slate-500">
                            <strong className="font-semibold">Review note:</strong> {anomaly.review_note}
                            {anomaly.reviewed_by && <span className="text-slate-400"> · {anomaly.reviewed_by}</span>}
                        </p>
                    )}
                </div>

                <div className="flex flex-shrink-0 flex-wrap gap-2">
                    {anomaly.status === 'open' || anomaly.status === 'acknowledged' ? (
                        <>
                            <button
                                type="button"
                                onClick={() => onReview(anomaly, 'resolved')}
                                disabled={reviewing}
                                data-testid="anomaly-resolve"
                                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Resolve
                            </button>
                            <button
                                type="button"
                                onClick={() => onReview(anomaly, 'dismissed')}
                                disabled={reviewing}
                                data-testid="anomaly-dismiss"
                                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-50"
                            >
                                Dismiss
                            </button>
                        </>
                    ) : (
                        <span className="self-center text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                            {anomaly.status}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}