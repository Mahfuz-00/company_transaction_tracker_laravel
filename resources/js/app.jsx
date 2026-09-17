import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import GlobalLoadingIndicator from '@/Components/GlobalLoadingIndicator';
import { FeedbackProvider } from '@/Components/Feedback/FeedbackProvider';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Apply the workspace theme as CSS custom properties on :root.
 *
 * Called twice:
 *   1. BEFORE React mounts (initial paint) - no flash of the default accent.
 *   2. On EVERY Inertia navigation (see router.on('success') below) - so saving
 *      the theme in Settings, or an SSA switching institutions, repaints the
 *      whole app immediately WITHOUT a hard reload.
 *
 * This is the client counterpart to the server-side injection in app.blade.php;
 * both write the same token names, so they can never disagree.
 */
function applyTheme(institution) {
    const accent = institution?.accent;
    const theme = institution?.theme;
    const root = document.documentElement;

    const hex = accent?.hex || '#4f46e5';
    const soft = accent?.soft || '#eef2ff';

    root.style.setProperty('--accent', hex);
    root.style.setProperty('--accent-soft', soft);
    root.style.setProperty('--accent-ring', `${hex}33`);
    root.style.setProperty('--primary-color', hex);
    root.style.setProperty('--primary-soft', soft);

    const radius = { sm: '0.375rem', md: '0.5rem', lg: '0.75rem', xl: '1rem' }[theme?.radius] || '0.75rem';
    root.style.setProperty('--radius', radius);

    root.setAttribute('data-theme-mode', theme?.mode || 'light');
    root.style.colorScheme = theme?.mode === 'dark' ? 'dark' : 'light';
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
        applyTheme(props?.initialPage?.props?.institution);

        // 2. Re-apply on every successful visit. This is what makes a saved
        //    theme, or an SSA institution switch, reflect globally at once -
        //    the shared `institution` prop changes and the CSS vars repaint.
        router.on('success', (event) => {
            applyTheme(event.detail.page.props.institution);
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
