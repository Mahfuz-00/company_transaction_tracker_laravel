import { createContext, useContext, useCallback, useEffect, useMemo } from 'react';
import { usePage } from '@inertiajs/react';

/* ------------------------------------------------------------------ *
 * Shared theme tokens (imported by the customiser + provider)
 * ------------------------------------------------------------------ */

/** Accent soft (tint) colours, keyed by accent name. Mirrors Institution::THEMES. */
export const ACCENT_SOFT = (accent) =>
({
    indigo: '#eef2ff', emerald: '#ecfdf5', sky: '#e0f2fe', violet: '#f5f3ff',
    rose: '#fff1f2', amber: '#fffbeb', slate: '#f1f5f9', teal: '#f0fdfa',
}[accent] || '#eef2ff');

/** Accent hex colours, keyed by accent name. */
export const ACCENT_HEX = (accent) =>
({
    indigo: '#4f46e5', emerald: '#059669', sky: '#0284c7', violet: '#7c3aed',
    rose: '#e11d48', amber: '#d97706', slate: '#334155', teal: '#0d9488',
}[accent] || '#4f46e5');

/** Corner-radius token -> CSS length. */
export const RADIUS_PX = { sm: '0.375rem', md: '0.5rem', lg: '0.75rem', xl: '1rem' };

/** Font token -> CSS font stack, with the Google family to load (or null). */
export const FONT_STACKS = {
    inter: { stack: "'Inter', ui-sans-serif, system-ui, sans-serif", google: 'Inter' },
    roboto: { stack: "'Roboto', ui-sans-serif, system-ui, sans-serif", google: 'Roboto' },
    poppins: { stack: "'Poppins', ui-sans-serif, system-ui, sans-serif", google: 'Poppins' },
    nunito: { stack: "'Nunito', ui-sans-serif, system-ui, sans-serif", google: 'Nunito' },
    system: { stack: 'ui-sans-serif, system-ui, sans-serif', google: null },
};

/** Layout-density token -> a base font-size for the whole document. */
export const DENSITY_SCALE = { compact: '14px', comfortable: '15px', spacious: '16px' };

/**
 * Density -> the fluid range the root font-size is clamped between (see the
 * `html` rule in app.css). Tailwind's text-* utilities are rem-based, so scaling
 * the ROOT size scales the whole app's type proportionally - these bounds keep
 * it readable from a phone up to a large TV without a rule per screen.
 */
export const DENSITY_RANGE = {
    compact: { min: '13px', max: '15px' },
    comfortable: { min: '14px', max: '16px' },
    spacious: { min: '15px', max: '18px' },
};

/** localStorage key. Keyed to the BROWSER, so it persists for whoever uses it. */
const STORAGE_KEY = 'tt.theme';

/**
 * The default theme, used when neither the account nor the browser has one.
 * Kept in step with User::DEFAULT_THEME on the server.
 */
const DEFAULT_THEME = {
    mode: 'light',
    accent: 'indigo',
    radius: 'lg',
    density: 'comfortable',
    font: 'inter',
};

/* ------------------------------------------------------------------ *
 * Applying a theme object to the document
 * ------------------------------------------------------------------ */

/**
 * Apply a theme object as CSS custom properties on :root, load the chosen web
 * font, and (optionally) persist the choice to localStorage - the PC-local half
 * of the dual-persistence requirement.
 *
 * Called: on first paint (before React mounts, via app.jsx), on every Inertia
 * navigation, and live while the user tweaks the customiser.
 */
export function applyThemeTokens(theme, { persist = false } = {}) {
    if (typeof document === 'undefined') return;

    const root = document.documentElement;
    const t = { ...DEFAULT_THEME, ...(theme || {}) };

    const hex = ACCENT_HEX(t.accent);
    const soft = ACCENT_SOFT(t.accent);
    const density = t.density || 'comfortable';
    const isDark = t.mode === 'dark';

    /*
     * ----------------------------------------------------------------
     * GLOBAL CLASS TOGGLE
     * ----------------------------------------------------------------
     * The `.dark` class on <html> switches on the dark palette defined in
     * app.css (--bg-color, --surface, --text-*, --border-color). Bootstrap-/
     * Tailwind-style, this is the single flip that inverts the ENTIRE app,
     * because every component reads those semantic tokens rather than a
     * hard-coded slate-* colour. `.light` is added for symmetry so components
     * can target either state.
     */
    root.classList.toggle('dark', isDark);
    root.classList.toggle('light', !isDark);

    // Keep the attribute too - it is what the CSS `:root`/`.dark` blocks and
    // any legacy selectors key off, and it survives a class-strip.
    root.setAttribute('data-theme-mode', isDark ? 'dark' : 'light');
    root.style.colorScheme = isDark ? 'dark' : 'light';

    // Accent + semantic tokens. --primary-color is the name the email templates
    // and older components already reference, so both spellings are set.
    root.style.setProperty('--accent', hex);
    root.style.setProperty('--accent-soft', soft);
    root.style.setProperty('--accent-ring', `${hex}33`);
    root.style.setProperty('--primary-color', hex);
    root.style.setProperty('--primary-soft', soft);

    /*
     * SEMANTIC SURFACE + TEXT TOKENS.
     *
     * In light mode these mirror the original slate palette; in dark mode they
     * flip to the dark scale. Setting them inline (rather than only in the CSS
     * `.dark` block) means the values are authoritative per request and any
     * component style that reads them updates on the very next paint.
     */
    const palette = isDark
        ? {
              '--bg-color': '#0b1120',
              '--surface': '#111827',
              '--surface-soft': '#1e293b',
              '--surface-muted': '#1e293b',
              '--border-color': '#1f2937',
              '--text-primary': '#f1f5f9',
              '--text-secondary': '#cbd5e1',
              '--text-muted': '#64748b',
          }
        : {
              '--bg-color': '#f8fafc',
              '--surface': '#ffffff',
              '--surface-soft': '#f8fafc',
              '--surface-muted': '#f1f5f9',
              '--border-color': '#e2e8f0',
              '--text-primary': '#0f172a',
              '--text-secondary': '#475569',
              '--text-muted': '#94a3b8',
          };

    Object.entries(palette).forEach(([k, v]) => root.style.setProperty(k, v));

    // A dark-mode accent tint derived from the accent (the CSS block also does
    // this with color-mix, but setting it inline guarantees a value even in
    // older engines).
    if (isDark) {
        root.style.setProperty('--accent-soft', `${hex}33`);
    }

    // Radius + density.
    root.style.setProperty('--radius', RADIUS_PX[t.radius] || RADIUS_PX.lg);
    root.style.setProperty('--density', density);
    root.style.setProperty('--font-scale', DENSITY_SCALE[density] || DENSITY_SCALE.comfortable);

    // The fluid bounds the root font-size is clamped between (see the `html`
    // rule in app.css). This is what makes typography scale with the viewport
    // instead of being frozen at one size on every screen.
    const range = DENSITY_RANGE[density] || DENSITY_RANGE.comfortable;
    root.style.setProperty('--font-scale-min', range.min);
    root.style.setProperty('--font-scale-max', range.max);

    // Font family + load the Google font if the stack needs one.
    const font = FONT_STACKS[t.font] || FONT_STACKS.inter;
    root.style.setProperty('--font-family', font.stack);
    root.style.fontFamily = font.stack;
    if (font.google) {
        loadGoogleFont(font.google);
    }

    // Persist to THIS browser so it applies instantly next time - regardless of
    // who signs in (the PC-local half).
    if (persist) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(t));
        } catch (e) {
            /* private mode / storage disabled - non-fatal */
        }
    }

    // Let any mounted components (e.g. the customiser's own preview frame)
    // react to a programmatic theme change.
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('theme:change', { detail: t }));
    }
}

