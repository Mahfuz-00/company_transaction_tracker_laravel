import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import GlobalLoadingIndicator from '@/Components/GlobalLoadingIndicator';
import { FeedbackProvider } from '@/Components/Feedback/FeedbackProvider';
import { applyThemeTokens, readLocalTheme } from '@/Components/ThemeProvider';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Decide which theme to paint with, in precedence order, and apply it.
 *
 *   1. The signed-in user's DATABASE theme (auth.user.theme) - follows them from
 *      any device.
 *   2. The browser localStorage theme - the PC-local copy (applies pre-login).
 *   3. The institution theme - workspace default.
 *
 * This mirrors ThemeProvider.resolveTheme; running it BEFORE React mounts means
 * there is no flash of the default accent on first paint.
 */
function resolveAndApply(props) {
    const userTheme = props?.auth?.user?.theme;
    const institutionTheme = props?.institution?.theme;

    if (userTheme && Object.keys(userTheme).length > 0) {
        applyThemeTokens(userTheme, { persist: true });
        return;
    }

    const local = readLocalTheme();
    if (local) {
        applyThemeTokens(local, { persist: false });
        return;
    }

    applyThemeTokens(institutionTheme, { persist: false });
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
