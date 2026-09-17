import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
// NOTE: we deliberately do NOT import or call `usePage()` here.
//
// FeedbackProvider is mounted ABOVE the Inertia <App /> component in app.jsx.
// Inertia's page context is provided BY <App>, so calling usePage() in a
// component that renders above it throws:
//
//     "usePage must be used within the Inertia component"
//
// Instead we read flash messages from the Inertia ROUTER (a plain event
// emitter that is global, not context-bound). That works from anywhere in the
// tree - including above <App /> - and never touches React context.
import { router } from '@inertiajs/react';

/**
 * Centralised software alert system.
 *
 * Replaces every native browser `alert()` / `confirm()` / `prompt()` with an
 * elegant, centre-of-screen modal for confirmations and a stack of toasts in
 * the top-right for transient notices. Everything is driven from one context so
 * that any component can call `useFeedback()` and stay consistent.
 *
 *   const { confirm, alert, toast, success, error } = useFeedback();
 *
 *   await confirm({ title: 'Delete vendor?', tone: 'danger', confirmLabel: 'Delete' });
 *   toast.success('Saved');
 *
 * `confirm()` returns a Promise<boolean>, so call sites read naturally:
 *
 *   if (await confirm({ ... })) { router.delete(...) }
 */

const FeedbackContext = createContext(null);

let idCounter = 0;
const nextId = () => `fb-${++idCounter}`;

/* ------------------------------------------------------------------ *
 * Icons
 * ------------------------------------------------------------------ */

const ICONS = {
    success: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
    ),
    error: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    ),
    warning: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
    ),
    info: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    ),
};

const TONES = {
    success: {
        ring: 'border-emerald-200',
        icon: 'bg-emerald-50 text-emerald-600',
        button: 'bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800',
    },
    error: {
        ring: 'border-rose-200',
        icon: 'bg-rose-50 text-rose-600',
        button: 'bg-rose-600 hover:bg-rose-700 active:bg-rose-800',
    },
    danger: {
        ring: 'border-rose-200',
        icon: 'bg-rose-50 text-rose-600',
        button: 'bg-rose-600 hover:bg-rose-700 active:bg-rose-800',
    },
    warning: {
        ring: 'border-amber-200',
        icon: 'bg-amber-50 text-amber-600',
        button: 'bg-amber-600 hover:bg-amber-700 active:bg-amber-800',
    },
    info: {
        ring: 'border-sky-200',
        icon: 'bg-sky-50 text-sky-600',
        button: 'bg-sky-600 hover:bg-sky-700 active:bg-sky-800',
    },
    accent: {
        ring: 'border-[var(--accent)]/30',
        icon: 'bg-[var(--accent-soft)] text-[var(--accent)]',
        button: 'bg-[var(--accent)] hover:opacity-90',
    },
};

const toneFor = (tone) => TONES[tone] || TONES.accent;

/* ------------------------------------------------------------------ *
 * Toast
 * ------------------------------------------------------------------ */