/** Read the browser-local theme, if one has been saved on this PC. */
export function readLocalTheme() {
    if (typeof localStorage === 'undefined') return null;
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

/** Inject a Google-hosted font stylesheet once. */
const loadedFonts = new Set();
function loadGoogleFont(family) {
    if (loadedFonts.has(family)) return;
    loadedFonts.add(family);

    const id = `gf-${family.toLowerCase().replace(/\s+/g, '-')}`;
    if (document.getElementById(id)) return;

    const link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:wght@400;500;600;700&display=swap`;
    document.head.appendChild(link);
}

/* ------------------------------------------------------------------ *
 * Context
 * ------------------------------------------------------------------ */

const ThemeContext = createContext(null);

export const useTheme = () => {
    const ctx = useContext(ThemeContext);
    // A safe fallback so a component can call useTheme() even if it renders
    // outside the provider (e.g. a stray route).
    return ctx || {
        theme: DEFAULT_THEME,
        applyPreview: (t) => applyThemeTokens(t),
        resetPreview: () => applyThemeTokens(readLocalTheme() || DEFAULT_THEME),
    };
};

/**
 * Chooses the theme to apply, in precedence order:
 *   1. The signed-in user's DATABASE theme (auth.user.theme) - follows them to
 *      any device.
 *   2. The browser's localStorage theme - applies on this PC even before login.
 *   3. The institution theme - a sensible workspace default.
 *   4. The platform default.
 */
function resolveTheme(userTheme, institutionTheme) {
    if (userTheme && Object.keys(userTheme).length > 0) {
        return { ...DEFAULT_THEME, ...userTheme };
    }
    const local = readLocalTheme();
    if (local) {
        return { ...DEFAULT_THEME, ...local };
    }
    if (institutionTheme) {
        return { ...DEFAULT_THEME, ...institutionTheme };
    }
    return DEFAULT_THEME;
}

/**
 * THE single precedence resolver, exported so the pre-mount paint in app.jsx and
 * this provider share ONE definition of which theme wins - they can never drift.
 * Takes a full Inertia `props` object and returns the resolved token set.
 */
export function resolveInitialTheme(props = {}) {
    return resolveTheme(props?.auth?.user?.theme, props?.institution?.theme);
}

/**
 * Applies the resolved theme as CSS custom properties on :root and provides the
 * live-preview helpers to the customiser.
 *
 * The database theme WINS over localStorage when a user is signed in, so a theme
 * chosen on another device is restored here; when nobody is signed in, the
 * PC-local values keep the workspace looking how this machine's user left it.
 */
export default function ThemeProvider({ children }) {
    const { auth, institution } = usePage().props;
    const userTheme = auth?.user?.theme;

    const resolved = useMemo(
        () => resolveTheme(userTheme, institution?.theme),
        // Re-resolve whenever the account theme or institution changes.
        [JSON.stringify(userTheme || {}), JSON.stringify(institution?.theme || {})]
    );

    useEffect(() => {
        // Persist the resolved theme to THIS browser on every navigation, so the
        // PC-local copy always tracks the account theme.
        applyThemeTokens(resolved, { persist: true });
    }, [resolved]);

    // Live preview from the customiser: apply without persisting until saved.
    const applyPreview = useCallback((t) => applyThemeTokens(t, { persist: false }), []);
    const resetPreview = useCallback(() => applyThemeTokens(resolved, { persist: true }), [resolved]);

    const value = useMemo(
        () => ({ theme: resolved, applyPreview, resetPreview }),
        [resolved, applyPreview, resetPreview]
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
