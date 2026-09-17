import React, { useMemo, useState } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/UI/Field';
import useCan from '@/Utils/can';
import { Head, Link, useForm } from '@inertiajs/react';

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
        <div className="rounded-2xl border border-slate-200/80 bg-gradient-to-b from-white to-slate-50/50 p-5 shadow-xs">
            <div className="mb-4 flex items-center justify-between">
                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400">
                    Live Preview
                </h4>
                {effect && (
                    <span className="rounded-full border border-indigo-100 bg-indigo-50/80 px-2.5 py-0.5 text-[10px] font-semibold text-indigo-600">
                        {effect}
                    </span>
                )}
            </div>

            <ul className="space-y-2.5">
                {items.map((item) => (
                    <li key={item.label} className="flex items-center justify-between gap-3 text-xs">
                        <span className="text-slate-500">{item.label}</span>
                        <span className="truncate font-semibold text-slate-800">{item.value}</span>
                    </li>
                ))}
            </ul>

            <p className="mt-4 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
                Saved overrides apply across the sidebar, page headings, and reports immediately. Clear a field to fall back to the preset.
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Page
 * ------------------------------------------------------------------ */

export default function InstitutionSettings({ institution, types = [], termKeys = [], themes = [] }) {
    const { can } = useCan();
    const canManage = can('institution.manage');

    const { data, setData, post, processing, errors, clearErrors } = useForm({
        // Method spoofing - see submit() below for why we do not use put().
        _method: 'put',
        name: institution?.name || '',
        subtitle: institution?.subtitle || '',
        type: institution?.type || 'general_mess',
        timezone: institution?.timezone || 'UTC',
        address: institution?.address || '',
        contact_email: institution?.contact_email || '',
        contact_phone: institution?.contact_phone || '',
        terminology: institution?.terminology || {},
        // Theme customisation.
        theme: institution?.theme || { accent: 'indigo', mode: 'light', radius: 'lg', density: 'comfortable' },
        // Branding images (file inputs).
        logo: null,
        banner: null,
        remove_logo: false,
        remove_banner: false,
    });

    // Local preview URLs so a chosen image shows before it is uploaded.
    const [logoPreview, setLogoPreview] = useState(institution?.logo_url || null);
    const [bannerPreview, setBannerPreview] = useState(institution?.banner_url || null);

    const [showOverrides, setShowOverrides] = useState(false);

    // Preset terms for the currently selected type. Used for the preview and to
    // show what a blank override would fall back to.
    const presetTerms = useMemo(() => {
        const found = types.find((type) => type.value === data.type);
        return found?.terms || {};
    }, [types, data.type]);

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

        // WHY post() + _method instead of put():
        // The logo and banner ride along as files, so the request must be
        // multipart/form-data. PHP only populates $_POST (which Laravel reads)
        // for POST requests - it does NOT parse a multipart body on PUT or
        // PATCH. Sending a real PUT therefore delivered an empty payload, and
        // EVERY field failed "is required" even after the user filled it in
        // (the frustrating validation bug). POSTing with _method=put keeps the
        // multipart body intact AND still routes to the update method.
        post(route('settings.institution.update'), {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    const setTheme = (key, value) => setData('theme', { ...data.theme, [key]: value });

    const onLogoChange = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        setData('logo', file);
        setData('remove_logo', false);
        setLogoPreview(URL.createObjectURL(file));
    };

    const onBannerChange = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        setData('banner', file);
        setData('remove_banner', false);
        setBannerPreview(URL.createObjectURL(file));
    };

    return (
        <SettingsLayout title="Settings">
            <Head title="Institution Settings" />

            <form onSubmit={submit} className="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-start">
                {/* Main column */}
                <div className="space-y-6 lg:col-span-7">
                    {/* Identity */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Institution Identity</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                The name and contact details shown to members and in reports.
                            </p>
                        </div>

                        <div className="mt-5 space-y-4">
                            <Field
                                label="Institution Name"
                                name="name"
                                required
                                value={data.name}
                                error={errors.name}
                                placeholder="e.g. Main Campus Hall"
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

                            <Field
                                label="Subtitle"
                                name="subtitle"
                                value={data.subtitle}
                                error={errors.subtitle}
                                placeholder="e.g. Shared meals, tracked"
                                hint="Shown under the institution name in the sidebar and on the login screen."
                                onChange={(event) => setData('subtitle', event.target.value)}
                            />
                        </div>
                    </section>

                    {/* Type */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Institution Type</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Drives the vocabulary used throughout the app.
                            </p>
                        </div>

                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {types.map((type) => {
                                const active = data.type === type.value;

                                return (
                                    <button
                                        key={type.value}
                                        type="button"
                                        disabled={!canManage}
                                        onClick={() => handleTypeChange(type.value)}
                                        className={`rounded-xl border p-4 text-left transition-all disabled:opacity-60 ${
                                            active
                                                ? 'border-indigo-300 bg-indigo-50/40 ring-2 ring-indigo-500/10'
                                                : 'border-slate-200/80 bg-white hover:border-slate-300 hover:bg-slate-50/50'
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
                                        <p className="mt-3 text-[11px] font-medium text-indigo-600">
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

                    {/* Theme customiser */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Theme</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Colour and shape of this workspace. Applies instantly across the app.
                            </p>
                        </div>

                        {/* Accent swatches */}
                        <div className="mt-5">
                            <span className="mb-2.5 block text-xs font-semibold text-slate-700">Accent colour</span>
                            <div className="flex flex-wrap gap-3">
                                {themes.map((theme) => {
                                    const active = (data.theme?.accent || 'indigo') === theme.value;

                                    return (
                                        <button
                                            key={theme.value}
                                            type="button"
                                            disabled={!canManage}
                                            onClick={() => setTheme('accent', theme.value)}
                                            title={theme.label}
                                            aria-label={theme.label}
                                            className={`h-9 w-9 rounded-full border-2 transition-transform disabled:opacity-60 ${
                                                active ? 'scale-110 border-slate-900 shadow-sm' : 'border-transparent hover:scale-105'
                                            }`}
                                            style={{ backgroundColor: theme.hex }}
                                        />
                                    );
                                })}
                            </div>
                        </div>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Corner radius"
                                name="theme_radius"
                                type="select"
                                value={data.theme?.radius || 'lg'}
                                options={[
                                    { value: 'sm', label: 'Sharp' },
                                    { value: 'md', label: 'Slightly rounded' },
                                    { value: 'lg', label: 'Rounded' },
                                    { value: 'xl', label: 'Very rounded' },
                                ]}
                                onChange={(e) => setTheme('radius', e.target.value)}
                            />
                            <Field
                                label="Density"
                                name="theme_density"
                                type="select"
                                value={data.theme?.density || 'comfortable'}
                                options={[
                                    { value: 'compact', label: 'Compact' },
                                    { value: 'comfortable', label: 'Comfortable' },
                                    { value: 'spacious', label: 'Spacious' },
                                ]}
                                onChange={(e) => setTheme('density', e.target.value)}
                            />
                        </div>
                    </section>

                    {/* Branding images */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Branding</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Logo and banner shown in the sidebar and on the login screen.
                            </p>
                        </div>

                        <div className="mt-5 space-y-6">
                            {/* Logo */}
                            <div>
                                <span className="mb-2.5 block text-xs font-semibold text-slate-700">Institution logo</span>
                                <div className="flex items-center gap-4">
                                    {logoPreview ? (
                                        <img src={logoPreview} alt="Logo" className="h-14 w-14 flex-shrink-0 rounded-xl border border-slate-200 object-contain bg-slate-50 p-1" />
                                    ) : (
                                        <span className="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl border border-slate-200 border-dashed bg-slate-50 text-xs font-semibold text-slate-400">
                                            None
                                        </span>
                                    )}
                                    <div className="flex flex-col gap-2">
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                            disabled={!canManage}
                                            onChange={onLogoChange}
                                            className="block text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3.5 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800 disabled:opacity-60"
                                        />
                                        {logoPreview && canManage && (
                                            <button
                                                type="button"
                                                onClick={() => { setLogoPreview(null); setData('logo', null); setData('remove_logo', true); }}
                                                className="w-fit text-xs font-semibold text-rose-500 hover:text-rose-700"
                                            >
                                                Remove logo
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {errors.logo && <p className="mt-1 text-xs text-rose-500">{errors.logo}</p>}
                            </div>

                            {/* Banner */}
                            <div>
                                <span className="mb-2.5 block text-xs font-semibold text-slate-700">Login banner</span>
                                {bannerPreview ? (
                                    <img src={bannerPreview} alt="Banner" className="mb-3 h-28 w-full rounded-xl border border-slate-200 object-cover shadow-2xs" />
                                ) : (
                                    <div className="mb-3 flex h-28 w-full items-center justify-center rounded-xl border border-slate-200 border-dashed bg-slate-50 text-xs font-semibold text-slate-400">
                                        No banner uploaded
                                    </div>
                                )}
                                <div className="flex items-center justify-between">
                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        disabled={!canManage}
                                        onChange={onBannerChange}
                                        className="block text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3.5 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800 disabled:opacity-60"
                                    />
                                    {bannerPreview && canManage && (
                                        <button
                                            type="button"
                                            onClick={() => { setBannerPreview(null); setData('banner', null); setData('remove_banner', true); }}
                                            className="text-xs font-semibold text-rose-500 hover:text-rose-700"
                                        >
                                            Remove banner
                                        </button>
                                    )}
                                </div>
                                {errors.banner && <p className="mt-1 text-xs text-rose-500">{errors.banner}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Terminology overrides */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                            <div>
                                <h3 className="text-base font-bold text-slate-900">Custom Terminology</h3>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Optional. Overrides the preset for individual terms.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowOverrides((open) => !open)}
                                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
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
                                            className="mb-1.5 block text-xs font-semibold text-slate-700"
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
                                            className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-900 outline-none transition-all placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 disabled:bg-slate-50"
                                        />
                                    </div>
                                ))}
                            </div>
                        )}

                        {!showOverrides && overrideCount > 0 && (
                            <p className="mt-4 text-xs font-medium text-indigo-600">
                                {overrideCount} custom term{overrideCount === 1 ? '' : 's'} applied.
                            </p>
                        )}
                    </section>

                    {/* Locale / Regional (Currency removed, timezone only) */}
                    <section className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Regional</h3>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Configure system timezone settings.
                            </p>
                        </div>

                        <div className="mt-5 max-w-sm">
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
                <div className="lg:col-span-5 lg:sticky lg:top-6 space-y-4">
                    <TerminologyPreview
                        terms={effectiveTerms}
                        effect={overrideCount > 0 ? 'custom overrides' : 'preset'}
                    />

                    {canManage && (
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
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
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md rounded-2xl border border-slate-100 bg-white p-6 shadow-xl">
                        <h3 className="text-base font-bold text-slate-900">Keep custom terminology?</h3>
                        <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                            You have {overrideCount} custom term{overrideCount === 1 ? '' : 's'}. Switching
                            to <strong>{types.find((t) => t.value === pendingTypeChange)?.label}</strong> can
                            either keep those overrides or reset to the new preset.
                        </p>

                        <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                onClick={() => setPendingTypeChange(null)}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => confirmTypeChange(true)}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                            >
                                Use preset
                            </button>
                            <button
                                type="button"
                                onClick={() => confirmTypeChange(false)}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-700"
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