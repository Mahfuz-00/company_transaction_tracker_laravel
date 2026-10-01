import React, { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { usePage } from '@inertiajs/react';
import { useHints } from './HintsProvider';

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
 *
 * WHERE THE POPOVER IS PAINTED (and why it is a PORTAL)
 * ----------------------------------------------------
 * An earlier version positioned the popover with `absolute right-0` INSIDE the
 * badge's own span. That looks fine on a wide metric card and fails everywhere
 * else:
 *
 *   - inside a TABLE CELL near the right edge, a 288px panel extending leftwards
 *     was clipped by the card's `overflow-hidden`;
 *   - inside a card near the LEFT edge of the body column, it extended leftwards
 *     under the sidebar and disappeared behind it;
 *   - on a narrow viewport it ran off the screen entirely.
 *
 * The cause is that an absolutely-positioned child is confined by its ancestors'
 * overflow and stacking contexts, and the badge cannot know which ancestor will
 * impose which. The fix is to stop being a child: the panel is rendered into
 * `document.body` (a React portal) with `position: fixed` and coordinates computed
 * from the badge's own bounding box, then CLAMPED into the viewport. It therefore
 * cannot be clipped by a card, cannot sit behind the sidebar (its z-index is above
 * the shell's), and cannot leave the screen - at any size, in any container.
 *
 * A high z-index is used deliberately: it is above the fixed top bar (z-30), the
 * mobile drawer backdrop (z-40 / z-50) and the onboarding tour (z-[100]), so a hint
 * the user opened is never painted behind something else - while remaining below
 * the feedback toasts, which must win.
 */

/** The panel's preferred width. Narrowed automatically on small screens. */
const PANEL_WIDTH = 288;

/** Minimum gap between the panel and the badge / the viewport edge, in px. */
const GAP = 6;
const GUTTER = 8;

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

    /*
     * THE GLOBAL SWITCH. A user who has turned hints off sees no badge at all -
     * not a disabled one. A greyed-out control would still be visual noise on a
     * dense dashboard, which is exactly what the preference exists to remove.
     */
    const { hidden } = useHints();

    const [open, setOpen] = useState(false);
    const [pinned, setPinned] = useState(false);
    const [coords, setCoords] = useState(null);
    const containerRef = useRef(null);
    const popoverRef = useRef(null);

    // Restore a previously pinned state on mount.
    useEffect(() => {
        const stored = readPinned(hintId);
        setPinned(stored);
        if (stored) setOpen(true);
    }, [hintId]);

    /*
     * Compute the panel's viewport coordinates from the badge's own box, then
     * clamp it on BOTH axes. This runs on open, then again after paint (once the
     * panel has a measured height, so it can flip above the badge when there is no
     * room below), and on any scroll or resize while it stays open.
     */
    const positionPopover = useCallback(() => {
        if (typeof window === 'undefined') return;

        const anchor = containerRef.current;
        if (!anchor) return;

        const rect = anchor.getBoundingClientRect();
        const panel = popoverRef.current;

        // Never wider than the viewport (minus the gutters), so a small screen
        // cannot produce a horizontally overflowing panel.
        const width = Math.min(PANEL_WIDTH, Math.max(200, window.innerWidth - GUTTER * 2));
        const height = panel ? panel.offsetHeight : 0;

        // `align` only expresses a PREFERENCE; the clamp below is what guarantees
        // the panel stays on screen.
        let left = align === 'right' ? rect.right - width : rect.left;
        let top = rect.bottom + GAP;
        let placement = 'bottom';

        // Prefer below, but flip above when below would be cut off and above fits.
        if (height > 0 && top + height > window.innerHeight - GUTTER) {
            if (rect.top - GAP - height >= GUTTER) {
                top = rect.top - GAP - height;
                placement = 'top';
            }
        }

        left = Math.min(Math.max(left, GUTTER), Math.max(GUTTER, window.innerWidth - width - GUTTER));
        top = Math.min(Math.max(top, GUTTER), Math.max(GUTTER, window.innerHeight - height - GUTTER));

        setCoords((previous) => {
            if (
                previous
                && previous.left === left
                && previous.top === top
                && previous.width === width
                && previous.placement === placement
            ) {
                return previous;
            }

            return { left, top, width, placement };
        });
    }, [align]);

    useLayoutEffect(() => {
        if (!open) {
            setCoords(null);
            return undefined;
        }

        positionPopover();

        // Second pass after the panel exists, so its measured height is accurate.
        const frame = requestAnimationFrame(positionPopover);

        return () => cancelAnimationFrame(frame);
    }, [open, positionPopover]);

    // Keep it anchored while the page or an inner container scrolls.
    useEffect(() => {
        if (!open) return undefined;

        const onReflow = () => positionPopover();

        window.addEventListener('resize', onReflow);
        // `true` = capture, so scrolls inside the body column's own scroll
        // container are caught too - not only <body> scrolls.
        window.addEventListener('scroll', onReflow, true);

        return () => {
            window.removeEventListener('resize', onReflow);
            window.removeEventListener('scroll', onReflow, true);
        };
    }, [open, positionPopover]);

    // Outside-click + Escape close, but ONLY when not pinned.
    useEffect(() => {
        if (!open || pinned) return undefined;

        const onPointerDown = (event) => {
            const inBadge = containerRef.current && containerRef.current.contains(event.target);
            // The panel lives in a PORTAL on <body>, so it is NOT a DOM descendant
            // of the badge - without this check, clicking "Pin" would close the
            // panel it is trying to pin.
            const inPanel = popoverRef.current && popoverRef.current.contains(event.target);

            if (!inBadge && !inPanel) {
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

    /*
     * THE GLOBAL OFF SWITCH.
     *
     * Returning null (rather than hiding with CSS) removes the badge from the DOM,
     * so nothing is left behind to catch a click, shift a layout or appear to a
     * screen reader. That is what "turn all hints off" has to mean.
     */
    if (hidden) {
        return null;
    }

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

            {/*
              * PORTAL. See the header note: rendering into <body> with fixed
              * coordinates is what keeps the panel inside the viewport and in front
              * of the shell instead of clipped by a card or hidden behind the
              * sidebar.
              */}
            {open && typeof document !== 'undefined' && createPortal(
                <div
                    ref={popoverRef}
                    id={`${hintId}-popover`}
                    role="dialog"
                    aria-label={label || title}
                    data-testid="help-popover"
                    /*
                     * `visibility: hidden` until the first measurement lands avoids a
                     * one-frame flash at the top-left corner of the page (the default
                     * position of a fixed element with no coordinates yet).
                     */
                    style={{
                        position: 'fixed',
                        left: coords ? `${coords.left}px` : 0,
                        top: coords ? `${coords.top}px` : 0,
                        width: coords ? `${coords.width}px` : `${PANEL_WIDTH}px`,
                        visibility: coords ? 'visible' : 'hidden',
                        zIndex: 1200,
                    }}
                    className="rounded-xl border border-slate-200 bg-white p-3.5 text-left shadow-xl animate-rise"
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
                </div>,
                document.body,
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
    // Honour the global preference: an always-visible helper line is exactly the
    // kind of noise a user turns hints off to be rid of.
    const { hidden } = useHints();

    if (hidden) return null;

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

    // The page-level orientation bar is a hint like any other, so the global
    // preference silences it too.
    const { hidden } = useHints();

    if (hidden) return null;

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