import { useEffect, useState } from 'react';
import { useTheme } from '@/Components/ThemeProvider';

/**
 * The active theme, tracked LIVE.
 *
 * WHY THIS EXISTS
 * ---------------
 * `useTheme()` returns the theme resolved from Inertia props, which only changes
 * after a SAVE round-trip (the server re-resolves and re-sends the props).
 *
 * `applyThemeTokens()` - called by the Theme Customizer's live preview and by the
 * global ThemeToggle - writes the CSS custom properties onto <html> IMMEDIATELY
 * and then dispatches a `theme:change` window event. That means anything styled
 * with `var(--accent)` etc. repaints instantly, but anything whose colours are
 * computed IN JAVASCRIPT (e.g. ThemePreviewFrame's miniature palette, which calls
 * `ACCENT_HEX(theme.accent)`) does not - React never re-renders.
 *
 * This hook bridges that gap: it listens for `theme:change` and returns the
 * latest theme object, so JS-derived previews react in real time while the user
 * switches accent or dark/light mode, without waiting for a save.
 *
 * The context theme is the initial value and the fallback, so the first render is
 * always correct and a save round-trip still flows through.
 */
export default function useLiveTheme() {
    const { theme } = useTheme();
    const [live, setLive] = useState(theme);

    // Track the context (initial value + any post-save re-resolution).
    useEffect(() => {
        setLive(theme);
    }, [theme]);

    // Follow the un-saved live preview broadcast by applyThemeTokens().
    useEffect(() => {
        const onChange = (event) => {
            if (event?.detail) {
                setLive(event.detail);
            }
        };

        window.addEventListener('theme:change', onChange);

        return () => window.removeEventListener('theme:change', onChange);
    }, []);

    return live;
}
