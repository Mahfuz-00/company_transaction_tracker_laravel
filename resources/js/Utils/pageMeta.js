/**
 * PAGE HIERARCHY: Section > Module > Sub-Module > Page Title.
 *
 * The Fixed Top Bar (Components/TopBar.jsx) renders the CURRENT page's position in
 * this hierarchy as a breadcrumb trail, plus the page title. Keeping the map in one
 * data file means a new page is documented by adding ONE entry here, never by
 * touching the layout - and the same map can be asserted directly in tests.
 *
 * THE FOUR LEVELS
 *   section    : the top-level area          (e.g. "Meal Management")
 *   module     : the feature within it       (e.g. "Meal Menus")
 *   subModule  : an optional third level     (e.g. "Voting")
 *   title      : the specific page           (e.g. "Menu Board")
 *
 * SMART COLLAPSE RULE
 * -------------------
 * When the MODULE and the PAGE TITLE are identical, showing both reads as noise
 * ("Meal Menus > Meal Menus"). In that case the module level is dropped, so the
 * trail is simplified. This is applied by `buildTrail()` and is the reason entries
 * declare `module` and `title` separately rather than hand-writing a crumb array.
 *
 * Resolution order for a pathname:
 *   1. an EXACT match in ROUTES,
 *   2. the LONGEST matching PREFIX (so /settings/users/12/edit still resolves to
 *      the User Manager entry, with the extra segment as the leaf),
 *   3. a Title-Cased fallback derived from the URL segments, so nothing ever
 *      renders blank.
 */

export const ROUTES = {
    /* ---- Platform (SSA) ------------------------------------------------ */
    '/platform': { section: 'Platform', module: 'Business Dashboard', title: 'Overview' },
    '/platform/analytics': { section: 'Platform', module: 'SaaS Analytics', title: 'Revenue & Growth' },
    '/platform/audit': { section: 'Platform', module: 'Security', subModule: 'Audit Log', title: 'Global Activity' },
    '/platform/broadcasts': { section: 'Platform', module: 'Announcements', title: 'Platform Broadcasts' },
    '/platform/enquiries': { section: 'Platform', module: 'Growth', subModule: 'Leads', title: 'Landing Enquiries' },
    '/platform/plans': { section: 'Platform', module: 'Billing', subModule: 'Pricing', title: 'Plans & Tiers' },
    '/platform/smtp': { section: 'Platform', module: 'Settings', subModule: 'Email', title: 'SMTP Relay' },
    '/platform/currencies': { section: 'Platform', module: 'Billing', subModule: 'Currency', title: 'FX Rates' },
    '/platform/subscription-payments': {
        section: 'Platform', module: 'Billing', subModule: 'Subscriptions', title: 'Payment Verification',
    },

    /* ---- Member area ---------------------------------------------------- */
    '/my/dashboard': { section: 'My Account', module: 'Summary', title: 'My Dashboard' },
    '/my/meals': { section: 'My Account', module: 'Meals', title: 'My Meal Entries' },
    '/my/deposits': { section: 'My Account', module: 'Payments', title: 'My Deposits' },
    '/my/payments': { section: 'My Account', module: 'Payments', title: 'Make a Payment' },
    '/my/analytics': { section: 'My Account', module: 'Insights', title: 'My Analytics' },
    '/my/menus': { section: 'My Account', module: 'Meal Voting', title: 'Vote on Menus' },
    '/claims': { section: 'My Account', module: 'Claims', title: 'My Claims' },
    '/claims/review': { section: 'Meal Management', module: 'Claims', title: 'Claim Review' },

    /* ---- Meal management ------------------------------------------------ */
    '/meals/students': { section: 'Meal Management', module: 'Members', title: 'Roster' },
    '/meals/students-export': { section: 'Meal Management', module: 'Members', title: 'Roster Export' },
    '/meals/departments': { section: 'Meal Management', module: 'Members', subModule: 'Groups', title: 'Departments' },
    '/meals/deposits': { section: 'Meal Management', module: 'Finance', subModule: 'Money In', title: 'Deposits' },
    '/meals/refunds': { section: 'Meal Management', module: 'Finance', subModule: 'Money Out', title: 'Refunds' },
    '/meals/expenses': { section: 'Meal Management', module: 'Finance', subModule: 'Money Out', title: 'Expenses' },
    '/meals/subsidies': { section: 'Meal Management', module: 'Finance', subModule: 'Funding', title: 'Subsidies' },
    '/meals/entries': { section: 'Meal Management', module: 'Meals', title: 'Meal Entries' },
    '/meals/vendors': { section: 'Meal Management', module: 'Procurement', title: 'Vendors' },
    '/meals/reports': { section: 'Meal Management', module: 'Reports', title: 'Meal Reports' },

    '/meals/menus': { section: 'Meal Management', module: 'Meal Menus', title: 'Menu Board' },
    '/meals/menu-cycle': { section: 'Meal Management', module: 'Menu Cycle', title: 'Weekly Planning' },
    '/meals/purchase-orders': {
        section: 'Meal Management', module: 'Procurement', subModule: 'Purchase Orders', title: 'Orders',
    },
    '/meals/member-payments': {
        section: 'Meal Management', module: 'Finance', subModule: 'Payments', title: 'Payment Verification',
    },
    '/meals/import': { section: 'Meal Management', module: 'Data', subModule: 'Import', title: 'Bulk Import' },
    '/meals/report-builder': { section: 'Meal Management', module: 'Reports', subModule: 'Builder', title: 'Custom Reports' },
    '/meals/anomalies': { section: 'Meal Management', module: 'Monitoring', subModule: 'Integrity', title: 'Anomaly Monitor' },
    '/meals/forecasting': { section: 'Meal Management', module: 'Monitoring', subModule: 'AI', title: 'Forecasting' },

    /* ---- Account & settings --------------------------------------------- */
    '/profile': { section: 'Account', module: 'Profile', title: 'Profile Manager' },
    '/settings/users': { section: 'Account', module: 'Users', title: 'User Manager' },
    '/settings/theme': { section: 'Account', module: 'Appearance', title: 'Theme Customizer' },
    '/notifications': { section: 'Account', module: 'Notifications', title: 'Inbox' },

    '/settings/subscription': {
        section: 'Workspace Settings', module: 'Billing', subModule: 'Subscription', title: 'Subscription & Billing',
    },
    '/settings/institution': {
        section: 'Workspace Settings', module: 'Institution', title: 'Profile & Terminology',
    },
    '/settings/invite-code': { section: 'Workspace Settings', module: 'Institution', subModule: 'Access', title: 'Invite Code' },
    '/settings/currency': { section: 'Workspace Settings', module: 'Finance', subModule: 'Currency', title: 'Currency Manager' },
    '/settings/subsidy-sources': {
        section: 'Workspace Settings', module: 'Finance', subModule: 'Funding', title: 'Subsidy Sources',
    },
    '/settings/activity': { section: 'Workspace Settings', module: 'Security', subModule: 'Audit', title: 'Activity Log' },
    '/settings/emails': { section: 'Workspace Settings', module: 'Settings', subModule: 'Email', title: 'Email Log' },
    '/settings/broadcasts': { section: 'Workspace Settings', module: 'Announcements', title: 'Workspace Broadcasts' },
    '/settings/roles': { section: 'Platform Settings', module: 'Access Control', title: 'Role Manager' },
    '/settings/institutions': { section: 'Platform', module: 'Institutions', title: 'Registry' },
    '/settings/trials': { section: 'Platform', module: 'Billing', subModule: 'Subscriptions', title: 'Trials & Plans' },
    '/settings/monitoring': { section: 'Platform', module: 'Monitoring', title: 'Control Tower' },
    '/analytics': { section: 'Workspace', module: 'Analytics', title: 'Institution Analytics' },
};

