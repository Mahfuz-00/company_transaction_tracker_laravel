import React, { useEffect, useMemo } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Head, useForm } from '@inertiajs/react';
import { useTheme, ACCENT_SOFT, ACCENT_HEX, RADIUS_PX, DENSITY_SCALE } from '@/Components/ThemeProvider';
import ThemedText from '@/Components/UI/ThemedText';

/**
 * The dedicated Theme Customizer.
 *
 * Reachable by EVERY authenticated user (members included). The theme is a
 * PERSONAL preference, so it is stored per-account (database) and mirrored to
 * the browser (localStorage) - see ThemeProvider for the dual-persistence write.
 *
 * The live preview at the top is not a mock: it applies the in-progress choices
 * to the REAL document root via the shared `applyTheme` helper, so what the user
 * sees is exactly what they will get on save, with no guesswork.
 */
export default function ThemeCustomizer({ theme, accents = [], fonts = [], radiusOptions = [], densityOptions = [] }) {
    const { data, setData, put, post, processing, errors } = useForm({
        mode: theme?.mode || 'light',
        accent: theme?.accent || 'indigo',
        radius: theme?.radius || 'lg',
        density: theme?.density || 'comfortable',
        font: theme?.font || 'inter',
    });

    /*
     * LIVE PREVIEW - THE KEY FIX.
     *
     * The preview must repaint the ENTIRE app the instant a token changes, and
     * must fully REVERT if the user leaves without saving. Two problems used to
     * make it feel broken:
     *
     *   1. The preview was applied but never subscribed to: components that read
     *      theme values did not re-render, so only CSS-var-driven styles moved.
     *   2. Leaving the page left the half-tweaked preview applied, so the app
     *      looked broken until a hard refresh.
     *
     * The fix: push the in-progress tokens through the shared theme context
     * (applyPreview) so every consumer re-renders, and RESTORE the saved theme
     * on unmount. The `theme:change` event fired by applyThemeTokens additionally
     * lets any non-React listener react.
     */
    const { applyPreview, resetPreview } = useTheme();

    // Serialise the form so the effect only re-runs on a REAL change, not on
    // every render (setData produces a new object each time).
    const previewKey = JSON.stringify(data);

    useEffect(() => {
        applyPreview(data);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewKey, applyPreview]);

    // Revert the preview to the SAVED theme when the user navigates away without
    // saving, so an abandoned edit never leaves the app in a half-themed state.
    useEffect(() => {
        return () => {
            resetPreview();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submit = (e) => {
        e.preventDefault();
        put(route('settings.theme.update'));
    };

    const reset = () => {
        resetPreview();
        post(route('settings.theme.reset'));
    };

    const activeAccent = useMemo(
        () => accents.find((a) => a.value === data.accent) || accents[0],
        [accents, data.accent]
    );

    const activeFont = useMemo(
        () => fonts.find((f) => f.value === data.font) || fonts[0],
        [fonts, data.font]
    );

    return (
        <SettingsLayout title="Settings">
            <Head title="Theme Customizer" />

            <form onSubmit={submit} className="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-start">
                {/* ---- Controls ---- */}
                <div className="space-y-6 lg:col-span-7">
                    {/* Light / Dark */}
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <SectionHead
                            title="Mode"
                            subtitle="Switch the whole platform between light and dark. Your choice follows your account."
                        />
                        <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:max-w-md">
                            <ModeCard
                                label="Light"
                                active={data.mode === 'light'}
                                onClick={() => setData('mode', 'light')}
                                swatch="from-white to-slate-100 border-slate-200"
                                textTone="text-slate-800"
                            />
                            <ModeCard
                                label="Dark"
                                active={data.mode === 'dark'}
                                onClick={() => setData('mode', 'dark')}
                                swatch="from-slate-800 to-slate-950 border-slate-700"
                                textTone="text-white"
                            />
                        </div>
                    </section>

                    {/* Accent */}
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <SectionHead title="Accent colour" subtitle="Primary buttons, links and active navigation." />
                        <div className="mt-5 flex flex-wrap gap-3">
                            {accents.map((a) => {
                                const active = data.accent === a.value;
                                return (
                                    <button
                                        key={a.value}
                                        type="button"
                                        onClick={() => setData('accent', a.value)}
                                        title={a.label}
                                        aria-label={a.label}
                                        aria-pressed={active}
                                        className={`h-10 w-10 rounded-full border-2 transition-transform ${active ? 'scale-110 border-slate-900 shadow-sm' : 'border-transparent hover:scale-105'
                                            }`}
                                        style={{ backgroundColor: a.hex }}
                                    />
                                );
                            })}
                        </div>
                        <p className="mt-3 text-xs font-medium text-slate-500">
                            Selected: <span className="font-semibold text-slate-700">{activeAccent?.label}</span>
                        </p>
                    </section>

                    {/* Font family */}
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <SectionHead title="Font family" subtitle="The typeface used across headings and body text." />
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {fonts.map((f) => {
                                const active = data.font === f.value;
                                return (
                                    <button
                                        key={f.value}
                                        type="button"
                                        onClick={() => setData('font', f.value)}
                                        className={`rounded-xl border p-4 text-left transition-all ${active
                                                ? 'border-indigo-300 bg-indigo-50/40 ring-2 ring-indigo-500/10'
                                                : 'border-slate-200/80 bg-white hover:border-slate-300 hover:bg-slate-50/50'
                                            }`}
                                        style={{ fontFamily: f.stack }}
                                    >
                                        <span className="flex items-center justify-between">
                                            <span className="text-sm font-bold text-slate-900">{f.label}</span>
                                            {active && (
                                                <svg className="h-4 w-4 flex-shrink-0 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                                </svg>
                                            )}
                                        </span>
                                        <span className="mt-1 block text-xs text-slate-500">Aa Bb Cc 123</span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    {/* Radius + density */}
                    <section className="rounded-2xl border-slate-200/80 bg-white p-6 shadow-xs sm:p-7">
                        <SectionHead title="Shape &amp; density" subtitle="Corner rounding and how tightly content is packed." />
                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <span className="mb-2.5 block text-xs font-semibold text-slate-700">Corner radius</span>
                                <div className="grid grid-cols-2 gap-2">
                                    {radiusOptions.map((r) => (
                                        <PillButton
                                            key={r.value}
                                            active={data.radius === r.value}
                                            onClick={() => setData('radius', r.value)}
                                        >
                                            {r.label}
                                        </PillButton>
                                    ))}
                                </div>
                            </div>
                            <div>
                                <span className="mb-2.5 block text-xs font-semibold text-slate-700">Layout density</span>
                                <div className="grid grid-cols-1 gap-2">
                                    {densityOptions.map((d) => (
                                        <PillButton
                                            key={d.value}
                                            active={data.density === d.value}
                                            onClick={() => setData('density', d.value)}
                                        >
                                            {d.label}
                                        </PillButton>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </section>

                    {Object.keys(errors).length > 0 && (
                        <p className="text-xs text-rose-500">Please review your selections and try again.</p>
                    )}
                </div>

                {/* ---- Sticky preview + actions ---- */}
                <div className="space-y-4 lg:col-span-5 lg:sticky lg:top-6">
                    <div className="rounded-2xl border-slate-200/80 bg-white p-5 shadow-xs">
                        <ThemedText as="h4" variant="overline" className="mb-4">Live Preview</ThemedText>

                        {/* Built from the SAME resolved values the rest of the app
                            uses (mode palette, accent, radius, font, density), so
                            it moves in lockstep with the real UI. */}
                        <div
                            className="rounded-xl border p-4 transition-colors"
                            style={{
                                borderRadius: RADIUS_PX[data.radius] || RADIUS_PX.lg,
                                fontFamily: activeFont?.stack,
                                fontSize: DENSITY_SCALE[data.density] || DENSITY_SCALE.comfortable,
                                background: data.mode === 'dark' ? '#0f172a' : '#ffffff',
                                color: data.mode === 'dark' ? '#e2e8f0' : '#0f172a',
                                borderColor: data.mode === 'dark' ? '#1e293b' : '#e2e8f0',
                            }}
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-bold">Meal Summary</span>
                                <span
                                    className="rounded-full px-2.5 py-0.5 text-[10px] font-bold text-white"
                                    style={{ backgroundColor: ACCENT_HEX(data.accent) }}
                                >
                                    Active
                                </span>
                            </div>

                            <p className="mt-2 text-xs opacity-70">This month, 42 meals recorded across 3 departments.</p>

                            <div className="mt-4 flex items-center gap-2">
                                <span
                                    className="rounded-lg px-3.5 py-1.5 text-xs font-semibold text-white"
                                    style={{
                                        backgroundColor: ACCENT_HEX(data.accent),
                                        borderRadius: RADIUS_PX[data.radius] || RADIUS_PX.lg,
                                    }}
                                >
                                    Record Deposit
                                </span>
                                <span
                                    className="rounded-lg border px-3.5 py-1.5 text-xs font-semibold"
                                    style={{
                                        borderColor: data.mode === 'dark' ? '#334155' : '#e2e8f0',
                                        borderRadius: RADIUS_PX[data.radius] || RADIUS_PX.lg,
                                    }}
                                >
                                    View Report
                                </span>
                            </div>

                            <div
                                className="mt-4 rounded-lg p-3 text-[11px]"
                                style={{ backgroundColor: ACCENT_SOFT(data.accent), color: ACCENT_HEX(data.accent) }}
                            >
                                Accent-tinted panel - follows your colour choice.
                            </div>
                        </div>

                        <ThemedText as="p" variant="muted" className="mt-4 border-t border-slate-100 pt-3 text-[11px] leading-relaxed">
                            These changes apply to the WHOLE app instantly as you edit - look at the
                            sidebar and cards around this panel. Save to keep them on every device.
                        </ThemedText>
                    </div>

                    <div className="flex flex-col gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : 'Save My Theme'}
                        </button>
                        <button
                            type="button"
                            onClick={reset}
                            className="inline-flex w-full items-center justify-center rounded-xl border-slate-200 px-6 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Reset to default
                        </button>
                    </div>
                </div>
            </form>
        </SettingsLayout>
    );
}

/* ------------------------------------------------------------------ *
 * Small presentational helpers
 * ------------------------------------------------------------------ */

function SectionHead({ title, subtitle }) {
    return (
        <div className="border-b border-slate-100 pb-4">
            <ThemedText as="h3" variant="heading" className="text-base">{title}</ThemedText>
            <ThemedText as="p" variant="muted" className="mt-0.5 text-xs">{subtitle}</ThemedText>
        </div>
    );
}

function ModeCard({ label, active, onClick, swatch, textTone }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`overflow-hidden rounded-xl border p-3 text-left transition-all ${active ? 'border-indigo-300 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300'
                }`}
        >
            <span className={`flex h-14 items-end justify-start rounded-lg border bg-gradient-to-b p-2 ${swatch}`}>
                <span className={`text-xs font-bold ${textTone}`}>{label}</span>
            </span>
            <span className="mt-2 flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-700">{label} mode</span>
                {active && (
                    <svg className="h-3.5 w-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                )}
            </span>
        </button>
    );
}

function PillButton({ active, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`rounded-xl border px-3 py-2 text-xs font-semibold transition-colors ${active
                    ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                    : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                }`}
        >
            {children}
        </button>
    );
}
