import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import { useCurrencySettings } from '@/Utils/useCurrency';
import formatNumber from '@/Utils/numberFormatter';
import Button from '@/Components/UI/Button';
import Input from '@/Components/UI/Input';
import Card from '@/Components/UI/Card';
import { Spinner } from '@/Components/UI/Loading';
import ThemePreviewFrame from '@/Components/ThemePreviewFrame';
import ThemedText from '@/Components/UI/ThemedText';
import useThemedText from '@/Utils/useThemedText';

/**
 * Currency Manager — the Settings → Currency screen ("Currency Settings").
 *
 * Inertia page component that edits the app-wide currency format (symbol,
 * placement, separators, precision and compact-number scale) with a live preview
 * alongside the form.
 *
 * PROPS
 *   - `auth`             : shared auth prop, forwarded to SettingsLayout.
 *   - `currencies`       : catalogue of selectable currencies ({ id, code, name,
 *                          symbol }) used to populate the dropdown and to resolve
 *                          a symbol from the chosen code.
 *   - `currencySettings` : the currently saved formatting options; falls back to
 *                          the shared `currency` prop / institution default.
 *   - `canManage`        : true only for a Software Super Admin — gates the form
 *                          (view-only for everyone else).
 *
 * Inertia / React concepts on show:
 *   - `useForm` is the form's isolated state, seeded from the server props.
 *   - `usePage().props` reads shared props (flash messages, institution, ...) from
 *     any depth, without prop-drilling.
 *   - `useMemo` derives ONE canonical settings object from the form + currency
 *     list, so the preview, the localStorage cache and the submitted payload can
 *     never drift apart.
 *   - `post(route('settings.currency.store'))` saves via Inertia; on success it
 *     also writes the shared client cache so every mounted module repaints with
 *     the new format before the next response arrives.
 */
