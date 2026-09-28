import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeProvider from '@/Components/ThemeProvider';
import usePlatformBranding from '@/Utils/usePlatformBranding';
import { Link } from '@inertiajs/react';

/**
 * AUTH SPLIT LAYOUT — the shared two-column shell for Login and Register.
 *
 * LAYOUT
 *   `[ LEFT: light introduction panel ] [ RIGHT: the authentication form ]`
 *
 *   - LEFT (lg+ only): a LIGHT, software-appropriate introduction panel — brand
 *     lockup, headline, capability list and a trust strip — over a soft,
 *     slowly-drifting ambient gradient. It is decorative (`aria-hidden`) and
 *     collapses entirely below `lg`, where a half-width panel would be unusable
 *     and the form must own the full viewport.
 *   - RIGHT: the primary authentication surface. It renders `children` (the
 *     credential form) and, strictly BELOW it, the `sso` slot, plus an optional
 *     `footer` link.
 *
 * WHY THE LEFT PANEL IS LIGHT (not the old dark slate-950 panel)
 *   The previous version was a near-black panel. The product is a finance tool
 *   for shared mess halls and canteens — it should read as calm and trustworthy,
 *   not as a hacker terminal. A light panel with a soft accent wash matches the
 *   in-app surfaces, so the login screen and the app behind it look like one
 *   continuous product rather than two different designs.
 *
 * ANIMATION
 *   Motion is CSS-only (`ap-*` utilities in resources/css/app.css — see the
 *   "AUTH SPLIT PANEL" block). There is deliberately no Framer Motion dependency:
 *   the app ships zero animation libraries, so the panel uses the same technique
 *   as the landing page. Each element animates opacity/transform only and every
 *   animation is disabled under `prefers-reduced-motion`.
 *
 * SSO PLACEMENT CONTRACT
 *   The SSO block is a SEPARATE slot (`sso`), rendered strictly AFTER `children`.
 *   That ordering is structural — a caller cannot accidentally place the provider
 *   buttons above the form, which is the bug this layout exists to prevent.
 *
 * BRANDING
 *   Every value comes from usePlatformBranding() (`config/platform.php`), so
 *   renaming the product updates this panel with no component edit. The panel
 *   shows PLATFORM branding, never a tenant's — a public visitor has not been
 *   mapped to a workspace yet.
 *
 * @param {object}  props
 * @param {string}  props.heading       - the form heading ("Welcome back")
 * @param {string} [props.subheading]   - the line under the heading
 * @param {node}   [props.footer]       - a slot under the form card (e.g. "Register instead")
 * @param {node}   [props.sso]          - the SSO/OAuth block, rendered BELOW the form
 * @param {string} [props.introTitle]   - left-panel headline override
 * @param {string} [props.introBody]    - left-panel supporting paragraph
 * @param {node}    props.children      - the authentication form itself
 */