function ToastItem({ toast, onDismiss }) {
    const tone = toast.tone === 'error' ? 'error' : toast.tone;

    // Colours are keyed off tone but a toast is quieter than a modal.
    const palette = {
        success: { bar: 'bg-emerald-500', icon: 'text-emerald-600', ring: 'border-emerald-100' },
        error: { bar: 'bg-rose-500', icon: 'text-rose-600', ring: 'border-rose-100' },
        warning: { bar: 'bg-amber-500', icon: 'text-amber-600', ring: 'border-amber-100' },
        info: { bar: 'bg-sky-500', icon: 'text-sky-600', ring: 'border-sky-100' },
    }[tone] || { bar: 'bg-[var(--accent)]', icon: 'text-[var(--accent)]', ring: 'border-slate-200' };

    const iconKey = tone === 'error' ? 'error' : tone;

    return (
        <div
            role="status"
            className="pointer-events-auto relative flex w-80 max-w-[calc(100vw-2rem)] items-start gap-3 overflow-hidden rounded-xl border-slate-200 bg-white p-4 pr-3 shadow-lg animate-rise"
        >
            <span className={`absolute inset-y-0 left-0 w-1 ${palette.bar}`} aria-hidden="true" />

            <span className={`mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full ${palette.icon}`}>
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {ICONS[iconKey] || ICONS.info}
                </svg>
            </span>

            <div className="min-w-0 flex-1 pl-1">
                {toast.title && (
                    <p className="text-sm font-bold text-slate-900">{toast.title}</p>
                )}
                {toast.message && (
                    <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{toast.message}</p>
                )}
            </div>

            <button
                type="button"
                onClick={() => onDismiss(toast.id)}
                aria-label="Dismiss notification"
                className="flex-shrink-0 rounded-lg p-1 text-slate-300 transition-colors hover:bg-slate-100 hover:text-slate-500"
            >
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Modal
 * ------------------------------------------------------------------ */

function FeedbackModal({ modal, onResolve }) {
    const dialogRef = useRef(null);

    // Focus the primary action so keyboard users can confirm immediately.
    useEffect(() => {
        const previous = document.activeElement;
        dialogRef.current?.focus();
        return () => previous?.focus?.();
    }, []);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onResolve(modal.kind === 'modal', modal.cancelValue);
            if (event.key === 'Enter' && modal.kind === 'modal') onResolve(true, modal.confirmValue);
        };
        document.addEventListener('keydown', onKeyDown);

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [modal, onResolve]);

    const tone = toneFor(modal.tone);
    const iconKey = modal.tone === 'danger' ? 'error' : modal.tone;

    return (
        <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in"
            role="dialog"
            aria-modal="true"
            aria-labelledby="feedback-modal-title"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onResolve(modal.kind === 'modal', modal.cancelValue);
                }
            }}
        >
            <div
                ref={dialogRef}
                tabIndex={-1}
                className="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl outline-none animate-rise"
            >
                <div className="flex gap-4 p-6">
                    <span className={`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full ${tone.icon}`}>
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            {ICONS[iconKey] || ICONS.info}
                        </svg>
                    </span>

                    <div className="min-w-0 flex-1">
                        <h3 id="feedback-modal-title" className="text-base font-bold text-slate-900">
                            {modal.title}
                        </h3>
                        {modal.message && (
                            <p className="mt-1.5 text-sm leading-relaxed text-slate-600">
                                {modal.message}
                            </p>
                        )}

                        {modal.kind === 'prompt' && (
                            <input
                                autoFocus
                                type="text"
                                value={modal.inputValue}
                                onChange={(event) => modal.setInputValue(event.target.value)}
                                placeholder={modal.placeholder}
                                className="mt-3 w-full rounded-lg border-slate-300 px-3.5 py-2 text-sm text-slate-900 outline-none transition-all focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent-ring)]"
                            />
                        )}
                    </div>
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end">
                    {modal.kind !== 'alert' && (
                        <button
                            type="button"
                            onClick={() => onResolve(modal.kind === 'modal', modal.cancelValue)}
                            className="rounded-lg border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-white"
                        >
                            {modal.cancelLabel}
                        </button>
                    )}
                    <button
                        type="button"
                        onClick={() => onResolve(true, modal.kind === 'prompt' ? modal.inputValue : modal.confirmValue)}
                        className={`rounded-lg px-5 py-2 text-sm font-semibold text-white shadow-sm transition-all ${tone.button}`}
                    >
                        {modal.confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ *
 * Provider
 * ------------------------------------------------------------------ */

export function FeedbackProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const [modal, setModal] = useState(null);
    const resolverRef = useRef(null);

    const dismissToast = useCallback((id) => {
        setToasts((current) => current.filter((t) => t.id !== id));
    }, []);

    const pushToast = useCallback((options) => {
        const id = nextId();
        const toast = {
            id,
            tone: options.tone || 'info',
            title: options.title || null,
            message: options.message || null,
        };

        setToasts((current) => [...current, toast]);

        const duration = options.duration ?? 4500;
        if (duration > 0) {
            setTimeout(() => dismissToast(id), duration);
        }

        return id;
    }, [dismissToast]);

    /**
     * Open a modal and resolve with the user's choice.
     * `kind` is 'modal' | 'alert' | 'prompt'.
     */
    const openModal = useCallback((options) => {
        return new Promise((resolve) => {
            resolverRef.current = resolve;
            setModal({
                ...options,
                inputValue: options.defaultValue ?? '',
                setInputValue: (value) =>
                    setModal((current) => (current ? { ...current, inputValue: value } : current)),
            });
        });
    }, []);

    const resolveModal = useCallback((confirmed, value) => {
        const resolve = resolverRef.current;
        resolverRef.current = null;
        setModal(null);
        resolve?.(confirmed && value !== undefined ? value : confirmed);
    }, []);

    /* --- Public API ------------------------------------------------- */

    const confirm = useCallback((options = {}) => openModal({
        kind: 'modal',
        tone: options.tone || 'warning',
        title: options.title || 'Are you sure?',
        message: options.message,
        confirmLabel: options.confirmLabel || 'Confirm',
        cancelLabel: options.cancelLabel || 'Cancel',
        confirmValue: true,
        cancelValue: false,
    }), [openModal]);

    const alert = useCallback((options = {}) => openModal({
        kind: 'alert',
        tone: options.tone || 'info',
        title: options.title || 'Notice',
        message: typeof options === 'string' ? options : options.message,
        confirmLabel: options.confirmLabel || 'Got it',
        confirmValue: true,
        cancelValue: false,
    }), [openModal]);

    const prompt = useCallback((options = {}) => openModal({
        kind: 'prompt',
        tone: options.tone || 'info',
        title: options.title || 'Enter a value',
        message: options.message,
        placeholder: options.placeholder,
        defaultValue: options.defaultValue,
        confirmLabel: options.confirmLabel || 'Submit',
        cancelLabel: options.cancelLabel || 'Cancel',
        confirmValue: true,
        cancelValue: false,
    }), [openModal]);

    const toast = useMemo(() => ({
        success: (title, message) => pushToast({ tone: 'success', title, message }),
        error: (title, message) => pushToast({ tone: 'error', title, message }),
        warning: (title, message) => pushToast({ tone: 'warning', title, message }),
        info: (title, message) => pushToast({ tone: 'info', title, message }),
        show: pushToast,
    }), [pushToast]);

    /**
     * Bridge Laravel flash messages into toasts, so every existing
     * ->with('success', ...) / ->with('error', ...) surfaces the new way with
     * no controller changes required.
     *
     * We read flash from the Inertia ROUTER's `success` event rather than
     * usePage(). The router is a global emitter, so this subscription works even
     * though the provider is mounted above <App /> - which is precisely why the
     * old usePage() call crashed with "usePage must be used within the Inertia
     * component".
     */
    useEffect(() => {
        const showFlash = (page) => {
            const flash = page?.props?.flash;
            if (!flash) return;

            if (flash.success) pushToast({ tone: 'success', title: 'Success', message: flash.success });
            if (flash.error) pushToast({ tone: 'error', title: 'Something went wrong', message: flash.error });
            if (flash.status) pushToast({ tone: 'info', title: 'Notice', message: flash.status });
        };

        // Fires on every successful Inertia visit (including the first).
        const offSuccess = router.on('success', (event) => {
            showFlash(event.detail.page);
        });

        // Cover the very first render: the initial page props are already on
        // router.page by the time this effect runs, so any flash set on a
        // full-page load is not missed.
        showFlash(router.page);

        return () => offSuccess();
    }, [pushToast]);

    const value = useMemo(
        () => ({ confirm, alert, prompt, toast, success: toast.success, error: toast.error }),
        [confirm, alert, prompt, toast]
    );

    return (
        <FeedbackContext.Provider value={value}>
            {children}

            {/* Toast stack - top-right, above everything but the modal. */}
            <div className="pointer-events-none fixed right-4 top-4 z-[110] flex-col gap-2">
                {toasts.map((t) => (
                    <ToastItem key={t.id} toast={t} onDismiss={dismissToast} />
                ))}
            </div>

            {modal && <FeedbackModal modal={modal} onResolve={resolveModal} />}
        </FeedbackContext.Provider>
    );
}

/**
 * Access the feedback API. Falls back to safe no-ops when rendered outside the
 * provider (e.g. in a unit test) rather than throwing.
 */
export function useFeedback() {
    const ctx = useContext(FeedbackContext);

    if (ctx) return ctx;

    const noop = async (options = {}) => {
        // Degrade gracefully: without the provider, alarms go to the console
        // rather than silently disappearing.
        if (typeof window !== 'undefined' && options.title) {
            // eslint-disable-next-line no-console
            console.warn('[feedback]', options.title, options.message || '');
        }
        return false;
    };

    return {
        confirm: noop,
        alert: noop,
        prompt: noop,
        toast: {
            success: () => { },
            error: () => { },
            warning: () => { },
            info: () => { },
            show: () => { },
        },
    };
}

export default FeedbackProvider;
