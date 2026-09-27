import React, { useEffect, useId, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * PERSISTENT UI HINTS & HELP BADGES.
 *
 * The first-login onboarding modal explains the big picture ONCE, then never
 * returns. Days later a manager has forgotten where a screen lives, or what a
 * figure actually means. This component is the always-available answer: a small
 * "?" badge or inline hint that can be attached to any page, card, metric or
 * field, and which re-opens on demand.
 *
 * THREE FORMS
 * -----------
 *   1. <HelpBadge>   - a compact "?" bubble with a popover. The default.
 *   2. <InfoHint>    - a permanent, non-interactive line of helper text, for
 *                      places where a tooltip would be missed (dense tables).
 *   3. <FieldHelp>   - a trailing "?" beside a form label, for input guidance.
 *
 * PERSISTENCE
 * -----------
 * "Persistent" here means the hint is permanently AVAILABLE, not permanently
 * OPEN. A user can pin a badge's popover open (`localStorage`, per hint id) so
 * guidance they rely on stays visible across reloads - and unpin it when they no
 * longer need it. Default is unpinned, so the UI stays uncluttered.
 *
 * ACCESSIBILITY
 *   - The badge is a real button with `aria-expanded` / `aria-controls`.
 *   - Escape closes; clicking outside closes.
 *   - `role="dialog"` on the popover content with an accessible label.
 *
 * TEST HOOKS
 *   `data-testid="help-badge"`, `data-testid="help-popover"`,
 *   `data-testid="help-pin"` (the pin toggle).
 */

const STORAGE_PREFIX = 'nomnomytics.hint.pinned.';

/** Read the pinned state for a hint id (tolerating private-mode storage errors). */
function readPinned(id) {
    try {
        return window.localStorage.getItem(STORAGE_PREFIX + id) === '1';
    } catch (e) {
        return false;
    }
}

function writePinned(id, value) {
    try {
        if (value) window.localStorage.setItem(STORAGE_PREFIX + id, '1');
        else window.localStorage.removeItem(STORAGE_PREFIX + id);
    } catch (e) {
        // Storage unavailable (private mode / disabled) - the hint still works,
        // it just will not remember the pin.
    }
}

/**
 * A compact help badge with a popover.
 *
 * @param {object} props
 * @param {string} props.title      - short heading inside the popover
 * @param {React.ReactNode} props.children - the guidance body
 * @param {string} [props.label]    - accessible label (defaults to title)
 * @param {string} [props.id]       - stable id for pinning (defaults to generated)
 * @param {'left'|'right'} [props.align] - which way the popover opens
 */
export function HelpBadge({ title, children, label = null, id = null, align = 'right' }) {
    const generatedId = useId();
    const hintId = id || `hint-${generatedId.replace(/[^a-zA-Z0-9-]/g, '')}`;

    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);
    const containerRef = useRef(null);

    // Restore a previously pinned state on mount.
    useEffect(() => {
        const stored = readPinned(hintId);
        setPinned(stored);
        if (stored) setOpen(true);
    }, [hintId]);

    // Outside-click + Escape close, but ONLY when not pinned.
    useEffect(() => {
        if (!open || pinned) return undefined;

        const onPointerDown = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open, pinned]);

    const togglePin = () => {
        const next = !pinned;
        setPinned(next);
        writePinned(hintId, next);
        if (next) setOpen(true);
    };

    return (
        <span className="relative inline-flex" ref={containerRef}>
            <button
                type="button"
                data-testid="help-badge"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-controls={`${hintId}-popover`}
                aria-label={label || `Help: ${title}`}
                className={`inline-flex h-4.5 w-4.5 flex-shrink-0 items-center justify-center rounded-full border text-[10px] font-bold transition-colors ${open || pinned
                        ? 'border-[var(--accent)] bg-[var(--accent)] text-white'
                        : 'border-slate-300 bg-white text-slate-400 hover:border-[var(--accent)] hover:text-[var(--accent)]'
                    }`}
                style={{ height: '1.125rem', width: '1.125rem' }}
            >
                ?
            </button>

            {open && (
                <div
                    id={`${hintId}-popover`}
                    role="dialog"
                    aria-label={label || title}
                    data-testid="help-popover"
                    className={`absolute top-6 z-50 w-72 rounded-xl border border-slate-200 bg-white p-3.5 text-left shadow-xl animate-rise ${align === 'right' ? 'right-0' : 'left-0'
                        }`}
                >
                    <div className="flex items-start justify-between gap-2">
                        <h4 className="text-xs font-bold text-slate-900">{title}</h4>
                        <div className="flex flex-shrink-0 items-center gap-1">
                            <button
                                type="button"
                                onClick={togglePin}
                                data-testid="help-pin"
                                aria-pressed={pinned}
                                title={pinned ? 'Unpin this hint' : 'Keep this hint open'}
                                className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold transition-colors ${pinned
                                        ? 'bg-[var(--accent-soft)] text-[var(--accent)]'
                                        : 'text-slate-400 hover:bg-slate-100 hover:text-slate-600'
                                    }`}
                            >
                                {pinned ? 'Pinned' : 'Pin'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                aria-label="Close help"
                                className="rounded-md p-0.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                            >
                                <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div className="mt-1.5 text-xs leading-relaxed text-slate-600">{children}</div>
                </div>
            )}
        </span>
    );
}

/**
 * A permanent, always-visible line of helper text.
 *
 * Use inside cards and tables where a hover-reveal would be missed entirely.
 */
export function InfoHint({ children, tone = 'slate', className = '' }) {
    const tones = {
        slate: 'border-slate-200 bg-slate-50 text-slate-600',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        indigo: 'border-indigo-200 bg-indigo-50 text-indigo-800',
    };

    return (
        <p
            data-testid="info-hint"
            className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-xs leading-relaxed ${tones[tone] || tones.slate} ${className}`}
        >
            <svg className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{children}</span>
        </p>
    );
}

/**
 * A "?" trailing a form label, for per-field guidance. Renders the label text,
 * the badge, and (optionally) the field's own hint line beneath it.
 */
export function FieldHelp({ label, title, children, id = null }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span>{label}</span>
            <HelpBadge title={title || label} id={id} label={`Help for ${label}`}>
                {children}
            </HelpBadge>
        </span>
    );
}

/**
 * A page-level "tour" hint: a small persistent bar that reminds the user what
 * this screen is for and links back into the onboarding guide.
 *
 * Rendered by pages that want a constant orientation aid (noology: the modal is
 * gone, but the "what am I looking at" cue remains).
 */
export function PageHint({ title = 'About this page', children, guideHref = null }) {
    const { onboarding } = usePage().props;
    const role = onboarding?.role;

    return (
        <div
            data-testid="page-hint"
            className="mb-5 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-xs sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex min-w-0 items-start gap-2.5">
                <span className="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-lg bg-[var(--accent-soft)] text-[var(--accent)]">
                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <div className="min-w-0">
                    <p className="font-bold text-slate-800">{title}</p>
                    <p className="mt-0.5 leading-relaxed text-slate-500">{children}</p>
                </div>
            </div>

            {guideHref && (
                <a
                    href={guideHref}
                    className="flex-shrink-0 self-start rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-600 transition-colors hover:bg-slate-50 sm:self-auto"
                >
                    {role === 'ssa' ? 'Platform guide' : 'Show me around'}
                </a>
            )}
        </div>
    );
}

export default HelpBadge;