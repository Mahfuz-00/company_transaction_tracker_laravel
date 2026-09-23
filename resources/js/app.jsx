import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import GlobalLoadingIndicator from '@/Components/GlobalLoadingIndicator';
import { FeedbackProvider } from '@/Components/Feedback/FeedbackProvider';
import { applyThemeTokens, resolveInitialTheme } from '@/Components/ThemeProvider';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Decide which theme to paint with and apply it, BEFORE React mounts (so there
 * is no flash of the default accent on first paint).
 *
 * The precedence itself lives in ONE place - resolveInitialTheme() in
 * ThemeProvider - so this pre-mount paint and the provider's React render can
 * never drift apart. Here we only decide whether to also write the PC-local
 * copy: we persist when the ACCOUNT supplied a theme (so the browser copy
 * tracks the signed-in user); a localStorage/institution fallback is applied
 * but not re-persisted.
 */
function resolveAndApply(props) {
    const userTheme = props?.auth?.user?.theme;
    const persist = !!(userTheme && Object.keys(userTheme).length > 0);

    applyThemeTokens(resolveInitialTheme(props), { persist });
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        // 1. Initial paint: apply the theme before React mounts (no flash).
        resolveAndApply(props?.initialPage?.props);

        // 2. Re-apply on every successful visit, so a saved theme - or an SSA
        //    institution switch - repaints the whole app at once, no reload.
        router.on('success', (event) => {
            resolveAndApply(event.detail.page.props);
        });

        const root = createRoot(el);

        root.render(
            // FeedbackProvider wraps the whole app (not just a layout) so flash
            // messages and confirmations work identically on every screen,
            // including the guest pages.
            <FeedbackProvider>
                <App {...props} />
                {/* One central spinner for every async request. */}
                <GlobalLoadingIndicator />
            </FeedbackProvider>
        );
    },
    progress: {
        // Inertia's thin top bar, kept for fast navigations; the global
        // overlay handles slower requests with a fuller indicator.
        color: 'var(--accent, #4f46e5)',
        showSpinner: false,
    },
});
