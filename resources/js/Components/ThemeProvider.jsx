import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Applies the workspace theme as CSS custom properties on :root.
 *
 * Every component reads colours through `var(--accent)` / `var(--accent-soft)`
 * rather than a hard-coded Tailwind colour, so changing the institution's
 * accent in Settings repaints the whole app instantly - and the values are
 * already on the page from the very first server render, so there is no flash
 * of the default colour.
 *
 * The provider renders its children untouched; it is purely a side-effect.
 */
export default function ThemeProvider({ children }) {
    const { institution } = usePage().props;
    const theme = institution?.theme || {};
    const accent = institution?.accent || {};

    const accentHex = accent.hex || '#4f46e5';
    const accentSoft = accent.soft || '#eef2ff';

    useEffect(() => {
        const root = document.documentElement;

        // Accent colours.
        root.style.setProperty('--accent', accentHex);
        root.style.setProperty('--accent-soft', accentSoft);
        // A translucent ring colour derived from the accent, used for focus
        // states so they match the theme rather than always being indigo.
        root.style.setProperty('--accent-ring', `${accentHex}33`);

        // Corner radius, chosen from sm/md/lg/xl.
        const radius = { sm: '0.375rem', md: '0.5rem', lg: '0.75rem', xl: '1rem' }[theme.radius] || '0.75rem';
        root.style.setProperty('--radius', radius);

        // Light / dark mode flag - components can branch on this if needed.
        root.setAttribute('data-theme-mode', theme.mode || 'light');

        // Colour scheme so native controls (scrollbars, inputs) follow suit.
        root.style.colorScheme = theme.mode === 'dark' ? 'dark' : 'light';
    }, [accentHex, accentSoft, theme.radius, theme.mode]);

    return children;
}
