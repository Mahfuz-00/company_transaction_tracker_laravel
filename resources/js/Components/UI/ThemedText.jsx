import React from 'react';

/**
 * CENTRALISED THEME-AWARE TYPOGRAPHY.
 *
 * WHY THIS EXISTS
 * ---------------
 * Despite the dark-mode utility remap in app.css, text authored with a literal
 * Tailwind colour (`text-slate-700`, `text-gray-500`, ...) could still leak a
 * "dark on dark" / "bright on bright" label when a component paired two
 * utilities, used an arbitrary opacity variant the remap did not cover, or
 * rendered inside a self-contained preview frame (the Theme Customizer live
 * preview, the Currency Manager preview, the Institute Manager preview) that
 * drew its own palette.
 *
 * This component (and its matching hook) is the SINGLE place that maps a
 * semantic text ROLE onto the active theme's tokens. Every heading, subtitle,
 * body line and muted caption should be rendered through one of these variants,
 * so a theme (or accent / dark-mode) change repaints them all - no exceptions,
 * no per-component colour literals to chase.
 *
 *   <ThemedText variant="heading">Meal reports</ThemedText>
 *   <ThemedText variant="subtitle">This month at a glance</ThemedText>
 *   <ThemedText variant="muted" as="span">Updated 5m ago</ThemedText>
 *
 * VARIANTS map to the CSS custom properties written by ThemeProvider
 * (--text-primary / --text-secondary / --text-muted), so both the global `.dark`
 * flip and a live customizer preview update them instantly.
 */

/** Variant -> the utility classes that resolve to a theme token. */
const VARIANT_CLASSES = {
    // Headings + primary copy - the strongest contrast.
    heading: 'text-primary font-bold tracking-tight',
    // Large display / hero text.
    display: 'text-primary font-extrabold tracking-tight',
    // Default body copy.
    body: 'text-secondary',
    // Secondary line under a heading.
    subtitle: 'text-secondary',
    // Small labels / captions / hints - the lightest contrast.
    muted: 'text-muted',
    // A tiny uppercase section label.
    overline: 'text-muted font-semibold uppercase tracking-wider text-[11px]',
    // Inline link / accent text, follows the workspace accent.
    accent: 'text-[var(--accent)] font-semibold',
};

/** The default element per variant, so callers get sensible semantics for free. */
const VARIANT_TAG = {
    heading: 'h2',
    display: 'h1',
    body: 'p',
    subtitle: 'p',
    muted: 'span',
    overline: 'p',
    accent: 'span',
};

/** The class string for a variant (used by the hook and by the component). */
export function themedTextClass(variant = 'body', extra = '') {
    const base = VARIANT_CLASSES[variant] || VARIANT_CLASSES.body;
    return extra ? `${base} ${extra}` : base;
}

/**
 * A drop-in themed text element.
 *
 * @param {'display'|'heading'|'subtitle'|'body'|'muted'|'overline'|'accent'} variant
 * @param {string} as  override the element (span, div, dd, ...)
 */
export default function ThemedText({
    variant = 'body',
    as,
    className = '',
    children,
    ...rest
}) {
    const Tag = as || VARIANT_TAG[variant] || 'span';

    return (
        <Tag className={themedTextClass(variant, className)} {...rest}>
            {children}
        </Tag>
    );
}