/** Turn '/meals/students/12' or '/settings/users' into readable fallbacks. */
function humaniseSegment(segment) {
    if (!segment) return '';

    return segment
        .split('-')
        .map((word) => (word ? word[0].toUpperCase() + word.slice(1) : word))
        .join(' ');
}

/**
 * Build the breadcrumb trail from a hierarchy entry.
 *
 * THE SMART COLLAPSE lives here: when the module and the title are the SAME string
 * (case-insensitively), the module level is dropped so the trail does not read
 * "Meal Menus > Meal Menus". The section is always kept, because it is what tells
 * the user which AREA of the app they are in.
 *
 * @returns {Array<{label:string, url:string|null}>}
 */
export function buildTrail(entry, pathname = '/') {
    const { section, module, subModule, title } = entry;

    const trail = [];

    if (section) {
        trail.push({ label: section, url: null });
    }

    const moduleMatchesTitle =
        module && title && module.trim().toLowerCase() === title.trim().toLowerCase();

    // Smart collapse: skip the module level when it merely repeats the title.
    if (module && !moduleMatchesTitle) {
        trail.push({ label: module, url: null });
    }

    if (subModule) {
        trail.push({ label: subModule, url: null });
    }

    // The leaf is always the title, and is the only non-clickable entry.
    if (title) {
        trail.push({ label: title, url: pathname });
    } else if (module) {
        // No explicit title: the module IS the leaf.
        trail.push({ label: module, url: pathname });
    }

    return trail;
}

/**
 * Resolve the hierarchy + trail for a pathname.
 *
 * @param {string} pathname - e.g. window.location.pathname
 * @returns {{section:string, module:string, subModule:?string, title:string,
 *            crumbs:Array<{label:string, url:string|null}>}}
 */
export function resolvePageMeta(pathname = '/') {
    // Normalise: drop the query string and a trailing slash (except root).
    let path = '/' + String(pathname).replace(/^\/+/, '').split('?')[0];
    if (path.length > 1) path = path.replace(/\/+$/, '');

    // 1. Exact match.
    if (ROUTES[path]) {
        const entry = ROUTES[path];

        return { ...entry, crumbs: buildTrail(entry, path) };
    }

    // 2. Longest matching prefix, so detail/edit routes inherit their parent.
    const candidates = Object.keys(ROUTES)
        .filter((key) => key !== '/' && path.startsWith(key + '/'))
        .sort((a, b) => b.length - a.length);

    if (candidates.length > 0) {
        const base = ROUTES[candidates[0]];

        // Label the leaf with a humanised version of the extra URL segments
        // (e.g. "... > Edit"), so a detail page still identifies itself.
        const extra = path.slice(candidates[0].length + 1);
        const leaf = humaniseSegment(extra.split('/')[0]) || base.title;

        const entry = { ...base, title: leaf };

        return { ...entry, crumbs: buildTrail(entry, path) };
    }

    // 3. Nothing matched: derive something readable rather than showing nothing.
    const segments = path.split('/').filter(Boolean);

    if (segments.length === 0) {
        const entry = { section: '', module: 'Home', title: 'Home' };

        return { ...entry, crumbs: buildTrail(entry, path) };
    }

    const entry = {
        section: humaniseSegment(segments[0]),
        module: humaniseSegment(segments[1] || segments[0]),
        title: humaniseSegment(segments[segments.length - 1]),
    };

    return { ...entry, crumbs: buildTrail(entry, path) };
}