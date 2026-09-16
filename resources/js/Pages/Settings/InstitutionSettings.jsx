import React, { useEffect, useMemo, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import { Head, router, useForm, usePage } from '@inertiajs/react';

/* ------------------------------------------------------------------ *
 * Live preview of how terminology lands across the app
 * ------------------------------------------------------------------ */

function TerminologyPreview({ terms, effect }) {
    const items = [
        { label: 'Sidebar links', value: `/meals/students → ${terms.members || '—'}` },
        { label: 'Group heading', value: terms.departments || '—' },
        { label: 'Money in', value: `Record ${terms.deposit || '—'}` },
        { label: 'Manager title', value: terms.meal_manager || '—' },
        { label: 'Institution noun', value: terms.institution || '—' },
    ];

    return (
        <div className="rounded-xl border-slate-200 bg-slate-50/60 p-4">
            <div className="mb-3 flex items-center justify-between">
                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                    Live Preview
                </h4>
                {effect && (
                    <span className="rounded-full border-indigo-100 bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-600">
                        {effect}
                    </span>
                )}
            </div>

            <ul className="space-y-2">
                {items.map((item) => (
                    <li key={item.label} className="flex items-center justify-between gap-3 text-xs">
                        <span className="text-slate-500">{item.label}</span>
                        <span className="truncate font-semibold text-slate-800">{item.value}</span>
                    </li>
                ))}
            </ul>

            <p className="mt-3 border-t border-slate-200 pt-3 text-[11px] leading-relaxed text-slate-500">
                Saved overrides apply across the sidebar, page headings, and reports
                immediately. Clear a field to fall back to the preset.
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function InstitutionSettings({ institution, types = [], termKeys = [] }) {
    const { can } = useCan();
    const { flash } = usePage().props;
    const canManage = can('institution.manage');

    const { data, setData, put, processing, errors, clearErrors } = useForm({
        name: institution?.name || '',
        type: institution?.type || 'general_mess',
        currency_code: institution?.currency_code || '',
        timezone: institution?.timezone || 'UTC',
        address: institution?.address || '',
        contact_email: institution?.contact_email || '',
        contact_phone: institution?.contact_phone || '',
        terminology: institution?.terminology || {},
    });

    const [showOverrides, setShowOverrides] = useState(false);

    // Preset terms for the currently selected type. Used for the preview and to
    // show what a blank override would fall back to.
    const presetTerms = useMemo(() => {
        const found = types.find((type) => type.value === data.type);
        return found?.terms || {};
    }, [types, data.type]);

    const selectedType = types.find((type) => type.value === data.type);

    // Effective = preset overlaid with explicit overrides, matching the backend.
    const effectiveTerms = useMemo(() => {
        const overrides = Object.fromEntries(
            Object.entries(data.terminology || {}).filter(([, value]) => value && value.trim() !== '')
        );
        return { ...presetTerms, ...overrides };
    }, [presetTerms, data.terminology]);

    const overrideCount = Object.values(data.terminology || {}).filter(
        (value) => value && value.trim() !== ''
    ).length;

    // Reassure the user rather than silently discarding overrides on type change.
    const [pendingTypeChange, setPendingTypeChange] = useState(null);
    const typeChanged = institution?.type && institution.type !== data.type;

    const handleTypeChange = (nextType) => {
        if (overrideCount > 0) {
            setPendingTypeChange(nextType);
            return;
        }
        setData('type', nextType);
    };

    const confirmTypeChange = (clearOverrides) => {
        if (pendingTypeChange) {
            setData('type', pendingTypeChange);
            if (clearOverrides) {
                setData('terminology', {});
            }
        }
        setPendingTypeChange(null);
    };

    const setTerm = (key, value) => {
        setData('terminology', { ...(data.terminology || {}), [key]: value });
    };

    const submit = (event) => {
        event.preventDefault();
        clearErrors();
        put(route('settings.institution.update'), { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Institution Settings" />

            {flash?.success && (
                <div
                    role="status"
                    className="mb-4 flex items-center gap-2 rounded-lg border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700"
                >
                    <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                    </svg>
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div
                    role="status"
                    className="mb-4 flex items-center gap-2 rounded-lg border-rose-200 bg-rose-50 p-3 text-sm font-medium text-rose-700"
                >
                    {flash.error}
                </div>
            )}

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">
                {/* Main column */}
                <div className="space-y-6 lg:col-span-7">
                    {/* Identity */}
                    <section className="rounded-xl border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900">Institution Identity</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            The name and contact details shown to members and in reports.
                        </p>

                        <div className="mt-5 space-y-4">
                            <Field
                                label="Institution Name"
                                name="name"
                                required
                                value={data.name}
                                error={errors.name}
                                placeholder="e.g. Main Campus Dorm"
                                onChange={(event) => setData('name', event.target.value)}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Contact Email"
                                    name="contact_email"
                                    type="email"
                                    value={data.contact_email}
                                    error={errors.contact_email}
                                    placeholder="admin@example.com"
                                    onChange={(event) => setData('contact_email', event.target.value)}
                                />
                                <Field
                                    label="Contact Phone"
                                    name="contact_phone"
                                    value={data.contact_phone}
                                    error={errors.contact_phone}
                                    placeholder="+8801XXXXXXXXX"
                                    onChange={(event) => setData('contact_phone', event.target.value)}
                                />
                            </div>

                            <Field
                                label="Address"
                                name="address"
                                value={data.address}
                                error={errors.address}
                                placeholder="Street, city"
                                onChange={(event) => setData('address', event.target.value)}
                            />
                        </div>
                    </section>

                    {/* Type */}
                    <section className="rounded-xl border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900">Institution Type</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Drives the vocabulary used throughout the app.
                        </p>

                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {types.map((type) => {
                                const active = data.type === type.value;

                                return (
                                    <button
                                        key={type.value}
                                        type="button"
                                        disabled={!canManage}
                                        onClick={() => handleTypeChange(type.value)}
                                        className={`rounded-xl border p-4 text-left transition-all disabled:opacity-60 ${active
                                                ? 'border-indigo-300 bg-indigo-50/60 ring-1 ring-indigo-200'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                            }`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="text-sm font-bold text-slate-900">
                                                {type.label}
                                            </span>
                                            {active && (
                                                <svg className="h-4 w-4 flex-shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                                </svg>
                                            )}
                                        </div>
                                        <p className="mt-1 text-xs leading-relaxed text-slate-500">
                                            {type.description}
                                        </p>
                                        <p className="mt-2 text-[11px] font-medium text-indigo-600">
                                            {type.terms.members} · {type.terms.deposits}
                                        </p>
                                    </button>
                                );
                            })}
                        </div>

                        {typeChanged && (
                            <p className="mt-3 text-xs font-medium text-amber-600">
                                Changing type will re-label the app once saved.
                            </p>
                        )}
                        {errors.type && (
                            <p role="alert" className="mt-2 text-xs text-rose-500">{errors.type}</p>
                        )}
                    </section>

                    {/* Terminology overrides */}
                    <section className="rounded-xl border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">Custom Terminology</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Optional. Overrides the preset for individual terms.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowOverrides((open) => !open)}
                                className="rounded-lg border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                            >
                                {showOverrides ? 'Hide' : 'Show'} {overrideCount > 0 && `(${overrideCount})`}
                            </button>
                        </div>

                        {showOverrides && (
                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                {termKeys.map((term) => (
                                    <div key={term.key}>
                                        <label
                                            htmlFor={`term-${term.key}`}
                                            className="mb-1.5 block text-xs font-semibold text-slate-600"
                                        >
                                            {term.label}
                                        </label>
                                        <input
                                            id={`term-${term.key}`}
                                            type="text"
                                            disabled={!canManage}
                                            value={data.terminology?.[term.key] || ''}
                                            onChange={(event) => setTerm(term.key, event.target.value)}
                                            placeholder={presetTerms[term.key] || ''}
                                            className="w-full rounded-lg border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition-all placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 disabled:bg-slate-50"
                                        />
                                    </div>
                                ))}
                            </div>
                        )}

                        {!showOverrides && overrideCount > 0 && (
                            <p className="mt-3 text-xs text-slate-500">
                                {overrideCount} custom term{overrideCount === 1 ? '' : 's'} applied.
                            </p>
                        )}
                    </section>

                    {/* Locale */}
                    <section className="rounded-xl border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900">Regional</h3>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Defaults used for formatting and date display.
                        </p>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Currency Code"
                                name="currency_code"
                                value={data.currency_code}
                                error={errors.currency_code}
                                placeholder="e.g. BDT"
                                hint="Managed in detail under Currency Manager."
                                onChange={(event) => setData('currency_code', event.target.value)}
                            />
                            <Field
                                label="Timezone"
                                name="timezone"
                                value={data.timezone}
                                error={errors.timezone}
                                placeholder="Asia/Dhaka"
                                onChange={(event) => setData('timezone', event.target.value)}
                            />
                        </div>
                    </section>
                </div>

                {/* Sticky preview column */}
                <div className="lg:col-span-5 lg:sticky lg:top-6">
                    <TerminologyPreview
                        terms={effectiveTerms}
                        effect={overrideCount > 0 ? 'custom overrides' : 'preset'}
                    />

                    {canManage && (
                        <button
                            type="submit"
                            disabled={processing}
                            className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing && (
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
                                </svg>
                            )}
                            {processing ? 'Saving...' : 'Save Institution Settings'}
                        </button>
                    )}
                </div>
            </form>

            {/* Type-change confirmation */}
            {pendingTypeChange && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md rounded-2xl border-slate-100 bg-white p-6 shadow-xl">
                        <h3 className="text-base font-bold text-slate-900">Keep custom terminology?</h3>
                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                            You have {overrideCount} custom term{overrideCount === 1 ? '' : 's'}. Switching
                            to <strong>{types.find((t) => t.value === pendingTypeChange)?.label}</strong> can
                            either keep those overrides or reset to the new preset.
                        </p>

                        <div className="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                onClick={() => setPendingTypeChange(null)}
                                className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => confirmTypeChange(true)}
                                className="rounded-lg border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100"
                            >
                                Use preset
                            </button>
                            <button
                                type="button"
                                onClick={() => confirmTypeChange(false)}
                                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700"
                            >
                                Keep mine
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}
