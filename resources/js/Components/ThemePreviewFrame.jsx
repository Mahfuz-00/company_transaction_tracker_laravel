import React from 'react';
import { useTheme, ACCENT_HEX, ACCENT_SOFT, RADIUS_PX, DENSITY_SCALE, FONT_STACKS } from '@/Components/ThemeProvider';
import ThemedText from '@/Components/UI/ThemedText';

/**
 * A live, theme-reactive preview frame.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Currency Manager and Institution Settings each render a small "preview"
 * panel. Those panels used to draw their own colours, so they only repainted on
 * a full reload and could drift from the active theme. This component instead
 * reads the ACTIVE theme from the shared ThemeProvider context, so any change in
 * the Theme Customizer (accent, light/dark, radius, font, density) repaints the
 * preview instantly - no refresh - because the context value changes and this
 * component re-renders.
 *
 * It is a faithful miniature of a real card: surface, border, primary/secondary
 * text, an accent button and an accent-tinted panel.
 */
export default function ThemePreviewFrame({
    title = 'Preview',
    subtitle = null,
    rows = [],
    children,
    className = '',
}) {
    const { theme } = useTheme();

    const isDark = (theme?.mode || 'light') === 'dark';
    const hex = ACCENT_HEX(theme?.accent);
    const soft = ACCENT_SOFT(theme?.accent);
    const radius = RADIUS_PX[theme?.radius] || RADIUS_PX.lg;
    const fontFamily = (FONT_STACKS[theme?.font] || FONT_STACKS.inter)?.stack;
    const fontSize = DENSITY_SCALE[theme?.density] || DENSITY_SCALE.comfortable;

    /*
     * The miniature palette, derived from the ACTIVE theme each render.
     *
     * These hex values are kept in step with the semantic tokens ThemeProvider
     * writes (--surface, --border-color, --text-primary, --text-muted) so this
     * self-contained frame NEVER shows hardcoded text on the wrong background -
     * the exact "preview text fails to adapt in dark mode" bug.
     */
    const palette = isDark
        ? { surface: '#111827', soft: '#1e293b', border: '#1f2937', text: '#f1f5f9', secondary: '#cbd5e1', muted: '#64748b' }
        : { surface: '#ffffff', soft: '#f8fafc', border: '#e2e8f0', text: '#0f172a', secondary: '#475569', muted: '#94a3b8' };

    return (
        <div
            className={`rounded-2xl p-5 transition-colors duration-300 ${className}`}
            style={{
                backgroundColor: palette.surface,
                border: `1px solid ${palette.border}`,
                borderRadius: radius,
                color: palette.text,
                fontFamily,
                fontSize,
            }}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                    {/* ThemedText variants resolve through the active theme, so the
                        preview's own text tracks dark mode + accent automatically. */}
                    <ThemedText as="p" variant="overline">{title}</ThemedText>
                    {subtitle && (
                        <ThemedText as="p" variant="muted" className="mt-0.5 text-[11px]">
                            {subtitle}
                        </ThemedText>
                    )}
                </div>
                <span
                    className="rounded-full px-2.5 py-0.5 text-[10px] font-bold text-white transition-colors"
                    style={{ backgroundColor: hex }}
                >
                    {isDark ? 'Dark' : 'Light'}
                </span>
            </div>

            {rows.length > 0 && (
                <div className="mt-4 space-y-2">
                    {rows.map((row, i) => (
                        <div
                            key={i}
                            className="flex items-center justify-between gap-3 rounded-lg px-3 py-2 transition-colors"
                            style={{ backgroundColor: palette.soft, borderRadius: radius }}
                        >
                            <ThemedText as="span" variant="muted" className="text-xs">{row.label}</ThemedText>
                            <ThemedText as="span" variant="heading" className="text-xs">{row.value}</ThemedText>
                        </div>
                    ))}
                </div>
            )}

            {children}

            {/* Accent primitives */}
            <div className="mt-4 flex items-center gap-2">
                <span
                    className="inline-flex items-center px-3.5 py-1.5 text-xs font-semibold text-white transition-colors"
                    style={{ backgroundColor: hex, borderRadius: radius }}
                >
                    Primary action
                </span>
                <span
                    className="inline-flex items-center px-3.5 py-1.5 text-xs font-semibold transition-colors"
                    style={{ border: `1px solid ${palette.border}`, color: palette.text, borderRadius: radius }}
                >
                    Secondary
                </span>
            </div>

            <div
                className="mt-3 px-3 py-2 text-[11px] font-medium transition-colors"
                style={{ backgroundColor: soft, color: hex, borderRadius: radius }}
            >
                Accent-tinted panel — follows your colour and radius choices.
            </div>
        </div>
    );
}
