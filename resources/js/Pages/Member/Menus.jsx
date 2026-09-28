import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import { Head, router, usePage } from '@inertiajs/react';

/**
 * MEMBER MENU VOTING.
 *
 * The member's own view: menus currently open for a vote (one choice each), plus
 * the outcome of what they voted on before. A member holds exactly ONE vote per
 * menu - changing their mind moves that vote rather than adding another.
 */
export default function Menus({ openMenus = [], history = [], canVote = false }) {
    const { flash } = usePage().props;

    // Which option is currently selected per menu (before submitting).
    const [selections, setSelections] = useState(() =>
        Object.fromEntries(openMenus.map((menu) => [menu.id, menu.my_vote_option_id || null]))
    );

    const castVote = (menuId) => {
        const optionId = selections[menuId];

        if (!optionId) return;

        router.post(route('member.menus.vote', menuId), { option_id: optionId }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    {/* Title lives in the fixed top bar; this is the description. */}
                    <p className="text-xs font-medium text-slate-500">
                        Have your say on what is served. One vote per menu.
                    </p>
                </div>
            }
        >
            <Head title="Meal Voting" />

            <div className="space-y-6">
                <PageHint title="How your vote works">
                    Pick the dish you would prefer for each open menu and submit. You hold{' '}
                    <strong className="font-semibold text-slate-700">one vote per menu</strong> - you can change
                    it while voting is open (unless the menu is locked). The kitchen only cooks an approved menu,
                    so your vote genuinely shapes the week.
                </PageHint>

                {flash?.success && (
                    <div role="status" data-testid="vote-flash" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" data-testid="vote-error" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
                        {flash.error}
                    </div>
                )}

                {!canVote && (
                    <InfoHint tone="amber">
                        Your account is not currently enrolled in meals, so you cannot vote on menus.
                        Ask your administrator if you should be included.
                    </InfoHint>
                )}

                {/* ---- Open votes ---- */}
                <div className="space-y-4">
                    {openMenus.length > 0 ? openMenus.map((menu) => {
                        const hasVoted = Boolean(menu.my_vote_option_id);
                        const selection = selections[menu.id];

                        return (
                            <div
                                key={menu.id}
                                data-testid="open-menu-card"
                                className="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs"
                            >
                                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700">
                                                Voting open
                                            </span>
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-[10px] font-semibold text-slate-600">
                                                {menu.meal_type_label}
                                            </span>
                                            <span className="text-[11px] font-medium text-slate-500">{menu.menu_date}</span>
                                        </div>
                                        <h3 className="mt-2 text-base font-bold text-slate-900">{menu.title}</h3>
                                        {menu.description && (
                                            <p className="mt-0.5 text-xs text-slate-500">{menu.description}</p>
                                        )}
                                        {menu.voting_closes_at && (
                                            <p className="mt-1 text-[11px] font-medium text-amber-600">
                                                Closes {menu.voting_closes_at}
                                            </p>
                                        )}
                                    </div>

                                    {hasVoted && (
                                        <span
                                            data-testid="voted-badge"
                                            className="self-start rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-bold text-emerald-700"
                                        >
                                            ✓ Voted
                                        </span>
                                    )}
                                </div>

                                <div className="mt-4 space-y-2">
                                    {menu.options.map((option) => {
                                        const selected = selection === option.id;

                                        return (
                                            <button
                                                key={option.id}
                                                type="button"
                                                disabled={!canVote || (hasVoted && !menu.allow_vote_changes)}
                                                onClick={() => setSelections((prev) => ({ ...prev, [menu.id]: option.id }))}
                                                data-testid={`vote-option-${option.id}`}
                                                className={`flex w-full items-center gap-3 rounded-xl border p-3.5 text-left transition-all disabled:cursor-not-allowed ${selected
                                                    ? 'border-[var(--accent)] bg-[var(--accent-soft)] ring-1 ring-[var(--accent-ring)]'
                                                    : 'border-slate-200 bg-white hover:border-slate-300 disabled:opacity-60'}`}
                                            >
                                                <span
                                                    className={`flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full border-2 ${selected ? 'border-[var(--accent)] bg-[var(--accent)]' : 'border-slate-300'}`}
                                                >
                                                    {selected && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                                                </span>

                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-bold text-slate-900">{option.name}</span>
                                                        {option.is_recommended && (
                                                            <span className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700">
                                                                Recommended
                                                            </span>
                                                        )}
                                                    </span>
                                                    {option.description && (
                                                        <span className="mt-0.5 block text-xs text-slate-500">{option.description}</span>
                                                    )}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className="mt-4 flex items-center justify-between gap-3">
                                    <span className="text-[11px] text-slate-400">
                                        {menu.total_votes} vote(s) so far
                                        {!menu.allow_vote_changes && hasVoted ? ' · locked' : ''}
                                    </span>

                                    <button
                                        type="button"
                                        onClick={() => castVote(menu.id)}
                                        disabled={!canVote || !selection || (hasVoted && selection === menu.my_vote_option_id)}
                                        data-testid="submit-vote"
                                        className="rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-bold text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        {hasVoted ? 'Update my vote' : 'Submit my vote'}
                                    </button>
                                </div>
                            </div>
                        );
                    }) : (
                        <div className="rounded-2xl border border-slate-200 bg-white py-14 text-center shadow-sm">
                            <p className="text-sm font-semibold text-slate-600">No menus are open for voting.</p>
                            <p className="mt-1 text-xs text-slate-400">
                                Your kitchen will post one here when there is a choice to make.
                            </p>
                        </div>
                    )}
                </div>

                {/* ---- Past votes ---- */}
                {history.length > 0 && (
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                        <div className="border-b border-slate-100 bg-slate-50/70 px-6 py-3">
                            <h3 className="text-sm font-bold text-slate-900">Your past votes</h3>
                        </div>
                        <table className="w-full text-left text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {history.map((row) => (
                                    <tr key={row.id} data-testid="vote-history-row">
                                        <td className="px-6 py-3">
                                            <p className="text-xs font-semibold text-slate-800">{row.title}</p>
                                            <p className="text-[11px] text-slate-400">
                                                {row.meal_type_label} · {row.menu_date}
                                            </p>
                                        </td>
                                        <td className="px-6 py-3 text-xs text-slate-600">
                                            You chose: <strong className="font-semibold">{row.my_choice || '—'}</strong>
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase ${row.status_tone === 'emerald'
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                                                {row.status_label}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}