import { router } from '@inertiajs/react';
import { applyThemeTokens, useTheme } from '@/Components/ThemeProvider';

/**
 * Global light/dark switch - the "one global spot" for the most common theme
 * change, reachable from every authenticated screen (it sits beside the
 * notification bell in the app shell, so it is visible at every breakpoint).
 *
 * It does three things in order, so the switch feels instant AND sticks:
 *   1. Paints immediately (optimistic) by calling applyThemeTokens - the user
 *      sees the flip before the network round-trip completes.
 *   2. Writes the browser copy to localStorage (applyThemeTokens with
 *      persist: true), so the choice survives even before the next request.
 *   3. Persists the FULL token set to the account through the existing
 *      `settings.theme.update` endpoint, so the choice follows the user to other
 *      devices and paints the login/welcome screens after logout.
 *
 * The endpoint deliberately carries no permission gate - personalising one's own
 * view is a preference, not an administrative act (each user edits only their
 * own `users.theme`).
 */
export default function ThemeToggle({ className = '' }) {
    const { theme } = useTheme();
    const isDark = theme?.mode === 'dark';

    const toggle = () => {
        // Flip only the mode; keep accent/radius/density/font as they are, so the
        // request satisfies the endpoint's full-token validation.
        const next = { ...theme, mode: isDark ? 'light' : 'dark' };

        applyThemeTokens(next, { persist: true });

        router.put(route('settings.theme.update'), next, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={isDark ? 'Light mode' : 'Dark mode'}
            className={`inline-flex h-9 w-9 items-center justify-center rounded-xl border text-slate-500 transition-colors hover:text-slate-800 ${className}`}
            style={{ borderColor: 'var(--border-color)', backgroundColor: 'var(--surface)' }}
        >
            {isDark ? (
                // Sun (shown while dark, to invite the switch to light)
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            ) : (
                // Moon (shown while light, to invite the switch to dark)
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
            )}
        </button>
    );
}
