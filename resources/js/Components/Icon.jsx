import React from 'react';

/**
 * Single source of icon paths for the sidebar (and anywhere else).
 *
 * Icons are stored as path data only, so the shell (svg + viewBox + stroke
 * settings) lives in one place. Add a key here and reference it by name
 * from Utils/navItems.js.
 *
 * ICON-UNIQUENESS RULE
 *   Every module in `Utils/navItems.js` must name a DISTINCT glyph. Two
 *   different destinations sharing one icon makes the sidebar unreadable - the
 *   user cannot tell "Deposits" from "Refunds" at a glance. The audit for this
 *   is mechanical:
 *
 *     node scripts/check-nav-icons.mjs
 *
 *   It fails if a nav item references an undefined icon, or if two different
 *   routes point at the same one. Add the new key here, then re-run it.
 */
const ICONS = {
    dashboard: {
        fill: true,
        paths: [
            'M3 13h8V3H3v10zM3 21h8v-6H3v6zM13 21h8V11h-8v10zM13 3v6h8V3h-8z',
        ],
    },
    analytics: {
        paths: ['M3 3v18h18', 'M18 17V9', 'M13 17V5', 'M8 17v-3'],
    },
    chart: {
        paths: [
            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        ],
    },
    plus: {
        paths: ['M12 5v14M5 12h14'],
        strokeWidth: 2.2,
    },
    store: {
        paths: [
            'M3 9l1.5-5h15L21 9M3 9v10a1 1 0 001 1h16a1 1 0 001-1V9M3 9h18M9 20v-6h6v6',
        ],
    },
    users: {
        paths: [
            'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-6.93 4 4 0 004 6.93zm6-2a3 3 0 10-2-5.65M9 7a3 3 0 11-6 0 3 3 0 016 0z',
        ],
    },
    building: {
        paths: [
            'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        ],
    },
    download: {
        paths: [
            'M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2',
        ],
    },
    receipt: {
        paths: [
            'M20 12V8H6a2 2 0 010-4h12v4m0 4v4H6a2 2 0 000 4h12v-4m0-4h-4a2 2 0 000 4h4v-4z',
        ],
    },
    mail: {
        paths: [
            'M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
            'M3 8l9 6 9-6',
        ],
    },
    /** Chevron for collapsible nav groups (used by Sidebar.jsx, not a nav item). */
    chevronDown: {
        paths: ['M19 9l-7 7-7-7'],
    },

    /* ------------------------------------------------------------------ *
     * MODULE ICONS.
     *
     * Every sidebar module must own a DISTINCT glyph. These were added because
     * several modules previously shared one icon (three different items all used
     * `bank`, four used `clipboard`), which made the sidebar unreadable - two
     * different destinations looked identical at a glance.
     *
     * Run `npm run lint:nav-icons` after touching this map or navItems.js: it
     * fails on an undefined icon and on two modules sharing one.
     * ------------------------------------------------------------------ */

    /** Make a Payment / Payment Verification - a wallet, not a coin stack. */
    cash: {
        paths: [
            'M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
            'M16 12h.01',
            'M3 9.5h18',
        ],
    },

    /** Meal Voting / Meal Menus - cutlery (fork + knife). */
    utensils: {
        paths: [
            'M7 3v7a2 2 0 002 2v9M7 3v4M11 3v4M11 12a2 2 0 01-2 2',
            'M17 3c-1.5 0-2.5 1.5-2.5 4s1 4 2.5 4v10',
        ],
    },

    /** Anomaly Monitor - a warning triangle (something needs a look). */
    alert: {
        paths: [
            'M12 4l9 15.5a1 1 0 01-.87 1.5H3.87A1 1 0 013 19.5L12 4z',
            'M12 10v4M12 17.5h.01',
        ],
    },

    /** Settings groups (Settings / Platform Settings) - a cog. */
    cog: {
        paths: [
            'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z',
        ],
        circles: [{ cx: 12, cy: 12, r: 3 }],
    },

    /** Deposit (Cash In) - a down arrow into a tray. */
    deposit: {
        paths: [
            'M12 3v10m0 0l-4-4m4 4l4-4M4 15v4a2 2 0 002 2h12a2 2 0 002-2v-4',
        ],
    },

    /** Meal Entries (member view) - a fork, spoon and knife. */
    mealEntries: {
        paths: [
            'M7 3v6a2 2 0 002 2v10M7 3v3M11 3v3',
            'M16 3a3 3 0 013 3v4h-3v11',
        ],
    },

    /** Member Summary (personal home) - a home glyph, distinct from the staff dashboard. */
    home: {
        paths: [
            'M3 11l9-7 9 7M5.5 9.8V20a1 1 0 001 1H10v-6h4v6h3.5a1 1 0 001-1V9.8',
        ],
    },

    /** Business Dashboard (SSA platform view) - a globe, distinct from the tenant dashboard. */
    globe: {
        paths: [
            'M12 21a9 9 0 100-18 9 9 0 000 18z',
            'M3.5 9h17M3.5 15h17M12 3c2.5 2.5 3.8 5.5 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.5-3.8-9S9.5 5.5 12 3z',
        ],
    },

    /** Report Builder (composing a saved query) - a document with a plus. */
    reportBuilder: {
        paths: [
            'M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z',
            'M14 3v5h5M12 11v6M9 14h6',
        ],
    },

    /** Analytics (tenant org-wide) - a pie chart slice. */
    pieChart: {
        paths: [
            'M12 3a9 9 0 109 9h-9V3z',
            'M12 3a9 9 0 016.4 2.6L12 12',
        ],
    },

    /** Payment Verification (manager queue) - a clipboard with a tick. */
    verifyList: {
        paths: [
            'M9 4H7a2 2 0 00-2 2v13a2 2 0 002 2h10a2 2 0 002-2V6a2 2 0 00-2-2h-2',
            'M9 4a2 2 0 012-2h2a2 2 0 012 2H9zM9.5 13l1.8 1.8 3.7-3.7',
        ],
    },

    /** Broadcasts (institution-scoped announcements) - a speech bubble. */
    announce: {
        paths: [
            'M21 11.5a8 8 0 01-8 8H8l-5 3 1.3-4.5A8 8 0 1121 11.5z',
            'M8.5 11.5h7M8.5 8.5h4',
        ],
    },

    /** Departments (workspace groups) - a sitemap / org tree. */
    sitemap: {
        paths: [
            'M9 3h6v4H9zM3 17h6v4H3zM15 17h6v4h-6z',
            'M12 7v4M6 17v-3h12v3M12 11v3',
        ],
    },

    /** SaaS Analytics (platform MRR/ARR) - a trending-up line. */
    trendingUp: {
        paths: [
            'M3 17l6-6 4 4 8-8',
            'M15 7h6v6',
        ],
    },

    /** My Claims (member view) - a speech bubble with a question mark. */
    claimBubble: {
        paths: [
            'M21 12a8 8 0 01-8 8H8l-5 3 1.2-4.4A8 8 0 1121 12z',
            'M10.5 10a1.6 1.6 0 113 0c0 1.1-1.5 1.3-1.5 2.5M12 15.5h.01',
        ],
    },

    /** Institution Directory (platform registry) - a city skyline. */
    city: {
        paths: [
            'M3 21V9l5-3v15M8 21V11l6-3v13M14 21V12l7 3v6M3 21h18',
            'M5.5 12h.01M5.5 15h.01M11 14h.01M11 17h.01M17 17h.01',
        ],
    },

    /** Currency Manager (one workspace's format) - a banknote with a coin. */
    coins: {
        paths: [
            'M15.5 6.5a5.5 5.5 0 11-11 0 5.5 5.5 0 0111 0z',
            'M10 4.2v4.6M8 5.2h3a1 1 0 010 2H8.5a1 1 0 000 2H12M14 12.5a5.5 5.5 0 01-9.9 3.4',
        ],
    },

    /** User Manager (accounts + roles) - two people. */
    userCog: {
        paths: [
            'M14 20v-1.5a4 4 0 00-4-4H6a4 4 0 00-4 4V20',
            'M8 11a4 4 0 100-8 4 4 0 000 8z',
            'M18.5 12.5l.9 1.9 2 .3-1.5 1.4.4 2-1.8-1-1.8 1 .4-2-1.5-1.4 2-.3.9-1.9z',
        ],
    },

    /** Subsidy Sources (the funding catalogue) - a folder of grants. */
    grantFolder: {
        paths: [
            'M3 7a2 2 0 012-2h3.6l1.6 2H19a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
            'M9 14h6M12 11v6',
        ],
    },

    /** Platform Settings (SSA global config) - sliders. */
    sliders: {
        paths: [
            'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0',
            'M16 6a2 2 0 11-4 0 2 2 0 014 0zM8 12a2 2 0 11-4 0 2 2 0 014 0zM20 18a2 2 0 11-4 0 2 2 0 014 0z',
        ],
    },

    /** Meal Menus (staff-side management) - a menu board with cutlery. */
    menuBoard: {
        paths: [
            'M6 3h12a2 2 0 012 2v16l-4-2.5L12 21l-4-2.5L4 21V5a2 2 0 012-2z',
            'M9 8h6M9 12h6',
        ],
    },

    /** Subscription & Billing - a card, distinct from the `bank` institution icon. */
    creditCard: {
        paths: [
            'M2 8a2 2 0 012-2h16a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V8z',
            'M2 10.5h20M6 15h3',
        ],
    },

    /** Currency & FX Rates - a currency exchange (two opposing arrows). */
    exchange: {
        paths: [
            'M4 8h13l-3-3M20 16H7l3 3',
        ],
    },

    /** Pricing & Plans - a price tag. */
    tag: {
        paths: [
            'M3 12V5a2 2 0 012-2h7l9 9-9 9-9-9z',
            'M7.5 7.5h.01',
        ],
    },

    /** Security & Audit - a lock (audit trail / security posture). */
    lock: {
        paths: [
            'M7 11V8a5 5 0 0110 0v3',
            'M5 11h14v9a2 2 0 01-2 2H7a2 2 0 01-2-2v-9z',
        ],
    },

    /** Landing Enquiries - an inbox tray, distinct from the `mail` envelope. */
    inbox: {
        paths: [
            'M4 4h16v16H4z',
            'M4 13h4l1.5 3h5L16 13h4',
        ],
    },

    /** Broadcasts - a megaphone (announcements). */
    megaphone: {
        paths: [
            'M3 11v3a1 1 0 001 1h2l3 4V6L6 10H4a1 1 0 00-1 1z',
            'M12 8a5 5 0 010 8M15 5a9 9 0 010 14',
        ],
    },

    /**
     * Bug Reports (SSA triage inbox) - a beetle/warning mark.
     *
     * Distinct from `alert` (the Anomaly Monitor's plain warning triangle) on
     * purpose: a user-reported DEFECT and an automated data ANOMALY are different
     * work queues, and the sidebar must not make them look like one module.
     */
    bug: {
        paths: [
            'M12 20a6 6 0 006-6v-2H6v2a6 6 0 006 6z',
            'M12 12V8M9 4l1.5 2M15 4l-1.5 2M6 12H3M18 12h3M6 15l-2.5 1.5M18 15l2.5 1.5M6 9L3.5 7.5M18 9l2.5-1.5',
        ],
    },
    /*
     * THE SUPPORT ASSISTANT.
     *
     * A speech bubble with a spark — deliberately NOT the `bug` glyph (defects)
     * nor `inbox` (enquiries): three different queues must not read as one
     * destination in the sidebar.
     */
    assistant: {
        paths: [
            'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-4l-5 4v-4z',
            'M18.5 3.5l.7 1.6 1.6.7-1.6.7-.7 1.6-.7-1.6-1.6-.7 1.6-.7.7-1.6z',
        ],
    },

    /** Subsidies / Subsidy Sources - a hand holding a coin. */
    handCoins: {
        paths: [
            'M3 15h3l4 3h4a1 1 0 000-2h-3M3 19h3l5 2h6a2 2 0 002-2v-3l-5-3H8',
            'M15.5 6.5a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z',
        ],
    },

    /** Refunds - an arrow curving back (money returned). */
    refund: {
        paths: [
            'M3 12a9 9 0 109-9 9 9 0 00-6.36 2.64L3 8',
            'M3 3v5h5',
        ],
    },

    /** Meal Entries - a calendar-with-check (a dated meal record). */
    calendarCheck: {
        paths: [
            'M4 6a2 2 0 012-2h12a2 2 0 012 2v13a2 2 0 01-2 2H6a2 2 0 01-2-2V6z',
            'M4 9.5h16M8 3v3M16 3v3M9.5 14.5l1.8 1.8 3.7-3.7',
        ],
    },

    /** Meal Reports - a document with a chart (reports, not the analytics graph). */
    fileChart: {
        paths: [
            'M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z',
            'M14 3v5h5M9 17v-3M12 17v-5M15 17v-2',
        ],
    },

    /** Purchase Orders - a shopping cart. */
    cart: {
        paths: [
            'M3 4h2l2.4 11.2a1 1 0 001 .8h9.3a1 1 0 001-.8L20 8H6',
            'M10 20.5h.01M17 20.5h.01',
        ],
    },

    /** Menu & Procurement - a chef's hat (menu planning). */
    chefHat: {
        paths: [
            'M6 13a4 4 0 01-1-7.8A4 4 0 0112 4a4 4 0 017 1.2A4 4 0 0118 13v5a1 1 0 01-1 1H7a1 1 0 01-1-1v-5z',
            'M6 16.5h12',
        ],
    },

    /** Profile Manager - a single person (distinct from the `users` roster). */
    userCircle: {
        paths: [
            'M12 21a9 9 0 100-18 9 9 0 000 18z',
            'M12 12a3 3 0 100-6 3 3 0 000 6z',
            'M6.5 18.4a6 6 0 0111 0',
        ],
    },

    /** Theme Customizer - a paint palette. */
    palette: {
        paths: [
            'M12 3a9 9 0 000 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1.1.9-2 2-2h2.3A3.7 3.7 0 0021 10.8C21 6.5 17 3 12 3z',
        ],
        circles: [
            { cx: 7.5, cy: 11.5, r: 1 },
            { cx: 10, cy: 7.5, r: 1 },
            { cx: 14.5, cy: 7.5, r: 1 },
            { cx: 17, cy: 11, r: 1 },
        ],
    },

    /** Activity Log / Global Audit Log - a clock with a rewinding arrow. */
    history: {
        paths: [
            'M3 12a9 9 0 109-9 9 9 0 00-6.36 2.64L3 8',
            'M3 3v5h5M12 7.5V12l3 2',
        ],
    },

    /** SMTP Settings - a server rack. */
    server: {
        paths: [
            'M3 5a2 2 0 012-2h14a2 2 0 012 2v3a2 2 0 01-2 2H5a2 2 0 01-2-2V5z',
            'M3 14a2 2 0 012-2h14a2 2 0 012 2v3a2 2 0 01-2 2H5a2 2 0 01-2-2v-3z',
            'M7 6.5h.01M7 15.5h.01',
        ],
    },

    /** Trial & Subscriptions - an hourglass (a time-boxed plan). */
    hourglass: {
        paths: [
            'M6 3h12M6 21h12',
            'M8 3v3.5c0 2 4 3.5 4 5.5s-4 3.5-4 5.5V21M16 3v3.5c0 2-4 3.5-4 5.5s4 3.5 4 5.5V21',
        ],
    },

    /** Subscription Payments - a receipt with a tick (a verified payment). */
    receiptCheck: {
        paths: [
            'M5 3h14v18l-2.3-1.5L14.4 21l-2.4-1.5L9.6 21l-2.3-1.5L5 21V3z',
            'M9 9.5l2 2 4-4',
        ],
    },

    /** Role Manager - a key (permissions). */
    key: {
        paths: [
            'M15.5 3a5.5 5.5 0 00-5.2 7.3L3 17.6V21h3.4l1.3-1.3v-2h2v-2h2l1.3-1.3A5.5 5.5 0 0015.5 3z',
            'M17 7.5h.01',
        ],
    },

    /** Invite Code - a ticket / shareable pass. */
    ticket: {
        paths: [
            'M4 7a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2a2 2 0 000-4V7z',
            'M13 5v14',
        ],
    },

    /** Bulk Import - a spreadsheet grid with an up arrow. */
    tableImport: {
        paths: [
            'M4 5a2 2 0 012-2h12a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V5z',
            'M4 9h16M4 14h6M14 14h6M12 12v7M9.5 14.5L12 12l2.5 2.5',
        ],
    },

    /** Claim Review - a gavel (adjudicating a dispute). */
    gavel: {
        paths: [
            'M14 3l7 7-3 3-7-7 3-3zM9 8l7 7M3 21h9M10.5 13.5l-7 7',
        ],
    },
};

export default function Icon({ name, className = 'h-5 w-5', ...props }) {
    const icon = ICONS[name];

    // Fail visibly in dev, quietly in production - a missing icon should never
    // take down the whole sidebar.
    if (!icon) {
        if (import.meta.env.DEV) {
            console.warn(`[Icon] Unknown icon "${name}"`);
        }
        return <span className={className} aria-hidden="true" />;
    }

    const paths = icon.paths || [];

    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill={icon.fill ? 'currentColor' : 'none'}
            stroke={icon.fill ? 'none' : 'currentColor'}
            strokeWidth={icon.strokeWidth || 2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {paths.map((d, index) => (
                <path key={index} d={d} />
            ))}

            {icon.circles?.map((circle, index) => (
                <circle
                    key={index}
                    cx={circle.cx}
                    cy={circle.cy}
                    r={circle.r}
                />
            ))}
        </svg>
    );
}
