/**
 * Small, shared presentational primitives used across the landing sections.
 *
 * Extracted so every section references one source for the arrow icon, the
 * centred section heading and the contact-field error wrapper - no duplication.
 */

export function ArrowRight() {
    return (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
        </svg>
    );
}

export function SectionHeading({ eyebrow, title, body }) {
    return (
        <div className="mx-auto max-w-2xl text-center">
            {eyebrow && (
                <p className="text-[11px] font-bold uppercase tracking-wider text-indigo-600">{eyebrow}</p>
            )}
            <h2 className="mt-2.5 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{title}</h2>
            <p className="mt-4 text-base leading-relaxed text-slate-600">{body}</p>
        </div>
    );
}

export function ContactField({ error, children }) {
    return (
        <div>
            {children}
            {error && <p className="mt-1.5 text-xs font-medium text-rose-200">{error}</p>}
        </div>
    );
}

/**
 * Landing entrance-animation styles.
 *
 * SINGLE SOURCE OF TRUTH: the `wa-*` keyframes and their delay utilities now
 * live in the GLOBAL stylesheet (resources/css/app.css), so BOTH the landing
 * page AND the guest/auth shell (login, register, password setup) share one
 * animation vocabulary. That was the root cause of the earlier "login looks
 * disconnected from the landing page" complaint - the auth screens had no access
 * to these classes.
 *
 * This component is retained as an explicit, self-documenting marker that the
 * landing page opts into those animations, and as the place to add any
 * LANDING-ONLY motion. It intentionally no longer re-declares the shared
 * keyframes (doing so would be a duplicate definition now that app.css owns
 * them), which is what previously risked drifting out of sync.
 */
export function LandingAnimationStyles() {
    return null;
}
