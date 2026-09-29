import React, { useMemo, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, router } from '@inertiajs/react';

/**
 * SOFTWARE SUPER ADMIN — THE SUPPORT ASSISTANT'S ESCALATION QUEUE.
 *
 * THIS SCREEN IS WHERE THE ASSISTANT LEARNS
 * -----------------------------------------
 * Every question the assistant could not answer, and every answer a user flagged
 * as wrong, lands here. Answering one calls
 * `AssistantEscalation::resolveWith()`, which writes the reply into the corpus as
 * a new knowledge row - so the SAME question is answered autonomously from then
 * on, with no human involvement.
 *
 * That is why the primary action is "Answer & teach", why the previous answer is
 * shown next to the question (an operator needs to know whether to ADD an answer
 * or REWRITE one), and why answering - not dismissing - is the default.
 *
 * ORDERING, AND WHY IT MATTERS
 *   Flagged first, then oldest-first within a reason. A flagged answer is actively
 *   misleading someone right now; an unanswered question merely lacks help. And a
 *   question that has waited longest must not be starved by new arrivals.
 *
 * CROSS-TENANT BY DESIGN
 *   This is one of the few screens that intentionally shows content from several
 *   workspaces at once (bug reports are the other). The route carries
 *   `role:Software Super Admin`, so no tenant role can reach it.
 */
