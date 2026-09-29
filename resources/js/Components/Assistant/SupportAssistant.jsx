import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import useTranslation from '@/i18n/LocaleProvider';

/** The value asserted in the UI's confidence hedge; mirrors SupportAssistant::CONFIDENT_THRESHOLD. */
const CONFIDENT_THRESHOLD = 0.45;

/**
 * THE SUPPORT ASSISTANT PANEL.
 *
 * WHERE IT IS MOUNTED
 *   - The public LANDING page (`surface="landing"`), so a visitor can ask a
 *     question before signing up.
 *   - The authenticated app shell (`surface="dashboard"`), so any role can ask
 *     without leaving the page they are on.
 *
 * THE THREE STATES OF A REPLY
 *   - MATCHED      : the answer came from the corpus. Shown plainly, with a
 *                    "from the documentation" note, plus a helpful/not-helpful
 *                    pair.
 *   - UNCERTAIN    : a match, but below the confident threshold. Shown with an
 *                    explicit hedge, so a low-confidence answer is never presented
 *                    as fact.
 *   - UNANSWERED   : no match. The assistant says so and escalates to the platform
 *                    team - which is what makes it LEARN the answer for next time.
 *
 * WHY A FLOATING PANEL AND NOT A PAGE
 *   A support question usually arises WHILE looking at the thing that confused
 *   you. Navigating away to a help page loses that context, and the user cannot
 *   see the screen they were asking about while reading the answer.
 *
 * @param {object}  props
 * @param {'landing'|'dashboard'} [props.surface]
 */
