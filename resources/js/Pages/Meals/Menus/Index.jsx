import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHint, InfoHint } from '@/Components/Help/HelpHint';
import Modal from '@/Components/UI/Modal';
import Field from '@/Components/UI/Field';
import { Spinner } from '@/Components/UI/Loading';
import { useFeedback } from '@/Components/Feedback/FeedbackProvider';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

/**
 * MEAL MENU BOARD (staff / management).
 *
 * Lists every proposed menu with its lifecycle status and current leader, and is
 * where an Admin or Meal Manager APPROVES or REJECTS a menu. The approval controls
 * are only rendered when the viewer actually holds that right (`canApprove`), so a
 * Meal Manager who is merely reading sees no dead buttons.
 */
export default function Index({ menus, mealTypes = [], filters = {}, canApprove = false, summary = {} }) {
    const { flash } = usePage().props;
    const { confirm } = useFeedback();

    const [createOpen, setCreateOpen] = useState(false);

    const rows = menus?.data || [];

    const applyFilter = (next) => {
        router.get(route('meals.menus.index'), { ...filters, ...next }, {
            preserveState: true, preserveScroll: true, replace: true,
        });
    };

    const approve = async (menu) => {
        const ok = await confirm({
            title: `Approve "${menu.title}"?`,
            message: menu.leading
                ? `This makes it the active menu. The leading option is "${menu.leading.name}" (${menu.leading.votes} vote(s)).`
                : 'There is no clear winner yet - you will need to choose an option on the menu page.',
            tone: 'info',
            confirmLabel: 'Approve',
        });
        if (!ok) return;

        router.patch(route('meals.menus.approve', menu.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        {/* Title lives in the fixed top bar; this is the description. */}
                        <p className="text-xs font-medium text-slate-500">
                            Propose menus, gather member votes, and approve what actually gets cooked.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setCreateOpen(true)}
                        data-testid="menu-create-button"
                        className="inline-flex items-center gap-2 self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    >
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Propose a menu
                    </button>
                </div>
            }
        >
            <Head title="Meal Menus" />

            <div className="space-y-5">
                <PageHint title="How the vote becomes a menu">
                    Propose two or more options, open the vote, and let eligible members choose.
                    Nothing becomes the active menu until an <strong className="font-semibold text-slate-700">Admin
                        or Meal Manager approves it</strong> - that is the step this board exists to make explicit.
                </PageHint>

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

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {[
                        { label: 'Voting open', value: summary.open_votes ?? 0, tone: 'text-sky-600' },
                        { label: 'Awaiting approval', value: summary.awaiting_approval ?? 0, tone: 'text-amber-600' },
                        { label: 'Approved', value: summary.approved ?? 0, tone: 'text-emerald-600' },
                        { label: 'Eligible voters', value: summary.eligible_voters ?? 0, tone: 'text-slate-900' },
                    ].map((card) => (
                        <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{card.label}</p>
                            <p className={`mt-1 text-2xl font-bold ${card.tone}`}>{card.value}</p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <select
                        value={filters.status || ''}
                        onChange={(e) => applyFilter({ status: e.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="voting">Voting open</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <select
                        value={filters.meal_type || ''}
                        onChange={(e) => applyFilter({ meal_type: e.target.value })}
                        className="rounded-lg border-slate-300 text-sm text-slate-900 focus:border-[var(--accent)] focus:ring-[var(--accent)]"
                    >
                        <option value="">All meals</option>
                        {mealTypes.map((type) => (
                            <option key={type.value} value={type.value}>{type.label}</option>
                        ))}
                    </select>
                </div>

                <div className="space-y-3">
                    {rows.length > 0 ? rows.map((menu) => (
                        <div
                            key={menu.id}
                            data-testid="menu-card"
                            className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs"
                        >
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusChip tone={menu.status_tone} label={menu.status_label} />
                                        <span className="rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-[10px] font-semibold text-slate-600">
                                            {menu.meal_type_label}
                                        </span>
                                        <span className="text-[11px] font-medium text-slate-500">{menu.menu_date}</span>
                                        {menu.voting_closes_at && menu.status === 'voting' && (
                                            <span className="text-[11px] text-amber-600">Closes {menu.voting_closes_at}</span>
                                        )}
                                    </div>

                                    <Link
                                        href={route('meals.menus.show', menu.id)}
                                        className="mt-2 block text-sm font-bold text-slate-900 hover:text-[var(--accent)]"
                                    >
                                        {menu.title}
                                    </Link>

                                    {menu.description && (
                                        <p className="mt-1 text-xs text-slate-500">{menu.description}</p>
                                    )}

                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                                        <span><strong className="font-semibold text-slate-700">{menu.options_count}</strong> options</span>
                                        <span><strong className="font-semibold text-slate-700">{menu.votes_count}</strong> votes</span>
                                        {menu.creator && <span>by {menu.creator}</span>}
                                        {menu.approver && <span>approved by {menu.approver}</span>}
                                    </div>

                                    {menu.leading && (
                                        <p className="mt-2 text-xs font-semibold text-emerald-700">
                                            Leading: {menu.leading.name} ({menu.leading.votes} vote(s), {menu.leading.percentage}%)
                                        </p>
                                    )}

                                    {menu.approval_note && (
                                        <p className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
                                            {menu.approval_note}
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-shrink-0 flex-wrap gap-2">
                                    <Link
                                        href={route('meals.menus.show', menu.id)}
                                        className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        Open
                                    </Link>

                                    {canApprove && (menu.status === 'voting' || menu.status === 'draft') && (
                                        <button
                                            type="button"
                                            onClick={() => approve(menu)}
                                            data-testid="menu-approve-button"
                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                        >
                                            Approve
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    )) : (
                        <div className="rounded-2xl border border-slate-200 bg-white py-14 text-center shadow-sm">
                            <p className="text-sm font-semibold text-slate-600">No menus yet.</p>
                            <p className="mt-1 text-xs text-slate-400">
                                Propose one to start collecting member votes.
                            </p>
                        </div>
                    )}
                </div>

                {menus?.links?.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {menus.links.map((link, index) => (
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

                {!canApprove && (
                    <InfoHint tone="amber">
                        Only an <strong>Institution Admin</strong> or <strong>Meal Manager</strong> can approve a menu.
                        You can still propose one and open the vote.
                    </InfoHint>
                )}
            </div>

            <CreateMenuModal open={createOpen} onClose={() => setCreateOpen(false)} mealTypes={mealTypes} />
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

/** Propose a menu with two starting options. */
function CreateMenuModal({ open, onClose, mealTypes }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        title: '',
        meal_type: 'lunch',
        menu_date: new Date().toISOString().slice(0, 10),
        description: '',
        allow_vote_changes: true,
        voting_closes_at: '',
        open_voting: true,
        options: [
            { name: '', description: '', estimated_cost: '', is_recommended: false },
            { name: '', description: '', estimated_cost: '', is_recommended: false },
        ],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('meals.menus.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); clearErrors(); onClose(); },
        });
    };

    const setOption = (index, key, value) => {
        const next = [...data.options];
        next[index] = { ...next[index], [key]: value };
        setData('options', next);
    };

    const addOption = () => {
        setData('options', [...data.options, { name: '', description: '', estimated_cost: '', is_recommended: false }]);
    };

    const removeOption = (index) => {
        if (data.options.length <= 2) return;
        setData('options', data.options.filter((_, i) => i !== index));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Propose a meal menu"
            description="Members will vote between the options you add here."
            maxWidth="max-w-2xl"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        form="menu-create-form"
                        disabled={processing}
                        data-testid="menu-submit"
                        className="inline-flex items-center gap-2 rounded-lg bg-[var(--accent)] px-5 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {processing && <Spinner className="h-4 w-4" />}
                        {processing ? 'Creating...' : 'Create menu'}
                    </button>
                </>
            }
        >
            <form id="menu-create-form" onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Title"
                        name="title"
                        required
                        value={data.title}
                        error={errors.title}
                        placeholder="e.g. Friday lunch"
                        onChange={(e) => setData('title', e.target.value)}
                    />
                    <Field
                        label="Meal"
                        name="meal_type"
                        type="select"
                        required
                        value={data.meal_type}
                        error={errors.meal_type}
                        options={mealTypes}
                        onChange={(e) => setData('meal_type', e.target.value)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Date"
                        name="menu_date"
                        type="date"
                        required
                        value={data.menu_date}
                        error={errors.menu_date}
                        onChange={(e) => setData('menu_date', e.target.value)}
                    />
                    <Field
                        label="Voting closes"
                        name="voting_closes_at"
                        type="datetime-local"
                        value={data.voting_closes_at}
                        error={errors.voting_closes_at}
                        hint="Optional. Leave blank to keep the vote open."
                        onChange={(e) => setData('voting_closes_at', e.target.value)}
                    />
                </div>

                <Field
                    label="Description"
                    name="description"
                    value={data.description}
                    error={errors.description}
                    placeholder="Any context for the voters"
                    onChange={(e) => setData('description', e.target.value)}
                />

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-bold uppercase tracking-wider text-[var(--accent)]">
                            Options ({data.options.length})
                        </p>
                        <button
                            type="button"
                            onClick={addOption}
                            className="text-xs font-semibold text-[var(--accent)] hover:underline"
                        >
                            + Add option
                        </button>
                    </div>

                    {data.options.map((option, index) => (
                        <div key={index} className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                            <div className="grid gap-3 sm:grid-cols-5">
                                <div className="sm:col-span-3">
                                    <input
                                        value={option.name}
                                        onChange={(e) => setOption(index, 'name', e.target.value)}
                                        placeholder={`Option ${index + 1} (e.g. Rice + Chicken)`}
                                        className="w-full rounded-lg border-slate-300 px-3 py-2 text-sm outline-none focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]"
                                    />
                                </div>
                                <input
                                    value={option.estimated_cost}
                                    onChange={(e) => setOption(index, 'estimated_cost', e.target.value)}
                                    placeholder="Cost/serving"
                                    type="number"
                                    step="0.01"
                                    className="rounded-lg border-slate-300 px-3 py-2 text-sm outline-none focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]"
                                />
                                <div className="flex items-center justify-end gap-2">
                                    <label className="flex cursor-pointer items-center gap-1.5 text-[11px] font-semibold text-slate-600">
                                        <input
                                            type="checkbox"
                                            checked={option.is_recommended}
                                            onChange={(e) => setOption(index, 'is_recommended', e.target.checked)}
                                            className="h-3.5 w-3.5 rounded border-slate-300 text-[var(--accent)]"
                                        />
                                        Rec.
                                    </label>
                                    {data.options.length > 2 && (
                                        <button
                                            type="button"
                                            onClick={() => removeOption(index)}
                                            className="rounded-md p-1 text-rose-500 hover:bg-rose-50"
                                            aria-label="Remove option"
                                        >
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))}
                    {errors.options && (
                        <p className="text-xs font-medium text-rose-600">{errors.options}</p>
                    )}
                </div>

                <div className="flex flex-wrap gap-4 border-t border-slate-100 pt-4">
                    <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            checked={data.open_voting}
                            onChange={(e) => setData('open_voting', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-[var(--accent)]"
                        />
                        Open voting immediately
                    </label>
                    <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            checked={data.allow_vote_changes}
                            onChange={(e) => setData('allow_vote_changes', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-[var(--accent)]"
                        />
                        Allow members to change their vote
                    </label>
                </div>
            </form>
        </Modal>
    );
}