export default function Settings({ auth, currencies, currencySettings, canManage }) {
    const { props } = usePage();
    // Theme-aware text classes, so the live preview adapts to dark mode + accent.
    const { tx } = useThemedText();
    const source = currencySettings || props?.currency || {};

    const { data, setData, post, processing } = useForm({
        currency_code: source.currency_code || props?.institution?.currency_code || '',
        symbol: source.symbol || '',
        symbol_position: source.symbol_position || 'before',
        decimal_separator: source.decimal_separator || '.',
        thousands_separator: source.thousands_separator || ',',
        decimal_precision: source.decimal_precision ?? 2,
        numbering_system: source.numbering_system || 'short',
        abbreviations: source.abbreviations ?? true,
        abbreviation_threshold: source.abbreviation_threshold ?? 1000,
    });

    const [, saveLocal] = useCurrencySettings();
    const [previewValue, setPreviewValue] = useState(1234567.89);

    // Build the canonical settings object once, so the live preview, the local
    // cache, and the saved payload can never drift from one another.
    const resolvedSettings = useMemo(() => {
        const symbol = data.symbol
            || currencies?.find((c) => c.code === data.currency_code)?.symbol
            || '৳';

        return {
            symbol,
            sign: symbol,
            position: data.symbol_position,
            symbol_position: data.symbol_position,
            decimal_separator: data.decimal_separator,
            thousands_separator: data.thousands_separator,
            decimal_precision: data.decimal_precision,
            numbering_system: data.numbering_system,
            abbreviations: data.abbreviations,
            abbreviation_threshold: Number(data.abbreviation_threshold) || 0,
            currency_code: data.currency_code,
        };
    }, [data, currencies]);

    // Keep the local cache in step so formatting is correct on the very next
    // paint, then the server share takes over permanently. Writing the FULL
    // object (not just symbol/position) is what makes the change propagate to
    // every already-mounted module the instant it is saved.
    useEffect(() => {
        try {
            localStorage.setItem('currency_settings', JSON.stringify(resolvedSettings));
        } catch (e) {}
    }, [resolvedSettings]);

    const handleSave = (e) => {
        e.preventDefault();
        post(route('settings.currency.store'), {
            preserveScroll: true,
            onSuccess: () => {
                // Push the full merged settings into the shared cache immediately
                // so every module re-renders with the new currency before the
                // next Inertia response lands. `reload` is not needed because
                // the server shares `currency` on every response.
                saveLocal(resolvedSettings);
            },
        });
    };

    const separatorsConflict = data.decimal_separator === data.thousands_separator;

    return (
        <SettingsLayout
            user={auth?.user}
            header={<h2 className="font-bold text-xl text-slate-900 tracking-tight">System Settings</h2>}
        >
            <Head title="Currency Settings" />

            <div className="py-8 px-6 max-w-7xl mx-auto space-y-6">
                {!canManage && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800">
                        Only a Software Super Admin can change global currency settings. You can view them here.
                    </div>
                )}
                {props?.flash?.success && (
                    <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800">
                        {props.flash.success}
                    </div>
                )}
                {props?.flash?.error && (
                    <div role="status" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-800">
                        {props.flash.error}
                    </div>
                )}

                <form onSubmit={handleSave} className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    
                    {/* Settings Input Form */}
                    <Card className="lg:col-span-7 p-6 sm:p-7 border border-slate-200/80 shadow-xs rounded-2xl bg-white space-y-7">
                        <div className="border-b border-slate-100 pb-4">
                            <h3 className="text-base font-bold text-slate-900">Currency & Formatting</h3>
                            <p className="mt-0.5 text-xs text-slate-500">Customize how numerical figures and transaction values appear across the app.</p>
                        </div>

                        {/* Section 1: Currency & Symbol */}
                        <div className="space-y-4">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">1. Currency Details</h4>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <label htmlFor="currency_code" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Active Currency
                                    </label>
                                    <select 
                                        id="currency_code" 
                                        value={data.currency_code} 
                                        disabled={!canManage}
                                        onChange={(e) => {
                                            const code = e.target.value;
                                            setData((prev) => ({
                                                ...prev,
                                                currency_code: code,
                                                symbol: currencies?.find((c) => c.code === code)?.symbol || prev.symbol,
                                            }));
                                        }}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all disabled:bg-slate-50"
                                    >
                                        <option value="">-- Select currency --</option>
                                        {currencies.map((c) => (
                                            <option key={c.id} value={c.code}>{`${c.name} (${c.code}) — ${c.symbol || ''}`}</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="symbol" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Currency Symbol
                                    </label>
                                    <input
                                        id="symbol"
                                        type="text"
                                        value={data.symbol}
                                        disabled={!canManage}
                                        maxLength={12}
                                        onChange={(e) => setData('symbol', e.target.value)}
                                        placeholder="e.g. ৳ / $ / € / ¥"
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all disabled:bg-slate-50"
                                    />
                                    <p className="mt-1.5 text-[11px] text-slate-400">
                                        Shown across every module, report and dashboard.
                                    </p>
                                </div>

                                <div>
                                    <label htmlFor="symbol_position" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Symbol Placement
                                    </label>
                                    <select
                                        id="symbol_position"
                                        value={data.symbol_position}
                                        onChange={(e) => setData('symbol_position', e.target.value)}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                    >
                                        <option value="before">Before ($100)</option>
                                        <option value="before_space">Before with space ($ 100)</option>
                                        <option value="after">After (100$)</option>
                                        <option value="after_space">After with space (100 $)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr className="border-slate-100" />

                        {/* Section 2: Separators & Precision */}
                        <div className="space-y-4">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">2. Separators & Precision</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label htmlFor="decimal_separator" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Decimal Separator
                                    </label>
                                    <select
                                        id="decimal_separator"
                                        value={data.decimal_separator}
                                        onChange={(e) => setData('decimal_separator', e.target.value)}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                    >
                                        <option value=".">Period (.)</option>
                                        <option value=",">Comma (,)</option>
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="thousands_separator" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Thousands Separator
                                    </label>
                                    <select
                                        id="thousands_separator"
                                        value={data.thousands_separator}
                                        onChange={(e) => setData('thousands_separator', e.target.value)}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                    >
                                        <option value=",">Comma (,)</option>
                                        <option value=".">Period (.)</option>
                                        <option value=" ">Space ( )</option>
                                        <option value="">None</option>
                                    </select>
                                </div>

                                <div>
                                    <Input
                                        id="decimal_precision"
                                        label="Decimal Precision"
                                        type="number"
                                        min={0}
                                        value={data.decimal_precision}
                                        onChange={(e) => setData('decimal_precision', Number(e.target.value))}
                                    />
                                </div>
                            </div>

                            {separatorsConflict && (
                                <div id="separator-error" role="alert" aria-invalid="true" className="p-3.5 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2.5 text-rose-700 text-xs font-medium mt-3">
                                    <svg className="w-4 h-4 shrink-0 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Decimal and thousands separators cannot be identical.
                                </div>
                            )}
                        </div>

                        <hr className="border-slate-100" />

                        {/* Section 3: Regional Scales */}
                        <div className="space-y-4">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-600">3. Scale & Display Options</h4>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                                <div>
                                    <label htmlFor="numbering_system" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Numbering Scale
                                    </label>
                                    <select
                                        id="numbering_system"
                                        value={data.numbering_system}
                                        onChange={(e) => setData('numbering_system', e.target.value)}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                    >
                                        <option value="short">Short scale (Million, Billion)</option>
                                        <option value="long">Long scale</option>
                                        <option value="indian">Indian (Lakh, Crore)</option>
                                        <option value="east_asian">East Asian (Wan, Yi)</option>
                                    </select>
                                </div>

                                <div className="flex items-center gap-3 pt-5 sm:pt-6">
                                    <input
                                        id="abbreviations"
                                        type="checkbox"
                                        checked={data.abbreviations}
                                        onChange={(e) => setData('abbreviations', e.target.checked)}
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 transition-colors"
                                    />
                                    <label htmlFor="abbreviations" className="text-xs font-semibold text-slate-700 select-none">
                                        Enable compact scale labels (e.g. 1.5 Mil)
                                    </label>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start pt-2">
                                <div>
                                    <label htmlFor="abbreviation_threshold" className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Abbreviation Threshold
                                    </label>
                                    <input
                                        id="abbreviation_threshold"
                                        type="number"
                                        min={0}
                                        step={100}
                                        value={data.abbreviation_threshold}
                                        disabled={!canManage || !data.abbreviations}
                                        onChange={(e) => setData('abbreviation_threshold', Number(e.target.value))}
                                        className="w-full border border-slate-200 rounded-xl shadow-2xs px-3.5 py-2 text-sm text-slate-900 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all disabled:bg-slate-50"
                                    />
                                    <p className="mt-1.5 text-[11px] text-slate-400">
                                        Numbers at or above this value are shown in compact scale. Below it they stay full. Set to 0 to always abbreviate.
                                    </p>
                                </div>
                                <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4 text-xs text-indigo-700 sm:mt-6">
                                    <strong className="font-semibold">Adaptive:</strong> figures below{' '}
                                    <strong className="font-semibold">{Number(data.abbreviation_threshold) >= 1000
                                        ? `${Number(data.abbreviation_threshold) / 1000}K`
                                        : Number(data.abbreviation_threshold)}</strong>{' '}
                                    render in full; at or above it they abbreviate automatically.
                                </div>
                            </div>
                        </div>

                        {/* Form Submit */}
                        <div className="pt-4 flex justify-end">
                            <Button type="submit" disabled={processing || separatorsConflict || !canManage} className="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold shadow-xs rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition-all">
                                {processing && <Spinner className="h-4 w-4" />}
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                        </div>
                    </Card>

                    {/* Sticky Live Preview */}
                    <Card className="lg:col-span-5 p-6 sm:p-7 border border-[var(--border-color)] shadow-xs rounded-2xl bg-[var(--surface)] sticky top-6 space-y-6 transition-colors duration-300">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                            <div>
                                <ThemedText as="h3" variant="heading" className="text-base">Live Formatting Preview</ThemedText>
                                <ThemedText as="p" variant="muted" className="mt-0.5 text-xs">Test how numbers appear in real-time</ThemedText>
                            </div>
                            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-[var(--accent-soft)] text-[var(--accent)] border border-[var(--accent-soft)]">
                                Real-time
                            </span>
                        </div>

                        <div>
                            <Input
                                id="preview_input"
                                label="Sample Number Input"
                                type="number"
                                value={previewValue}
                                onChange={(e) => setPreviewValue(Number(e.target.value))}
                            />
                        </div>

                        <div className="space-y-4">
                            <div className="p-4 bg-white rounded-xl border border-slate-200/80 shadow-2xs">
                                <ThemedText as="div" variant="overline">Standard Format</ThemedText>
                                <div className={tx('heading', 'mt-1 text-2xl tracking-tight')} role="status" aria-live="polite">
                                    {formatNumber(previewValue, resolvedSettings, currencies)}
                                </div>
                            </div>

                            <div className="p-4 bg-white rounded-xl border border-slate-200/80 shadow-2xs">
                                <ThemedText as="div" variant="overline">Abbreviated Output</ThemedText>
                                <div className={tx('accent', 'mt-1 text-2xl tracking-tight')} role="status" aria-live="polite">
                                    {data.abbreviations ? formatNumber(previewValue, { ...resolvedSettings, abbreviated: true }, currencies) : <span className="text-slate-400 font-normal text-base">— Disabled —</span>}
                                </div>
                            </div>
                        </div>

                        {/*
                         * THEME-REACTIVE PREVIEW.
                         *
                         * A miniature of a real card that reads the ACTIVE theme
                         * from the shared ThemeProvider context, so switching
                         * accent / light-dark / radius / font in the Theme
                         * Customizer repaints THIS block instantly - no reload.
                         */}
                        <ThemePreviewFrame
                            title="Theme-aware preview"
                            subtitle="Follows your live theme"
                            rows={[
                                { label: 'Standard', value: formatNumber(previewValue, resolvedSettings, currencies) },
                                { label: 'Abbreviated', value: data.abbreviations ? formatNumber(previewValue, { ...resolvedSettings, abbreviated: true }, currencies) : '—' },
                            ]}
                        />
                    </Card>

                </form>
            </div>
        </SettingsLayout>
    );
}