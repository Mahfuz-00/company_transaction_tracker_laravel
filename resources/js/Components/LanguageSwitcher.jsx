import { router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import useTranslation from '@/i18n/LocaleProvider';

/**
 * LANGUAGE SWITCHER.
 *
 * A small globe + the active language's own name. Deliberately compact: language
 * is a set-once preference, not something that deserves permanent header space.
 *
 * TWO SUBMIT PATHS
 *   - Signed in  → POST /language, which persists to `users.locale` so the choice
 *                  follows the account to any device.
 *   - A guest    → the GET /language/{code} route, which writes the session only.
 *                  This is what lets a visitor read the landing page in their own
 *                  language before they have an account.
 *
 * @param {object}  props
 * @param {string} [props.variant]  'light' (on dark surfaces) | 'default'
 * @param {boolean} [props.compact] icon-only until opened
 */
export default function LanguageSwitcher({ variant = 'default', compact = false }) {
    const { t, locale, supported, isCurrent } = useTranslation();
    const [open, setOpen] = useState(false);

    // With a single supported language a switcher is noise — render nothing.
    if (supported.length < 2) return null;

    const activeMeta = supported.find((entry) => entry.code === locale);

    /**
     * Switch language.
     *
     * A guest uses the session route; an authenticated user posts so the choice is
     * saved to their account and survives sign-out on a different machine.
     */
    const choose = (code) => {
        setOpen(false);

        if (isCurrent(code)) return;

        const authed = Boolean(document.querySelector('meta[name="user-authenticated"]')?.content === '1');

        if (authed) {
            router.post(route('language.update'), { locale: code }, { preserveScroll: true });
        } else {
            router.get(route('language.guest', code), {}, { preserveScroll: true });
        }
    };

    const triggerClass =
        variant === 'light'
            ? 'text-white/80 hover:bg-white/10 hover:text-white'
            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700';

    return (
        <div className="relative" data-testid="language-switcher">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label={t('language.choose')}
                data-testid="language-switcher-trigger"
                className={`inline-flex items-center gap-1.5 rounded-xl px-2.5 py-2 text-xs font-semibold transition-colors ${triggerClass}`}
            >
                <svg className="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" d="M3.6 9h16.8M3.6 15h16.8M12 3c2.4 2.4 3.6 5.4 3.6 9s-1.2 6.6-3.6 9c-2.4-2.4-3.6-5.4-3.6-9S9.6 5.4 12 3z" />
                </svg>
                {!compact && <span>{activeMeta?.label ?? locale.toUpperCase()}</span>}
            </button>

            {open && (
                <>
                    {/* Backdrop: closes on an outside click without a document listener. */}
                    <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden="true" />

                    <ul
                        role="listbox"
                        data-testid="language-switcher-menu"
                        className="absolute right-0 z-50 mt-1.5 min-w-[10rem] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg"
                    >
                        {supported.map((entry) => (
                            <li key={entry.code}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={isCurrent(entry.code)}
                                    onClick={() => choose(entry.code)}
                                    data-testid={`language-option-${entry.code}`}
                                    className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs font-semibold transition-colors ${isCurrent(entry.code)
                                            ? 'bg-[var(--accent-soft)] text-[var(--accent)]'
                                            : 'text-slate-600 hover:bg-slate-50'
                                        }`}
                                >
                                    {/* The language names itself — never translated. */}
                                    <span>{entry.label}</span>

                                    {isCurrent(entry.code) && (
                                        <svg className="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.4" d="M5 13l4 4L19 7" />
                                        </svg>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}