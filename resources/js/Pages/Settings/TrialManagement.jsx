import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import useMoney from '@/Utils/useMoney';
import { Head, Link, useForm, router } from '@inertiajs/react';

/**
 * Software Super Admin - Trial & Subscription Management.
 *
 * One place to see every institution's onboarding mode, a live countdown for
 * trials, and to act: send an upgrade prompt, extend a trial, or convert to a
 * permanent subscription. Only the global role can reach this (server-guarded).
 */
export default function TrialManagement({ institutions = [], stats = {}, filter = 'all', trialDays = 7 }) {
    const money = useMoney();
    const [converting, setConverting] = useState(null);
    const [extending, setExtending] = useState(null);

    const toneClass = {
        emerald: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        amber: 'bg-amber-50 text-amber-700 border-amber-100',
        rose: 'bg-rose-50 text-rose-700 border-rose-100',
        sky: 'bg-sky-50 text-sky-700 border-sky-100',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    const filters = [
        { value: 'all', label: 'All', count: stats.total },
        { value: 'trial', label: 'On trial', count: stats.trialing },
        { value: 'ending', label: 'Ending soon', count: stats.ending_soon },
        { value: 'expired', label: 'Expired', count: stats.expired },
        { value: 'subscribed', label: 'Subscribed', count: stats.subscribed },
    ];

    const setFilter = (value) => {
        router.get(route('settings.trials.index'), { filter: value }, { preserveState: true, replace: true });
    };

    const sendPrompt = (id) => {
        router.post(route('settings.trials.remind', id), {}, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Trial Management">
            <Head title="Trial Management" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Trial &amp; Subscription Management</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Track {trialDays}-day free trials, expiry countdowns and permanent subscribers.
                        </p>
                    </div>
                    <Link
                        href={route('settings.monitoring.index')}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        Back to Dashboard
                    </Link>
                </div>

                {/* Stat cards */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat label="On Trial" value={stats.trialing} tone="sky" />
                    <Stat label="Ending Soon" value={stats.ending_soon} tone="amber" />
                    <Stat label="Trial Expired" value={stats.expired} tone="rose" />
                    <Stat label="Subscribed" value={stats.subscribed} tone="emerald" />
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-2">
                    {filters.map((f) => (
                        <button
                            key={f.value}
                            type="button"
                            onClick={() => setFilter(f.value)}
                            className={`rounded-xl border px-3.5 py-1.5 text-xs font-semibold transition-colors ${filter === f.value
                                    ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                    : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                }`}
                        >
                            {f.label}{typeof f.count === 'number' ? ` (${f.count})` : ''}
                        </button>
                    ))}
                </div>

                {/* Table */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3 font-bold">Institution</th>
                                    <th className="px-4 py-3 font-bold">Mode</th>
                                    <th className="px-4 py-3 font-bold">Trial Status</th>
                                    <th className="px-4 py-3 font-bold">Ends</th>
                                    <th className="px-4 py-3 font-bold">Plan / Amount</th>
                                    <th className="px-4 py-3 font-bold">Reminder</th>
                                    <th className="px-6 py-3 text-right font-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {institutions.map((inst) => {
                                    const isTrial = inst.onboarding_mode === 'trial';
                                    const canPrompt = isTrial && inst.subscription_status !== 'paid';

                                    return (
                                        <tr key={inst.id} className="hover:bg-slate-50/60">
                                            <td className="px-6 py-3">
                                                <div className="flex items-center gap-3">
                                                    {inst.logo_url ? (
                                                        <img src={inst.logo_url} alt="" className="h-8 w-8 rounded-lg object-contain" />
                                                    ) : (
                                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500">
                                                            {inst.name?.[0]?.toUpperCase()}
                                                        </span>
                                                    )}
                                                    <div className="min-w-0">
                                                        <p className="truncate font-semibold text-slate-800">{inst.name}</p>
                                                        <p className="text-[11px] text-slate-400">
                                                            {inst.admin_email || 'no admin'} · {inst.members_count} members
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex rounded-full border-slate-200 bg-slate-50 px-2.5 py-0.5 text-[10px] font-bold capitalize text-slate-600">
                                                    {isTrial ? 'Free trial' : 'Subscription'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${toneClass[inst.trial_state?.tone] || toneClass.slate}`}
                                                >
                                                    {inst.trial_state?.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-slate-600">
                                                {isTrial ? (
                                                    <>
                                                        <span className="font-semibold text-slate-700">{inst.trial_ends_at || '—'}</span>
                                                        {inst.trial_ends_human && (
                                                            <span className="block text-[11px] text-slate-400">{inst.trial_ends_human}</span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-[12px] text-slate-600">
                                                {inst.subscription_plan || '—'}
                                                {inst.subscription_amount > 0 && (
                                                    <span className="block text-[11px] text-slate-400">
                                                        {money(inst.subscription_amount, true)}/mo
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-[11px] text-slate-400">
                                                {inst.reminder_sent_at || 'never'}
                                            </td>
                                            <td className="px-6 py-3">
                                                <div className="flex items-center justify-end gap-2">
                                                    {canPrompt && (
                                                        <button
                                                            type="button"
                                                            onClick={() => sendPrompt(inst.id)}
                                                            className="rounded-lg border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-700 transition-colors hover:bg-sky-100"
                                                        >
                                                            Send upgrade prompt
                                                        </button>
                                                    )}
                                                    {isTrial && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                onClick={() => setExtending(inst)}
                                                                className="rounded-lg border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                                            >
                                                                Extend
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => setConverting(inst)}
                                                                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                                            >
                                                                Convert
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {institutions.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No institutions match this filter.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {converting && <ConvertModal institution={converting} onClose={() => setConverting(null)} />}
            {extending && <ExtendModal institution={extending} onClose={() => setExtending(null)} />}
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ *
 * Modals
 * ------------------------------------------------------------------ */

function ConvertModal({ institution, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        subscription_plan: institution.subscription_plan || 'Standard',
        subscription_amount: institution.subscription_amount || 0,
        renews_at: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.trials.convert', institution.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <ModalShell title={institution.name} subtitle="Convert trial to a permanent subscription" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Plan name">
                    <input
                        type="text"
                        value={data.subscription_plan}
                        onChange={(e) => setData('subscription_plan', e.target.value)}
                        className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Monthly amount">
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.subscription_amount}
                            onChange={(e) => setData('subscription_amount', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>
                    <Field label="Renews on">
                        <input
                            type="date"
                            value={data.renews_at}
                            onChange={(e) => setData('renews_at', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>
                </div>
                {Object.keys(errors).length > 0 && <p className="text-xs text-rose-500">Please check the values.</p>}
                <ModalActions onCancel={onClose} processing={processing} submitLabel="Convert to subscription" tone="emerald" />
            </form>
        </ModalShell>
    );
}

function ExtendModal({ institution, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ days: 7 });

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.trials.extend', institution.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <ModalShell title={institution.name} subtitle="Extend the free trial" onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Extra days">
                    <input
                        type="number"
                        min="1"
                        max="90"
                        value={data.days}
                        onChange={(e) => setData('days', e.target.value)}
                        className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                    />
                </Field>
                {Object.keys(errors).length > 0 && <p className="text-xs text-rose-500">Please check the value.</p>}
                <ModalActions onCancel={onClose} processing={processing} submitLabel="Extend trial" tone="indigo" />
            </form>
        </ModalShell>
    );
}

/* ------------------------------------------------------------------ *
 * Presentational helpers
 * ------------------------------------------------------------------ */

function Stat({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'text-slate-900',
        sky: 'text-sky-600',
        amber: 'text-amber-600',
        rose: 'text-rose-600',
        emerald: 'text-emerald-600',
    };

    return (
        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1.5 text-2xl font-bold ${tones[tone]}`}>{value ?? 0}</p>
        </div>
    );
}

function ModalShell({ title, subtitle, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
            <div className="w-full max-w-md rounded-2xl border-slate-100 bg-white p-6 shadow-xl">
                <h3 className="text-base font-bold text-slate-900">{title}</h3>
                <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>
                <div className="mt-5">{children}</div>
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-semibold text-slate-700">{label}</label>
            {children}
        </div>
    );
}

function ModalActions({ onCancel, processing, submitLabel, tone = 'indigo' }) {
    const tones = {
        indigo: 'bg-indigo-600 hover:bg-indigo-700',
        emerald: 'bg-emerald-600 hover:bg-emerald-700',
    };

    return (
        <div className="flex justify-end gap-2 pt-2">
            <button
                type="button"
                onClick={onCancel}
                className="rounded-xl border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            >
                Cancel
            </button>
            <button
                type="submit"
                disabled={processing}
                className={`rounded-xl px-4 py-2 text-sm font-semibold text-white transition-colors disabled:opacity-50 ${tones[tone]}`}
            >
                {processing ? 'Saving...' : submitLabel}
            </button>
        </div>
    );
}