export default function SupportAssistant({ surface = 'dashboard' }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isAuthenticated = Boolean(auth?.user);

    const [open, setOpen] = useState(false);
    const [question, setQuestion] = useState('');
    const [messages, setMessages] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    /*
     * The thread token identifies a GUEST's conversation (a signed-in user's thread
     * is resolved server-side from their account). Held in a ref so it survives
     * re-renders without triggering one.
     */
    const tokenRef = useRef(null);
    const scrollRef = useRef(null);

    const scrollToEnd = useCallback(() => {
        // Defer a frame so the new bubble is in the DOM before we measure.
        requestAnimationFrame(() => {
            if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        });
    }, []);

    /*
     * Load the previous conversation when the panel first opens for a signed-in
     * user, so reopening continues where they left off rather than starting blank.
     *
     * NOTE: an earlier version called `router.reload({ only: [] })` twice here,
     * with `router` never imported. That threw `ReferenceError: router is not
     * defined` the moment a signed-in user opened the panel - killing the effect
     * and leaving the panel permanently empty. It was also unnecessary: the
     * endpoint below already returns the thread token AND the transcript, so a
     * full Inertia page reload would have been both slower and wrong.
     */
    useEffect(() => {
        if (!open || !isAuthenticated || messages.length > 0) return;

        fetch(route('assistant.history'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : { messages: [] }))
            .then((data) => {
                tokenRef.current = data.token ?? tokenRef.current;
                setMessages(data.messages ?? []);
                scrollToEnd();
            })
            .catch(() => {
                /* History is a convenience; failing to load it must not block asking. */
            });
    }, [open, isAuthenticated, messages.length, scrollToEnd]);

    const submit = async (event) => {
        event.preventDefault();

        const text = question.trim();
        if (text === '' || busy) return;

        setBusy(true);
        setError(null);

        // Show the user's own turn immediately - waiting for the round-trip to
        // echo back what they just typed feels broken.
        setMessages((current) => [...current, { role: 'user', body: text, pending: true }]);
        setQuestion('');
        scrollToEnd();

        try {
            const response = await fetch(route('assistant.ask'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    // Laravel's CSRF guard reads the token from the meta tag.
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ question: text, surface, token: tokenRef.current }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.message || 'Something went wrong. Please try again.');
                setBusy(false);
                return;
            }

            tokenRef.current = data.token ?? tokenRef.current;

            setMessages((current) => [
                ...current,
                {
                    id: data.message_id,
                    role: 'assistant',
                    body: data.answer,
                    confidence: data.confidence,
                    matched: data.matched,
                    escalated: data.escalated,
                    source: data.source,
                    flagable: data.matched,
                    helpful: null,
                },
            ]);
        } catch (e) {
            setError('We could not reach the assistant. Please try again.');
        } finally {
            setBusy(false);
            scrollToEnd();
        }
    };

    /** Record that an answer worked. */
    const markHelpful = async (message) => {
        if (!message?.id) return;

        setMessages((current) =>
            current.map((m) => (m === message ? { ...m, helpful: true, flagable: false } : m))
        );

        try {
            await fetch(route('assistant.helpful', message.id), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
            });
        } catch (e) {
            /* The rating is a quality signal, not a user-facing action. */
        }
    };

    /**
     * Report an answer as wrong.
     *
     * This ESCALATES server-side, carrying the bad answer along so the operator
     * knows whether to add an answer or rewrite one.
     */
    const flagAnswer = async (message) => {
        if (!message?.id) return;

        setMessages((current) =>
            current.map((m) =>
                m === message ? { ...m, helpful: false, flagable: false, escalated: true } : m
            )
        );

        try {
            await fetch(route('assistant.flag', message.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });
        } catch (e) {
            /* Already reflected locally; the server call is best-effort here. */
        }
    };

    return (
        <>
            {/* ---- Launcher ---- */}
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                data-testid="assistant-launcher"
                aria-expanded={open}
                aria-label={open ? 'Close the support assistant' : t('assistant.open')}
                className="fixed bottom-5 right-5 z-[60] inline-flex items-center gap-2 rounded-full bg-[var(--accent)] px-4 py-3 text-sm font-bold text-white shadow-xl transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)] focus-visible:ring-offset-2"
            >
                {open ? (
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                ) : (
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth="1.9"
                            d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-4 4v-4z"
                        />
                    </svg>
                )}
                <span className="hidden sm:inline">
                    {open ? t('common.close') : t('assistant.open')}
                </span>
            </button>

            {/* ---- Panel ---- */}
            {open && (
                <div
                    data-testid="assistant-panel"
                    role="dialog"
                    aria-label={t('assistant.title')}
                    className="fixed bottom-20 right-5 z-[60] flex max-h-[32rem] w-[min(24rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border bg-[var(--surface)] shadow-2xl"
                    style={{ borderColor: 'var(--border-color)' }}
                >
                    {/* Header */}
                    <div
                        className="flex items-start justify-between gap-3 border-b px-4 py-3"
                        style={{ borderColor: 'var(--border-color)' }}
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-bold text-slate-900">
                                {t('assistant.title')}
                            </p>
                            <p className="mt-0.5 text-[11px] leading-snug text-slate-500">
                                Answers come from the platform documentation. Anything I cannot
                                answer goes straight to the platform team.
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            aria-label="Close"
                            data-testid="assistant-close"
                            className="flex-shrink-0 rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Transcript */}
                    <div ref={scrollRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4">
                        {messages.length === 0 && (
                            <p className="text-xs leading-relaxed text-slate-500">
                                Ask anything about recording meals, deposits, reports or how the
                                platform calculates a figure. For example, &ldquo;how is the meal
                                price calculated?&rdquo;
                            </p>
                        )}

                        {messages.map((message, index) => (
                            <Bubble
                                key={message.id ?? `local-${index}`}
                                message={message}
                                onHelpful={() => markHelpful(message)}
                                onFlag={() => flagAnswer(message)}
                            />
                        ))}

                        {busy && (
                            <p
                                data-testid="assistant-thinking"
                                className="flex items-center gap-2 text-xs font-medium text-slate-400"
                            >
                                <svg className="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                                {t('assistant.thinking')}
                            </p>
                        )}
                    </div>

                    {/* Composer */}
                    <form
                        onSubmit={submit}
                        className="border-t px-3 py-3"
                        style={{ borderColor: 'var(--border-color)' }}
                    >
                        {error && (
                            <p role="alert" className="mb-2 text-[11px] font-medium text-rose-600">
                                {error}
                            </p>
                        )}

                        <div className="flex items-end gap-2">
                            <textarea
                                rows={1}
                                value={question}
                                data-testid="assistant-input"
                                onChange={(e) => setQuestion(e.target.value)}
                                onKeyDown={(e) => {
                                    // Enter sends; Shift+Enter makes a newline, which is what
                                    // anyone pasting a multi-line question expects.
                                    if (e.key === 'Enter' && !e.shiftKey) {
                                        e.preventDefault();
                                        submit(e);
                                    }
                                }}
                                placeholder={t('assistant.placeholder')}
                                className="max-h-24 min-h-[2.5rem] flex-1 resize-none rounded-xl border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition-all focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20 placeholder:text-slate-400"
                            />

                            <button
                                type="submit"
                                disabled={busy || question.trim() === ''}
                                data-testid="assistant-send"
                                className="inline-flex h-10 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--accent)] px-4 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                            >
                                {t('assistant.send')}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}

/** One message bubble, with the feedback controls for assistant replies. */
function Bubble({ message, onHelpful, onFlag }) {
    const { t } = useTranslation();

    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <p
                    data-testid="assistant-user-message"
                    className="max-w-[85%] whitespace-pre-line rounded-2xl rounded-br-md bg-[var(--accent)] px-3.5 py-2 text-xs leading-relaxed text-white"
                >
                    {message.body}
                </p>
            </div>
        );
    }

    /*
     * A low-confidence answer is HEDGED rather than stated flatly. The threshold
     * mirrors SupportAssistant::CONFIDENT_THRESHOLD, so the UI and the engine agree
     * about what "confident" means.
     */
    const uncertain = message.matched && (message.confidence ?? 0) < CONFIDENT_THRESHOLD;

    return (
        <div className="flex flex-col items-start gap-1.5">
            <div
                data-testid="assistant-answer"
                className="max-w-[92%] whitespace-pre-line rounded-2xl rounded-bl-md border px-3.5 py-2.5 text-xs leading-relaxed text-slate-700"
                style={{ borderColor: 'var(--border-color)' }}
            >
                {message.body}
            </div>

            {/* Provenance: only claimed when the answer really came from the corpus. */}
            {message.matched && (
                <p className="text-[10px] font-medium text-slate-400">
                    {t('assistant.from_docs')}
                </p>
            )}

            {uncertain && (
                <p className="text-[10px] font-medium text-amber-600">
                    I am not fully certain this is the answer you need — flag it below if it is
                    wrong and the team will follow up.
                </p>
            )}

            {message.escalated && (
                <p
                    data-testid="assistant-escalated"
                    className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[10px] font-medium text-amber-800"
                >
                    {t('assistant.escalated')}
                </p>
            )}

            {/* Feedback controls: only on a real answer that has not been rated. */}
            {message.flagable && (
                <div className="flex items-center gap-2">
                    <span className="text-[10px] text-slate-400">
                        {t('assistant.helpful')}
                    </span>

                    <button
                        type="button"
                        onClick={onHelpful}
                        data-testid="assistant-helpful"
                        className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 transition-colors hover:bg-emerald-100"
                    >
                        {t('assistant.yes')}
                    </button>

                    <button
                        type="button"
                        onClick={onFlag}
                        data-testid="assistant-unhelpful"
                        className="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700 transition-colors hover:bg-rose-100"
                    >
                        {t('assistant.no')}
                    </button>
                </div>
            )}

            {message.helpful === true && (
                <p className="text-[10px] font-medium text-emerald-600">Thanks — noted.</p>
            )}

            {message.helpful === false && !message.escalated && (
                <p className="text-[10px] font-medium text-rose-600">
                    Sorry about that — passed to the team.
                </p>
            )}
        </div>
    );
}