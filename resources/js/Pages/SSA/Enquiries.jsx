import React, { useMemo, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';

/**
 * Software Super Admin - Landing Enquiries / Demo Requests.
 *
 * Each public demo request is an actionable lead. The SSA can APPROVE one to
 * provision the institution immediately (on a 7-day trial by default, or a
 * chosen plan), send the welcome email, or mark it contacted / rejected.
 */
export default function Enquiries({
    enquiries = [],
    filter = '',
    stats = {},
    statuses = [],
    plans = [],
    trialDays = 7,
    institutionTypes = [],
}) {
    const [approving, setApproving] = useState(null);
    const [rejecting, setRejecting] = useState(null);

    const toneClass = {
        sky: 'bg-sky-50 text-sky-700 border-sky-100',
        amber: 'bg-amber-50 text-amber-700 border-amber-100',
        emerald: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        slate: 'bg-slate-100 text-slate-600 border-slate-200',
    };

    const tabs = useMemo(() => ([
        { value: '', label: 'All', count: stats.total },
        { value: 'new', label: 'New', count: stats.new },
        { value: 'contacted', label: 'Contacted', count: stats.contacted },
        { value: 'approved', label: 'Approved', count: stats.approved },
        { value: 'rejected', label: 'Rejected', count: stats.rejected },
    ]), [stats]);

    const setTab = (value) => {
        router.get(route('ssa.enquiries.index'), value ? { status: value } : {}, { preserveState: true, replace: true });
    };

    const contact = (id) => router.post(route('ssa.enquiries.contact', id), {}, { preserveScroll: true });

    return (
        <SettingsLayout title="Landing Enquiries">
            <Head title="Landing Enquiries" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-6 text-white shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-300">
                            Software Super Admin
                        </p>
                        <h1 className="mt-1 text-xl font-bold">Landing Enquiries &amp; Demo Requests</h1>
                        <p className="mt-1 text-xs text-slate-300">
                            Approve a request to provision the institution on a {trialDays}-day trial instantly.
                        </p>
                    </div>
                    <Link
                        href={route('ssa.dashboard')}
                        className="inline-flex flex-shrink-0 items-center justify-center rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white ring-1 ring-white/20 transition-colors hover:bg-white/20"
                    >
                        Back to Dashboard
                    </Link>
                </div>

                {/* Tabs */}
                <div className="flex flex-wrap gap-2">
                    {tabs.map((t) => (
                        <button
                            key={t.value || 'all'}
                            type="button"
                            onClick={() => setTab(t.value)}
                            className={`rounded-xl border px-3.5 py-1.5 text-xs font-semibold transition-colors ${filter === t.value
                                    ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                    : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                }`}
                        >
                            {t.label}{typeof t.count === 'number' ? ` (${t.count})` : ''}
                        </button>
                    ))}
                </div>

                {/* Enquiry cards */}
                <div className="space-y-4">
                    {enquiries.map((e) => (
                        <div key={e.id} className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-base font-bold text-slate-900">{e.institution_name || e.name}</span>
                                        <span className={`rounded-full border px-2.5 py-0.5 text-[10px] font-bold ${toneClass[e.status_tone] || toneClass.slate}`}>
                                            {e.status_label}
                                        </span>
                                    </div>

                                    <div className="mt-1.5 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                        <span className="font-medium text-slate-700">{e.name}</span>
                                        <a href={`mailto:${e.email}`} className="hover:text-indigo-600">{e.email}</a>
                                        {e.institution_type && <span className="capitalize">· {e.institution_type}</span>}
                                        <span>· {e.created_human}</span>
                                    </div>

                                    {e.message && (
                                        <p className="mt-2.5 rounded-lg bg-slate-50 px-3.5 py-2.5 text-xs leading-relaxed text-slate-600">
                                            {e.message}
                                        </p>
                                    )}

                                    {e.institution && (
                                        <p className="mt-2 text-[11px] font-medium text-emerald-600">
                                            Provisioned: {e.institution.name}
                                        </p>
                                    )}
                                    {e.review_notes && (
                                        <p className="mt-1 text-[11px] text-slate-400">Note: {e.review_notes}</p>
                                    )}
                                </div>

                                <div className="flex flex-shrink-0 flex-wrap items-center gap-2">
                                    {e.status !== 'approved' && (
                                        <button
                                            type="button"
                                            onClick={() => setApproving(e)}
                                            className="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                        >
                                            Approve &amp; Provision
                                        </button>
                                    )}
                                    {e.status === 'new' && (
                                        <button
                                            type="button"
                                            onClick={() => contact(e.id)}
                                            className="rounded-lg border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-100"
                                        >
                                            Mark contacted
                                        </button>
                                    )}
                                    {e.status !== 'rejected' && e.status !== 'approved' && (
                                        <button
                                            type="button"
                                            onClick={() => setRejecting(e)}
                                            className="rounded-lg border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-100"
                                        >
                                            Reject
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))}

                    {enquiries.length === 0 && (
                        <div className="rounded-2xl border-slate-200 bg-white py-16 text-center text-sm text-slate-400">
                            No enquiries{filter ? ' in this filter' : ''} yet.
                        </div>
                    )}
                </div>
            </div>

            {approving && (
                <ApproveModal
                    enquiry={approving}
                    plans={plans}
                    trialDays={trialDays}
                    institutionTypes={institutionTypes}
                    onClose={() => setApproving(null)}
                />
            )}
            {rejecting && <RejectModal enquiry={rejecting} onClose={() => setRejecting(null)} />}
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ *
 * Approve & provision modal
 * ------------------------------------------------------------------ */

function ApproveModal({ enquiry, plans, trialDays, institutionTypes, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        name: enquiry.institution_name || `${enquiry.name}'s Institution`,
        type: 'general_mess',
        provision_mode: 'trial',
        plan_id: '',
        trial_days: trialDays,
        admin_name: enquiry.name,
        admin_password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('ssa.enquiries.approve', enquiry.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border-slate-100 bg-white p-6 shadow-xl">
                <h3 className="text-base font-bold text-slate-900">Provision institution</h3>
                <p className="mt-0.5 text-xs text-slate-500">
                    From the enquiry by {enquiry.name} ({enquiry.email})
                </p>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <Field label="Institution name" error={errors.name}>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>

                    <Field label="Institution type" error={errors.type}>
                        <select
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        >
                            {institutionTypes.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Provision on">
                        <div className="grid grid-cols-2 gap-2">
                            {[
                                { value: 'trial', label: `${trialDays}-Day Free Trial` },
                                { value: 'plan', label: 'A subscription plan' },
                            ].map((o) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => setData('provision_mode', o.value)}
                                    className={`rounded-xl border px-3 py-2 text-left text-xs font-semibold transition-all ${data.provision_mode === o.value
                                            ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                            : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                        }`}
                                >
                                    {o.label}
                                </button>
                            ))}
                        </div>
                    </Field>

                    {data.provision_mode === 'trial' ? (
                        <Field label="Trial length (days)" error={errors.trial_days}>
                            <input
                                type="number"
                                min="1"
                                max="90"
                                value={data.trial_days}
                                onChange={(e) => setData('trial_days', e.target.value)}
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            />
                        </Field>
                    ) : (
                        <Field label="Plan" error={errors.plan_id}>
                            <select
                                value={data.plan_id}
                                onChange={(e) => setData('plan_id', e.target.value)}
                                className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                            >
                                <option value="">Select a plan...</option>
                                {plans.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} - {p.is_free ? 'Free' : `${p.monthly_price}/mo`}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}

                    <div className="rounded-xl border-slate-200 bg-slate-50 p-3.5">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Institution Admin</p>
                        <div className="mt-3 space-y-3">
                            <Field label="Admin name" error={errors.admin_name}>
                                <input
                                    type="text"
                                    value={data.admin_name}
                                    onChange={(e) => setData('admin_name', e.target.value)}
                                    className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                                />
                            </Field>
                            <Field label="Temporary password (optional)" error={errors.admin_password}>
                                <input
                                    type="text"
                                    value={data.admin_password}
                                    onChange={(e) => setData('admin_password', e.target.value)}
                                    placeholder="Auto-generated if left blank"
                                    className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                                />
                            </Field>
                        </div>
                    </div>

                    {Object.keys(errors).length > 0 && (
                        <p className="text-xs text-rose-500">Please review the values above.</p>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-xl border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {processing ? 'Provisioning...' : 'Approve & Provision'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function RejectModal({ enquiry, onClose }) {
    const { data, setData, post, processing } = useForm({ review_notes: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('ssa.enquiries.reject', enquiry.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
            <div className="w-full max-w-md rounded-2xl border-slate-100 bg-white p-6 shadow-xl">
                <h3 className="text-base font-bold text-slate-900">Reject enquiry</h3>
                <p className="mt-0.5 text-xs text-slate-500">{enquiry.name} ({enquiry.email})</p>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <Field label="Reason (optional)">
                        <textarea
                            rows={3}
                            value={data.review_notes}
                            onChange={(e) => setData('review_notes', e.target.value)}
                            className="w-full rounded-xl border-slate-200 px-3.5 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10"
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-xl border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-rose-700 disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : 'Reject'}
                        </button>
                    </div>
                </form>
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
