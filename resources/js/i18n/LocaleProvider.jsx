import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * FRONT-END i18n.
 *
 * WHY THIS AND NOT A LIBRARY
 *   The translation catalogue already exists server-side (`lang/<code>/app.php`)
 *   and is shared to every page as the `locale.messages` Inertia prop. Pulling in
 *   react-i18next would mean maintaining a SECOND copy of the same strings and a
 *   second loading strategy. Instead this provider simply reads the shared
 *   dictionary, so the PHP side and the React side can never disagree about
 *   wording — and there is one file to translate per language.
 *
 * WHY IT TAKES THE PAYLOAD AS A PROP (and does NOT call usePage)
 * -------------------------------------------------------------
 * It DELIBERATELY does not use Inertia's `usePage()`. That hook only works inside
 * Inertia's own `<App>` context, and this provider also has to wrap components
 * that sit BESIDE `<App>` in the render tree (the onboarding guide and the global
 * loading indicator). Calling `usePage()` from here threw
 * "usePage must be used within the Inertia component" and crashed the ENTIRE
 * React tree — a blank page on every route.
 *
 * Taking the prop instead makes the provider a plain React component with no
 * framework dependency: it works anywhere in the tree, including outside <App>.
 *
 * ADDING A LANGUAGE
 *   1. `config/locales.php`  → add the entry
 *   2. `lang/<code>/app.php` → copy `lang/en/app.php` and translate
 *   That is all. This provider, the switcher and the document direction all read
 *   from those two places.
 *
 * FALLBACK BEHAVIOUR
 *   `t('a.b.c')` resolves:
 *     active locale  →  English fallback (server-side)  →  the key itself
 *   A missing translation therefore shows a dotted path (`auth.sign_in`) rather
 *   than `undefined`, which makes the gap obvious in review instead of invisible
 *   to the user.
 */

const LocaleContext = createContext(null);

/** Read a dotted path out of a nested-or-flat object. */
function lookup(messages, key) {
    if (!messages) return undefined;

    // The server flattens to dot notation; accept a nested object too, so a
    // hand-built test dictionary works without transformation.
    if (Object.prototype.hasOwnProperty.call(messages, key)) {
        return messages[key];
    }

    return key.split('.').reduce((carry, part) => (carry == null ? undefined : carry[part]), messages);
}

/**
 * @param {object} props
 * @param {object} props.locale     - the shared `locale` Inertia prop
 * @param {node}   props.children
 */
export function LocaleProvider({ locale, children }) {
    /*
     * FOLLOW EVERY INERTIA VISIT.
     *
     * `locale` is handed down ONCE from `initialPage` (app.jsx reads it there
     * because this provider must also wrap components that render OUTSIDE
     * Inertia's <App>). Passing the initial page's value only means that after an
     * Inertia visit the language NEVER changes: the switcher POSTs / GETs the new
     * locale, the server applies and returns it, but the client keeps rendering
     * the OLD <html lang> and the old dictionary until a full page reload.
     *
     * Subscribing to the router directly fixes that without reintroducing
     * `usePage()` (which throws here - see the class docblock). The shared
     * `locale` prop is present on every Inertia response, so a successful visit is
     * all the signal needed.
     */
    const [serverLocale, setServerLocale] = useState(locale);

    useEffect(() => {
        setServerLocale(locale);
    }, [locale]);

    useEffect(() => {
        const off = router.on('success', (event) => {
            const next = event?.detail?.page?.props?.locale;

            if (next) setServerLocale(next);
        });

        return () => {
            // Inertia's router.on returns an unsubscribe function.
            if (typeof off === 'function') off();
        };
    }, []);

    const current = serverLocale?.current ?? 'en';
    const messages = serverLocale?.messages ?? {};
    const rtl = Boolean(serverLocale?.rtl);

    /**
     * Apply direction and lang to <html>.
     *
     * Both matter beyond styling: `lang` drives screen-reader pronunciation and
     * `dir` flips the entire layout for a right-to-left script. Doing it on the
     * document element (rather than a wrapper div) means native scrollbars,
     * form controls and browser UI follow too.
     */
    useEffect(() => {
        if (typeof document === 'undefined') return;

        document.documentElement.setAttribute('lang', current);
        document.documentElement.setAttribute('dir', rtl ? 'rtl' : 'ltr');
    }, [current, rtl]);

    /**
     * Translate a key.
     *
     * `replacements` interpolates `:name` placeholders, matching Laravel's own
     * `__('...', ['name' => ...])` syntax so the same string works on both sides.
     */
    const t = useCallback(
        (key, replacements = null) => {
            let value = lookup(messages, key);

            if (value === undefined || value === null) {
                // Visible, greppable fallback — never `undefined` in the UI.
                return key;
            }

            if (replacements && typeof value === 'string') {
                Object.entries(replacements).forEach(([name, replacement]) => {
                    value = value.split(`:${name}`).join(String(replacement));
                });
            }

            return value;
        },
        [messages]
    );

    const value = useMemo(
        () => ({
            t,
            locale: current,
            rtl,
            supported: serverLocale?.supported ?? [],
            /** Is this language currently active? */
            isCurrent: (code) => code === current,
        }),
        [t, current, rtl, serverLocale]
    );

    return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

/**
 * The translation hook.
 *
 *   const { t } = useTranslation();
 *   <button>{t('common.save')}</button>
 */
export default function useTranslation() {
    const context = useContext(LocaleContext);

    // Fail loudly in development rather than silently rendering raw keys, which
    // would look like a missing translation rather than a missing provider.
    if (!context) {
        if (import.meta.env.DEV) {
            throw new Error('useTranslation() must be used inside <LocaleProvider>.');
        }

        // In production degrade gracefully: render the key.
        return { t: (key) => key, locale: 'en', rtl: false, supported: [], isCurrent: () => false };
    }

    return context;
}