export default function AssistantIndex({
    escalations,
    stats = {},
    unreliable = [],
    institutions = [],
    filters = {},
}) {
    // Which escalation's answer box is open. One at a time: the operator works
    // through the queue deliberately rather than answering several in parallel.
    const [openId, setOpenId] = useState(null);
    const [answer, setAnswer] = useState('');
    const [busy, setBusy] = useState(false);

    // The "teach it proactively" form, for questions the operator knows are coming.
    const [knowledge, setKnowledge] = useState({ question: '', answer: '', keywords: '' });

    const rows = escalations?.data ?? [];

    const tabs = useMemo(
        () => [
            { value: 'pending', label: 'Pending', count: stats.pending },
            { value: 'answered', label: 'Answered', count: stats.answered },
            { value: 'dismissed', label: 'Dismissed', count: stats.dismissed },
            { value: 'all', label: 'All', count: null },
        ],
        [stats]
    );

    const toneClass = {
        rose: 'bg-rose-50 text-rose-700 border-rose-200',
        amber: 'bg-amber-50 text-amber-700 border-amber-200',
        emerald: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    /** Re-query with a filter change, keeping the scroll position. */
    const applyFilter = (patch) => {
        const next = { ...filters, ...patch };

        // Drop empties so the URL stays clean.
        Object.keys(next).forEach((key) => {
            if (next[key] === '' || next[key] === null) delete next[key];
        });

        router.get(route('ssa.assistant.index'), next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    /**
     * Answer the escalation (which TEACHES the assistant) or dismiss it.
     *
     * One endpoint with an `action` discriminator, because both outcomes mean
     * "close this item" and the only difference is whether the corpus grows -
     * keeping them together makes that distinction impossible to lose in the UI.
     */
    const resolve = (escalation, action) => {
        if (action === 'answer' && answer.trim() === '') return;

        setBusy(true);

        router.patch(
            route('ssa.assistant.update', escalation.id),
            { action, resolution: answer.trim() },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setOpenId(null);
                    setAnswer('');
                },
            }
        );
    };

    /** Add a corpus answer directly, before the question is ever asked. */
    const storeKnowledge = (event) => {
        event.preventDefault();

        if (knowledge.question.trim() === '' || knowledge.answer.trim() === '') return;

        setBusy(true);

        router.post(
            route('ssa.assistant.knowledge.store'),
            {
                question: knowledge.question.trim(),
                answer: knowledge.answer.trim(),
                // Comma-separated in the UI, an array over the wire: this field is a
                // quick-entry aid, not a structured editor.
                keywords: knowledge.keywords
                    .split(',')
                    .map((word) => word.trim())
                    .filter(Boolean),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setKnowledge({ question: '', answer: '', keywords: '' });
                },
            }
        );
    };

    return (
        <SettingsLayout title="Support Assistant">
            <Head title="Support Assistant Queue" />

            <div data-testid="assistant-queue" className="space-y-6">
                {/* ---- Intro + headline stats ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-slate-50/60 px-6 py-5">
                        <h2 className="text-base font-bold text-slate-900">Assistant escalation queue</h2>
                        <p className="mt-1 max-w-3xl text-xs leading-relaxed text-slate-500">
                            Answering a question here teaches the assistant permanently: your reply is
                            stored and the same question is then handled automatically. Flagged answers
                            are the most valuable — they mean the corpus is misleading someone, not just
                            incomplete.
                        </p>
                    </div>

                    <div className="grid grid-cols-2 divide-slate-100 border-t border-slate-100 sm:grid-cols-3 lg:grid-cols-5 lg:divide-x">
                        <Stat label="Pending" value={stats.pending} tone={stats.pending > 0 ? 'amber' : 'slate'} />
                        <Stat label="Flagged (wrong answer)" value={stats.flagged} tone={stats.flagged > 0 ? 'rose' : 'slate'} />
                        <Stat label="Answered" value={stats.answered} tone="emerald" />
                        <Stat label="Dismissed" value={stats.dismissed} tone="slate" />
                        <Stat label="Learned answers" value={stats.learned_total} tone="indigo" />
                    </div>
                </div>

                {/* ---- Filters ---- */}
                <div className="flex flex-wrap items-center gap-2">
                    {tabs.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => applyFilter({ status: tab.value })}
                            data-testid={`assistant-filter-${tab.value}`}
                            className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                                (filters.status || 'pending') === tab.value
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                            {tab.count !== null && tab.count !== undefined && (
                                <span className="rounded-full bg-black/10 px-1.5 text-[10px] font-bold">
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}

                    <button
                        type="button"
                        data-testid="assistant-filter-flagged-only"
                        onClick={() => applyFilter({ reason: filters.reason === 'flagged' ? '' : 'flagged' })}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                            filters.reason === 'flagged'
                                ? 'border-rose-600 bg-rose-600 text-white'
                                : 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'
                        }`}
                    >
                        Only flagged
                    </button>

                    {institutions.length > 1 && (
                        <select
                            value={filters.institution || ''}
                            onChange={(e) => applyFilter({ institution: e.target.value })}
                            aria-label="Filter by institution"
                            className="rounded-full border-slate-200 bg-white py-1.5 pl-3 pr-8 text-xs font-semibold text-slate-600 focus:border-slate-400 focus:ring-0"
                        >
                            <option value="">Every workspace</option>
                            {institutions.map((institution) => (
                                <option key={institution.id} value={institution.id}>
                                    {institution.name}
                                </option>
                            ))}
                        </select>
                    )}
                </div>

                {/* ---- The queue ---- */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {rows.length === 0 ? (
                        <p
                            data-testid="assistant-queue-empty"
                            className="px-6 py-12 text-center text-sm italic text-slate-400"
                        >
                            Nothing waiting. The assistant is handling every question on its own.
                        </p>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {rows.map((item) => {
                                const expanded = openId === item.id;

                                return (
                                    <li key={item.id} data-testid="escalation-row" className="px-6 py-5">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span
                                                        className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${
                                                            toneClass[item.reason_tone] || toneClass.slate
                                                        }`}
                                                    >
                                                        {item.reason_label}
                                                    </span>
                                                    <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                        {item.created_human}
                                                    </span>
                                                </div>

                                                <p
                                                    data-testid="escalation-question"
                                                    className="mt-2 text-sm font-semibold text-slate-900"
                                                >
                                                    “{item.question}”
                                                </p>

                                                <p className="mt-1 text-[11px] text-slate-500">
                                                    {item.asked_by?.name || 'A visitor'}
                                                    {item.asked_by?.email ? ` · ${item.asked_by.email}` : ''}
                                                    {item.institution ? ` · ${item.institution}` : ' · landing page'}
                                                </p>

                                                {/* What the assistant said, so the operator
                                                    knows whether to ADD an answer or REWRITE one. */}
                                                {item.previous_answer && (
                                                    <p
                                                        data-testid="escalation-previous-answer"
                                                        className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800"
                                                    >
                                                        <strong className="font-bold">The assistant said: </strong>
                                                        {item.previous_answer}
                                                    </p>
                                                )}

                                                {!item.is_pending && item.resolution && (
                                                    <p className="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-relaxed text-emerald-800">
                                                        <strong className="font-bold">Resolved: </strong>
                                                        {item.resolution}
                                                        {item.learned_knowledge_id && (
                                                            <span className="ml-1 font-semibold">
                                                                (learned as knowledge #{item.learned_knowledge_id})
                                                            </span>
                                                        )}
                                                    </p>
                                                )}
                                            </div>

                                            {item.is_pending && (
                                                <button
                                                    type="button"
                                                    data-testid="escalation-open"
                                                    onClick={() => {
                                                        setOpenId(expanded ? null : item.id);
                                                        setAnswer('');
                                                    }}
                                                    className="flex-shrink-0 rounded-lg border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                                                >
                                                    {expanded ? 'Cancel' : 'Answer & teach'}
                                                </button>
                                            )}
                                        </div>

                                        {expanded && item.is_pending && (
                                            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                <label
                                                    htmlFor={`escalation-answer-${item.id}`}
                                                    className="text-[11px] font-bold uppercase tracking-wider text-slate-400"
                                                >
                                                    Your answer (this becomes the assistant&apos;s answer)
                                                </label>

                                                <textarea
                                                    id={`escalation-answer-${item.id}`}
                                                    data-testid="escalation-answer-input"
                                                    rows={4}
                                                    value={answer}
                                                    onChange={(e) => setAnswer(e.target.value)}
                                                    placeholder="Write the answer the way the assistant should give it."
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                                                />

                                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        data-testid="escalation-answer-submit"
                                                        disabled={busy || answer.trim() === ''}
                                                        onClick={() => resolve(item, 'answer')}
                                                        className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                                                    >
                                                        Save answer &amp; teach the assistant
                                                    </button>

                                                    <button
                                                        type="button"
                                                        data-testid="escalation-dismiss"
                                                        disabled={busy}
                                                        onClick={() => resolve(item, 'dismiss')}
                                                        className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-40"
                                                    >
                                                        Dismiss without teaching
                                                    </button>

                                                    <span className="text-[11px] text-slate-400">
                                                        Dismissing closes the item without changing the corpus.
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {(escalations?.links ?? []).length > 3 && (
                        <div className="flex flex-wrap items-center gap-1.5 border-t border-slate-100 px-6 py-4">
                            {escalations.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                                    className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ${
                                        link.active
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                    } ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>

                {/* ---- Corpus quality + proactive teaching ---- */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-bold text-slate-900">Answers users keep rejecting</h3>
                        <p className="mt-1 text-xs leading-relaxed text-slate-500">
                            Three or more “not helpful” ratings on one answer means the corpus is actively
                            misleading people. Rewriting these is worth more than adding new ones.
                        </p>

                        <ul data-testid="unreliable-list" className="mt-4 space-y-3">
                            {unreliable.length === 0 ? (
                                <li className="text-xs italic text-slate-400">
                                    No answer has been rejected repeatedly.
                                </li>
                            ) : (
                                unreliable.map((entry) => (
                                    <li key={entry.id} className="rounded-xl border border-rose-100 bg-rose-50/50 p-3">
                                        <p className="text-xs font-semibold text-slate-800">{entry.question}</p>
                                        <p className="mt-1 text-xs leading-relaxed text-slate-600">{entry.answer}</p>
                                        <p className="mt-1.5 text-[11px] font-semibold text-rose-600">
                                            {entry.times_unhelpful} negative rating(s) · used {entry.times_used}×
                                            {entry.institution ? ` · ${entry.institution}` : ' · platform-wide'}
                                        </p>
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-bold text-slate-900">Teach it before it is asked</h3>
                        <p className="mt-1 text-xs leading-relaxed text-slate-500">
                            Add an answer directly — for a new feature, or a process change — instead of
                            waiting for somebody to be confused by it.
                        </p>

                        <form data-testid="knowledge-form" onSubmit={storeKnowledge} className="mt-4 space-y-3">
                            <div>
                                <label htmlFor="knowledge-question" className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    The question
                                </label>
                                <input
                                    id="knowledge-question"
                                    data-testid="knowledge-question"
                                    value={knowledge.question}
                                    onChange={(e) => setKnowledge({ ...knowledge, question: e.target.value })}
                                    placeholder="How do I export a report?"
                                    className="mt-1 block w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                                />
                            </div>

                            <div>
                                <label htmlFor="knowledge-answer" className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    The answer
                                </label>
                                <textarea
                                    id="knowledge-answer"
                                    data-testid="knowledge-answer"
                                    rows={4}
                                    value={knowledge.answer}
                                    onChange={(e) => setKnowledge({ ...knowledge, answer: e.target.value })}
                                    placeholder="Reports → Export, then choose CSV or Excel."
                                    className="mt-1 block w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                                />
                            </div>

                            <div>
                                <label htmlFor="knowledge-keywords" className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Keywords (comma separated, optional)
                                </label>
                                <input
                                    id="knowledge-keywords"
                                    data-testid="knowledge-keywords"
                                    value={knowledge.keywords}
                                    onChange={(e) => setKnowledge({ ...knowledge, keywords: e.target.value })}
                                    placeholder="export, download, csv, report"
                                    className="mt-1 block w-full rounded-xl border-slate-200 bg-white text-sm text-slate-800 shadow-sm focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
                                />
                            </div>

                            <button
                                type="submit"
                                data-testid="knowledge-submit"
                                disabled={busy || knowledge.question.trim() === '' || knowledge.answer.trim() === ''}
                                className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                            >
                                Add to the assistant&apos;s knowledge
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        </SettingsLayout>
    );
}

/** One headline figure. */
function Stat({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        amber: 'text-amber-600',
        rose: 'text-rose-600',
        emerald: 'text-emerald-600',
        indigo: 'text-indigo-600',
    };

    return (
        <div className="px-6 py-4">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1 text-2xl font-extrabold ${tones[tone] || tones.slate}`}>{value ?? 0}</p>
        </div>
    );
}