export default function AuthSplitLayout({
    heading,
    subheading,
    footer,
    sso = null,
    introTitle = 'Meals, deposits and dues — settled automatically.',
    introBody = 'One clear per-meal rate for your whole institution. No spreadsheets, no guesswork.',
    children,
}) {
    const { name, tagline, logoUrl } = usePlatformBranding();

    return (
        <ThemeProvider>
            <div className="flex min-h-screen bg-white">
                {/* ---------------- LEFT: light introduction panel (desktop only) ---------------- */}
                <aside
                    aria-hidden="true"
                    className="relative hidden w-1/2 flex-col justify-between overflow-hidden border-r border-slate-200/60 bg-white px-12 py-12 lg:flex xl:px-16"
                >
                    {/*
                     * Ambient wash: ONE soft accent gradient plus a single
                     * slowly-drifting blob. Deliberately restrained — the panel is
                     * whitespace-led, and its job is to stay out of the way of the
                     * form rather than compete with it.
                     *
                     * `bg-gradient-to-br from-white via-white to-indigo-50/70`
                     * keeps the field genuinely LIGHT (near-white), so the panel
                     * reads as clean space rather than a coloured block.
                     */}
                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white via-white to-indigo-50/70" />
                    <div className="ap-drift pointer-events-none absolute -left-32 top-1/4 h-[28rem] w-[28rem] rounded-full bg-indigo-300/15 blur-3xl" />

                    {/* Brand lockup — staggered entrance #0 */}
                    <div className="ap-stagger relative z-10 flex items-center gap-3" style={{ '--ap-index': 0 }}>
                        {logoUrl ? (
                            <img
                                src={logoUrl}
                                alt={name}
                                className="h-10 w-10 rounded-xl object-contain ring-1 ring-slate-900/5"
                            />
                        ) : (
                            <ApplicationLogo className="h-10 w-10 rounded-xl object-contain ring-1 ring-slate-900/5" />
                        )}
                        <div>
                            <p className="text-base font-bold tracking-tight text-slate-900">{name}</p>
                            <p className="text-xs font-medium text-slate-500">{tagline}</p>
                        </div>
                    </div>

                    {/* Concise value proposition + three short capabilities. */}
                    <div className="relative z-10 max-w-sm">
                        <h2 className="ap-sheen text-3xl font-bold leading-tight tracking-tight text-slate-900 xl:text-[2.5rem] xl:leading-[1.15]">
                            {introTitle}
                        </h2>

                        <p
                            className="ap-stagger mt-4 text-sm leading-relaxed text-slate-600"
                            style={{ '--ap-index': 1 }}
                        >
                            {introBody}
                        </p>

                        <ul className="mt-9 space-y-4">
                            <Feature
                                index={2}
                                title="One rate, every member"
                                body="Expenses ÷ meals, applied to every balance."
                            />
                            <Feature
                                index={3}
                                title="Separate tenant workspaces"
                                body="Your institution's data stays yours alone."
                            />
                            <Feature
                                index={4}
                                title="Deposits, subsidies, vendors"
                                body="One ledger, fully reconciled."
                            />
                        </ul>
                    </div>

                    {/* Trust strip */}
                    <div
                        className="ap-stagger relative z-10 flex flex-wrap items-center gap-x-6 gap-y-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400"
                        style={{ '--ap-index': 5 }}
                    >
                        <span className="inline-flex items-center gap-2">
                            <ShieldIcon /> Encrypted credentials
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <ShieldIcon /> Role-based access
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <ShieldIcon /> Full audit trail
                        </span>
                    </div>
                </aside>

                {/* ---------------- RIGHT: authentication form ---------------- */}
                <main className="flex w-full flex-col justify-center px-5 py-10 sm:px-10 lg:w-1/2 lg:px-14 xl:px-20">
                    {/*
                     * Language switcher, top-right of the form column.
                     *
                     * A guest has no account yet, so this is their ONLY chance to
                     * read the sign-in screen in their own language. It writes the
                     * session (see LanguageController::setGuest) and their choice is
                     * then persisted to their account the moment they sign in.
                     */}
                    <div className="mb-4 flex justify-end lg:mb-6">
                        <LanguageSwitcher />
                    </div>

                    {/* On mobile the panel is hidden, so show a compact lockup above
                        the form instead — the screen must still identify itself. */}
                    <div className="mb-8 flex items-center justify-center gap-3 lg:hidden">
                        <Link href="/" className="flex items-center gap-3">
                            {logoUrl ? (
                                <img src={logoUrl} alt={name} className="h-10 w-10 rounded-xl object-contain" />
                            ) : (
                                <ApplicationLogo className="h-10 w-10 rounded-xl object-contain" />
                            )}
                            <span className="text-lg font-bold tracking-tight text-slate-900">{name}</span>
                        </Link>
                    </div>

                    <div className="ap-stagger mx-auto w-full max-w-md" style={{ '--ap-index': 0 }}>
                        {heading && (
                            <div className="mb-7">
                                <h1 className="text-2xl font-bold tracking-tight text-slate-900">{heading}</h1>
                                {subheading && <p className="mt-2 text-sm text-slate-500">{subheading}</p>}
                            </div>
                        )}

                        {/* 1. THE FORM. Always first. */}
                        {children}

                        {/*
                         * 2. SSO / OAUTH. A dedicated slot rendered strictly BELOW
                         * the form — the placement is enforced by this layout, not
                         * by each page remembering to do it.
                         */}
                        {sso}

                        {footer && <div className="mt-7">{footer}</div>}
                    </div>
                </main>
            </div>
        </ThemeProvider>
    );
}

/** One capability row in the introduction panel, staggered by index. */
function Feature({ title, body, index = 0 }) {
    return (
        <li className="ap-stagger flex gap-3.5" style={{ '--ap-index': index }}>
            <span className="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-500/10 ring-1 ring-indigo-500/20">
                <svg className="h-3.5 w-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.4" d="M5 13l4 4L19 7" />
                </svg>
            </span>
            <span>
                <span className="block text-sm font-semibold text-slate-900">{title}</span>
                <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{body}</span>
            </span>
        </li>
    );
}

/** Small shield glyph used by the trust strip. */
function ShieldIcon() {
    return (
        <svg className="h-3.5 w-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z" />
        </svg>
    );
}