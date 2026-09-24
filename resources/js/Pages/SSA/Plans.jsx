import React, { useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import useMoney from '@/Utils/useMoney';
import { Spinner } from '@/Components/UI/Loading';
import { Head, Link, useForm, router } from '@inertiajs/react';

/**
 * Software Super Admin - Pricing & Subscription Plan Manager.
 *
 * Define the SaaS pricing tiers (Free Trial, Standard, Enterprise), toggle
 * their visibility, and assign a plan to any institution. The plan's price and
 * limits flow onto the institution, keeping revenue reporting in step.
 */
export default function Plans({ plans = [], institutions = [], totals = {} }) {
    const money = useMoney();
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);
    const [assigning, setAssigning] = useState(null);

    return (
        <SettingsLayout title="Pricing & Plans">
            <Head title="Pricing & Plans" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Pricing &amp; Subscription Plans</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Define the tiers you sell and assign them to institutions.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => { setEditing(null); setCreating(true); }}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        + New Plan
                    </button>
                </div>

                {/* Totals */}
                <div className="grid grid-cols-3 gap-4">
                    <Stat label="Active Plans" value={totals.plans ?? 0} />
                    <Stat label="Assigned Institutions" value={totals.assigned ?? 0} />
                    <Stat label="Plan MRR" value={money(totals.mrr ?? 0)} tone="emerald" />
                </div>

                {/* Plan cards */}
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <div key={plan.id} className="flex flex-col rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-bold text-slate-900">{plan.name}</h3>
                                    <p className="mt-0.5 text-xs text-slate-500">{plan.description}</p>
                                </div>
                                <div className="flex flex-col items-end gap-1">
                                    {plan.is_trial_default && (
                                        <span className="rounded-full border-sky-100 bg-sky-50 px-2 py-0.5 text-[10px] font-bold text-sky-700">
                                            Trial
                                        </span>
                                    )}
                                    {!plan.is_active && (
                                        <span className="rounded-full border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                                            Inactive
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="mt-4">
                                <span className="text-3xl font-extrabold text-slate-900">
                                    {plan.is_free ? 'Free' : money(plan.monthly_price, true)}
                                </span>
                                {!plan.is_free && <span className="text-sm text-slate-400">/mo</span>}
                            </div>

                            <ul className="mt-4 flex-1 space-y-2">
                                {(plan.features || []).map((f, i) => (
                                    <li key={i} className="flex items-start gap-2 text-xs text-slate-600">
                                        <svg className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                        </svg>
                                        {f}
                                    </li>
                                ))}
                                <li className="flex items-start gap-2 text-xs text-slate-400">
                                    Members: <strong className="font-semibold text-slate-600">{plan.member_limit_label}</strong>
                                </li>
                            </ul>

                            <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                                <span className="text-[11px] font-medium text-slate-400">
                                    {plan.institutions_count} institution{plan.institutions_count === 1 ? '' : 's'}
                                </span>
                                <div className="flex gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => { setCreating(false); setEditing(plan); }}
                                        className="rounded-lg border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAssigning(plan)}
                                        className="rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white transition-colors hover:bg-indigo-700"
                                    >
                                        Assign
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                    {plans.length === 0 && (
                        <div className="col-span-full rounded-2xl border-slate-200 bg-white py-14 text-center text-sm text-slate-400">
                            No plans yet. Create your first pricing tier.
                        </div>
                    )}
                </div>

                {/* Institutions table */}
                <section className="rounded-2xl border-slate-200/80 bg-white shadow-xs">
                    <div className="border-b border-slate-100 px-6 py-4">
                        <h3 className="text-base font-bold text-slate-900">Institutions &amp; Their Plans</h3>
                        <p className="mt-0.5 text-xs text-slate-500">Which tier each institution is currently on.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-slate-400">
                                    <th className="px-6 py-3 font-bold">Institution</th>
                                    <th className="px-4 py-3 font-bold">Plan</th>
                                    <th className="px-4 py-3 font-bold">Status</th>
                                    <th className="px-4 py-3 text-right font-bold">Monthly</th>
                                    <th className="px-6 py-3 text-right font-bold">Change</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {institutions.map((inst) => (
                                    <tr key={inst.id} className="hover:bg-slate-50/60">
                                        <td className="px-6 py-3 font-semibold text-slate-800">{inst.name}</td>
                                        <td className="px-4 py-3 capitalize text-slate-600">{inst.plan || '—'}</td>
                                        <td className="px-4 py-3 text-slate-600">{inst.status}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-700">{money(inst.amount, true)}</td>
                                        <td className="px-6 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => setAssigning({ __institution: inst })}
                                                className="rounded-lg border-slate-200 px-3 py-1 text-[11px] font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                            >
                                                Assign plan
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {institutions.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No institutions yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {(creating || editing) && (
                <PlanModal plan={editing} onClose={() => { setCreating(false); setEditing(null); }} />
            )}
            {assigning && (
                <AssignModal
                    target={assigning}
                    plans={plans}
                    onClose={() => setAssigning(null)}
                />
            )}
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ */

function Stat({ label, value, tone = 'slate' }) {
    const tones = { slate: 'text-slate-900', emerald: 'text-emerald-600' };

    return (
        <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
            <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
            <p className={`mt-1.5 text-2xl font-bold ${tones[tone]}`}>{value}</p>
        </div>
    );
}

function PlanModal({ plan, onClose }) {
    const isEdit = Boolean(plan);
    const { data, setData, post, put, processing, errors } = useForm({
        name: plan?.name || '',
        key: plan?.key || '',
        description: plan?.description || '',
        monthly_price: plan?.monthly_price ?? 0,
        is_free: plan?.is_free ?? false,
        is_trial_default: plan?.is_trial_default ?? false,
        member_limit: plan?.member_limit ?? -1,
        manager_limit: plan?.manager_limit ?? -1,
        features: plan?.features?.length ? plan.features : [''],
        sort_order: plan?.sort_order ?? 0,
        is_active: plan?.is_active ?? true,
        is_public: plan?.is_public ?? true,
    });

    const setFeature = (i, value) => {
        const next = [...data.features];
        next[i] = value;
        setData('features', next);
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (isEdit) {
            put(route('ssa.plans.update', plan.id), options);
        } else {
            post(route('ssa.plans.store'), options);
        }
    };

    return (
        <ModalShell title={isEdit ? `Edit ${plan.name}` : 'New Pricing Plan'} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Plan name" error={errors.name}>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Standard"
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>
                    <Field label={data.is_free ? 'Monthly price' : 'Monthly price *'} error={errors.monthly_price}>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.monthly_price}
                            disabled={data.is_free}
                            onChange={(e) => setData('monthly_price', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 disabled:bg-slate-50"
                        />
                    </Field>
                </div>

                {/*
                 * KEY FIELD RULE (edit vs create).
                 *
                 * On EDIT the plan key is a stable identifier institutions store
                 * as their plan, so it is shown but never re-generated or cleared
                 * - a blank submit keeps the existing key server-side. On CREATE
                 * the key is derived from the name automatically, so the operator
                 * never has to invent one.
                 */}
                {isEdit ? (
                    <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2 text-xs text-slate-500">
                        <span>Plan key (immutable)</span>
                        <code className="font-semibold text-slate-700">{plan.key}</code>
                    </div>
                ) : (
                    <p className="text-[11px] text-slate-400">
                        A unique plan key is generated from the name automatically.
                    </p>
                )}

                <Field label="Description" error={errors.description}>
                    <input
                        type="text"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="Short summary shown on the landing page"
                        className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Member limit (-1 = unlimited)" error={errors.member_limit}>
                        <input
                            type="number"
                            value={data.member_limit}
                            onChange={(e) => setData('member_limit', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>
                    <Field label="Manager limit (-1 = unlimited)" error={errors.manager_limit}>
                        <input
                            type="number"
                            value={data.manager_limit}
                            onChange={(e) => setData('manager_limit', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>
                </div>

                <Field label="Features">
                    <div className="space-y-2">
                        {data.features.map((f, i) => (
                            <input
                                key={i}
                                type="text"
                                value={f}
                                onChange={(e) => setFeature(i, e.target.value)}
                                placeholder="e.g. Vendor ledger"
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            />
                        ))}
                        <button
                            type="button"
                            onClick={() => setData('features', [...data.features, ''])}
                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800"
                        >
                            + Add feature
                        </button>
                    </div>
                </Field>

                <div className="flex flex-wrap gap-4">
                    <Toggle label="Free plan" checked={data.is_free} onChange={(v) => setData('is_free', v)} />
                    <Toggle label="Default trial" checked={data.is_trial_default} onChange={(v) => setData('is_trial_default', v)} />
                    <Toggle label="Active" checked={data.is_active} onChange={(v) => setData('is_active', v)} />
                    <Toggle label="Show on landing" checked={data.is_public} onChange={(v) => setData('is_public', v)} />
                </div>

                {Object.keys(errors).length > 0 && <p className="text-xs text-rose-500">Please review the values.</p>}

                <ModalActions onCancel={onClose} processing={processing} submitLabel={isEdit ? 'Save changes' : 'Create plan'} />
            </form>
        </ModalShell>
    );
}

function AssignModal({ target, plans, onClose }) {
    // `target` is either a plan (assign it to institutions) or an institution
    // (give it a plan). We support the institution case here for the table.
    const institution = target.__institution || null;
    const [institutionId, setInstitutionId] = useState(institution?.id || '');
    const { data, setData, post, processing, errors } = useForm({
        plan_id: target.__institution ? '' : target.id,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('ssa.plans.assign', institutionId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <ModalShell title={institution ? `Assign a plan to ${institution.name}` : `Assign "${target.name}"`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {target.__institution && (
                    <Field label="Institution">
                        <input
                            type="text"
                            value={institution.name}
                            readOnly
                            className="w-full rounded-xl border-slate-200 bg-slate-50 px-3.5 py-2 text-sm text-slate-600"
                        />
                    </Field>
                )}

                <Field label="Plan" error={errors.plan_id}>
                    <select
                        value={data.plan_id}
                        onChange={(e) => setData('plan_id', e.target.value)}
                        className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                    >
                        <option value="">Select a plan...</option>
                        {plans.map((p) => (
                            <option key={p.id} value={p.id}>{p.name} - {p.is_free ? 'Free' : p.monthly_price + '/mo'}</option>
                        ))}
                    </select>
                </Field>

                {Object.keys(errors).length > 0 && <p className="text-xs text-rose-500">Please choose a plan.</p>}

                <ModalActions onCancel={onClose} processing={processing} submitLabel="Assign plan" />
            </form>
        </ModalShell>
    );
}

/* ------------------------------------------------------------------ */

function ModalShell({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in">
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border-slate-100 bg-white p-6 shadow-xl animate-rise">
                <h3 className="text-base font-bold text-slate-900">{title}</h3>
                <div className="mt-5">{children}</div>
            </div>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-semibold text-slate-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-rose-500">{error}</p>}
        </div>
    );
}

function Toggle({ label, checked, onChange }) {
    return (
        <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-700">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            {label}
        </label>
    );
}

function ModalActions({ onCancel, processing, submitLabel }) {
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
                className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50"
            >
                {processing && <Spinner className="h-4 w-4" />}
                {processing ? 'Saving...' : submitLabel}
            </button>
        </div>
    );
}
