import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * ONE MEAL MENU — the vote tally, the voters, and the approval controls.
 *
 * This is where an Admin or Meal Manager actually DECIDES: they see the tally, the
 * turnout, and can approve a specific option (or the clear winner) or reject the
 * proposal with a reason.
 */
export default function Show({ menu, tally = [], votes = [], myVote = null, canApprove = false, canVote = false, totalVotes = 0, eligibleVoters = 0 }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();

    const [rejecting, setRejecting] = useState(false);
    const [rejectNote, setRejectNote] = useState('');
    const [selectedOption, setSelectedOption] = useState(null);

    const turnout = eligibleVoters > 0 ? Math.round((totalVotes / eligibleVoters) * 100) : 0;

    const approve = async (optionId = null) => {
        const option = optionId ? tally.find((row) => row.option_id === optionId) : menu.options[0];

        const ok = await confirm({
            title: 'Approve this menu?',
            message: optionId
                ? `"${option?.name}" will become the approved dish for ${menu.meal_type_label} on ${menu.menu_date}.`
                : 'The leading option will be adopted as the approved dish.',
            tone: 'info',
            confirmLabel: 'Approve',
        });
        if (!ok) return;

        router.patch(route('meals.menus.approve', menu.id), { option_id: optionId }, { preserveScroll: true });
    };

    const reject = () => {
        router.patch(route('meals.menus.reject', menu.id), { approval_note: rejectNote }, {
            preserveScroll: true,
            onSuccess: () => { setRejecting(false); setRejectNote(''); },
        });
    };

    const openVoting = () => {
        router.patch(route('meals.menus.open', menu.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href={route('meals.menus.index')} className="text-xs font-semibold text-slate-400 hover:text-slate-600">
                                ← Meal Menus
                            </Link>
                            <StatusChip tone={menu.status_tone} label={menu.status_label} />
                        </div>
                        <h2 className="mt-1 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                            {menu.title}
                        </h2>
                        <p className="mt-0.5 text-xs font-medium text-slate-500">
                            {menu.meal_type_label} · {menu.menu_date}
                            {menu.voting_closes_at && menu.voting_open ? ` · voting closes ${menu.voting_closes_at}` : ''}
                        </p>
                    </div>
                </div>
            }
        >
            <Head title={menu.title} />

            <div className="space-y-6">
                {flash?.success && (
                    <div role="status" data-testid="menu-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {/* Approval state banner - the point of the whole workflow. */}
                {menu.status === 'approved' ? (
                    <div data-testid="menu-approved-banner" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                        <p className="text-sm font-bold text-emerald-800">This menu is approved and active.</p>
                        <p className="mt-0.5 text-xs text-emerald-700">
                            Approved by {menu.approver}{menu.approved_at ? ` on ${menu.approved_at}` : ''}.
                        </p>
                        {menu.approval_note && <p className="mt-1 text-xs text-emerald-700">{menu.approval_note}</p>}
                    </div>
                ) : menu.status === 'rejected' ? (
                    <div data-testid="menu-rejected-banner" className="rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <p className="text-sm font-bold text-rose-800">This menu was rejected.</p>
                        {menu.approval_note && <p className="mt-0.5 text-xs text-rose-700">{menu.approval_note}</p>}
                    </div>
                ) : (
                    <InfoHint tone="amber">
                        <strong>Awaiting approval.</strong> Members can vote while this is open, but it will not
                        become the active menu until an Admin or Meal Manager approves it.
                    </InfoHint>
                )}

                {menu.description && (
                    <p className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600">
                        {menu.description}
                    </p>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* ---- Tally ---- */}
                    <div className="lg:col-span-2">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-sm font-bold text-slate-900">Vote tally</h3>
                                <span className="text-xs font-medium text-slate-400">
                                    {totalVotes} of {eligibleVoters} eligible ({turnout}%)
                                </span>
                            </div>

                            <div className="space-y-3">
                                {tally.map((row) => (
                                    <div
                                        key={row.option_id}
                                        data-testid="tally-row"
                                        className={`rounded-xl border p-3.5 transition-colors ${row.is_winner && row.votes > 0
                                            ? 'border-emerald-300 bg-emerald-50/60'
                                            : 'border-slate-200 bg-white'}`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm font-bold text-slate-900">{row.name}</span>
                                                    {row.is_recommended && (
                                                        <span className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700">
                                                            Recommended
                                                        </span>
                                                    )}
                                                    {row.is_winner && row.votes > 0 && (
                                                        <span data-testid="tally-winner" className="rounded-full border border-emerald-200 bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                                                            Leading
                                                        </span>
                                                    )}
                                                </div>
                                                {row.description && (
                                                    <p className="mt-0.5 text-xs text-slate-500">{row.description}</p>
                                                )}
                                                {row.estimated_cost > 0 && (
                                                    <p className="mt-0.5 text-[11px] text-slate-400">
                                                        Est. {row.estimated_cost} per serving
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex-shrink-0 text-right">
                                                <p className="text-lg font-extrabold text-slate-900">{row.votes}</p>
                                                <p className="text-[11px] font-medium text-slate-400">{row.percentage}%</p>
                                            </div>
                                        </div>

                                        <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className={`h-full rounded-full transition-all ${row.is_winner && row.votes > 0 ? 'bg-emerald-500' : 'bg-slate-300'}`}
                                                style={{ width: `${row.percentage}%` }}
                                            />
                                        </div>

                                        {/* Approving a SPECIFIC option, which is how a tie is resolved. */}
                                        {canApprove && (menu.status === 'voting' || menu.status === 'draft') && (
                                            <button
                                                type="button"
                                                onClick={() => approve(row.option_id)}
                                                data-testid={`approve-option-${row.option_id}`}
                                                className="mt-2 text-[11px] font-bold text-emerald-700 hover:underline"
                                            >
                                                Approve this option
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* ---- Voters ---- */}
                        <div className="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                            <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                                <h3 className="text-sm font-bold text-slate-900">Votes cast ({votes.length})</h3>
                            </div>
                            <div className="max-h-80 overflow-y-auto">
                                {votes.length > 0 ? (
                                    <table className="w-full text-left text-sm">
                                        <tbody className="divide-y divide-slate-100">
                                            {votes.map((vote) => (
                                                <tr key={vote.id}>
                                                    <td className="px-6 py-2.5 text-xs font-semibold text-slate-700">{vote.voter}</td>
                                                    <td className="px-6 py-2.5 text-xs text-slate-500">{vote.option}</td>
                                                    <td className="px-6 py-2.5 text-right text-[11px] text-slate-400">{vote.created_at}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                ) : (
                                    <p className="px-6 py-8 text-center text-xs text-slate-400">
                                        No votes have been cast yet.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* ---- Actions ---- */}
                    <div className="space-y-5">
                        {canApprove && (menu.status === 'voting' || menu.status === 'draft') && (
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                                <h3 className="text-sm font-bold text-slate-900">Decision</h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    Approving makes this the active menu. Rejecting keeps the current one in place.
                                </p>

                                <div className="mt-4 space-y-2">
                                    <button
                                        type="button"
                                        onClick={() => approve(null)}
                                        data-testid="menu-approve-button"
                                        className="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-emerald-700"
                                    >
                                        Approve leading option
                                    </button>

                                    {menu.status === 'draft' && (
                                        <button
                                            type="button"
                                            onClick={openVoting}
                                            data-testid="menu-open-voting"
                                            className="w-full rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
                                        >
                                            Open the vote first
                                        </button>
                                    )}

                                    {!rejecting ? (
                                        <button
                                            type="button"
                                            onClick={() => setRejecting(true)}
                                            data-testid="menu-reject-open"
                                            className="w-full rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-100"
                                        >
                                            Reject
                                        </button>
                                    ) : (
                                        <div className="space-y-2 rounded-xl border border-rose-200 bg-rose-50 p-3">
                                            <textarea
                                                value={rejectNote}
                                                onChange={(e) => setRejectNote(e.target.value)}
                                                rows={2}
                                                placeholder="Why is this being rejected?"
                                                className="w-full rounded-lg border-rose-200 px-3 py-2 text-xs outline-none focus:border-rose-400 focus:ring-2 focus:ring-rose-200"
                                            />
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={reject}
                                                    disabled={rejectNote.trim().length === 0}
                                                    data-testid="menu-reject-submit"
                                                    className="flex-1 rounded-lg bg-rose-600 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-rose-700 disabled:opacity-50"
                                                >
                                                    Confirm rejection
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setRejecting(false)}
                                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-600"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* The member's own vote state, if they reached this page. */}
                        {myVote && (
                            <InfoHint tone="indigo">
                                Your vote is recorded for{' '}
                                <strong>{menu.options.find((o) => o.id === myVote.option_id)?.name}</strong>.
                            </InfoHint>
                        )}

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-xs text-slate-500">
                            <p className="font-semibold text-slate-700">Who can vote</p>
                            <p className="mt-1.5 leading-relaxed">
                                Regular members, plus Admins and Meal Managers who have opted into meals.
                                Staff who do not eat in the mess are not eligible.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function StatusChip({ tone, label }) {
    const tones = {
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        sky: 'border-sky-200 bg-sky-50 text-sky-700',
        rose: 'border-rose-200 bg-rose-50 text-rose-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-700',
        slate: 'border-slate-200 bg-slate-100 text-slate-600',
    };

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${tones[tone] || tones.slate}`}>
            {label}
        </span>
    );
}