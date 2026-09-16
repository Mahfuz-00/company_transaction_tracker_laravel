import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import GlobalLoadingIndicator from '@/Components/GlobalLoadingIndicator';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Apply the workspace theme before React mounts.
 *
 * Inertia hands us the page props on first render, so we can set the CSS
 * variables synchronously - this avoids a flash of the default accent colour
 * between paint and the ThemeProvider's effect running.
 */
function applyInitialTheme(props) {
    const institution = props?.initialPage?.props?.institution;
    const accent = institution?.accent;
    const theme = institution?.theme;

    if (!accent) return;

    const root = document.documentElement;
    root.style.setProperty('--accent', accent.hex || '#4f46e5');
    root.style.setProperty('--accent-soft', accent.soft || '#eef2ff');
    root.style.setProperty('--accent-ring', `${accent.hex || '#4f46e5'}33`);
    root.setAttribute('data-theme-mode', theme?.mode || 'light');
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        applyInitialTheme(props);

        const root = createRoot(el);

        root.render(
            <>
                <App {...props} />
                {/* One central spinner for every async request. */}
                <GlobalLoadingIndicator />
            </>
        );
    },
    progress: {
        // Inertia's thin top bar, kept for fast navigations; the global
        // overlay handles slower requests with a fuller indicator.
        color: 'var(--accent, #4f46e5)',
        showSpinner: false,
    },
});
