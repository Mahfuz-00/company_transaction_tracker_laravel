import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import GlobalLoadingIndicator from '@/Components/GlobalLoadingIndicator';
import { FeedbackProvider } from '@/Components/Feedback/FeedbackProvider';
import HintsProvider from '@/Components/Help/HintsProvider';
import OnboardingProvider from '@/Components/Onboarding/OnboardingProvider';
import { applyThemeTokens, resolveInitialTheme } from '@/Components/ThemeProvider';
import { LocaleProvider } from '@/i18n/LocaleProvider';

const appName = import.meta.env.VITE_APP_NAME || 'NomNomytics';

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

        /*
         * The shared `locale` prop is read ONCE here and passed explicitly to
         * every LocaleProvider instance.
         *
         * LocaleProvider deliberately does NOT call Inertia's usePage(): it also
         * has to wrap components that render BESIDE <App>, outside Inertia's
         * context, and calling usePage() there threw
         * "usePage must be used within the Inertia component" - crashing the whole
         * tree and leaving a blank page on every route.
         */
        const locale = props?.initialPage?.props?.locale;

        /*
         * The onboarding payload is read the same way, and for the same reason.
         *
         * OnboardingProvider renders as a SIBLING of <App>, so it is outside
         * Inertia's context and cannot call usePage(). Passing the payload down as
         * a prop keeps it a plain React component.
         *
         * NOTE: this is read from `initialPage`, so the tour is evaluated on the
         * FIRST page of the session. That is exactly right - the server decides
         * `show` from `onboarding_completed_at`, and the modal is dismissible per
         * session.
         */
        const onboarding = props?.initialPage?.props?.onboarding;

        /*
         * The global hint preference, read the same way (and for the same reason -
         * HintsProvider also renders outside <App>).
         *
         * The VALUE here is only the initial state: HintsProvider itself follows
         * every later Inertia visit, so flipping the switch in Settings takes effect
         * on the redirect without a reload.
         */
        const hints = props?.initialPage?.props?.hints;

        root.render(
            /*
             * FeedbackProvider wraps the whole app (not just a layout) so flash
             * messages and confirmations work identically on every screen,
             * including the guest pages.
             */
            <FeedbackProvider>
                <LocaleProvider locale={locale}>
                    {/* One switch silences every `?` hint on the platform. Mounted
                        ABOVE <App> so a badge anywhere in the tree can read it. */}
                    <HintsProvider hints={hints}>
                        <App {...props} />

                        {/* Role-specific first-time onboarding, plus the manual
                            "Show me around" trigger any screen can fire. Mounted
                            globally so the guide appears on whichever dashboard the
                            user first lands on. */}
                        <OnboardingProvider onboarding={onboarding} />

                        {/* One central spinner for every async request. */}
                        <GlobalLoadingIndicator />
                    </HintsProvider>
                </LocaleProvider>
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
