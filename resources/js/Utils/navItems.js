/**
 * Navigation map for the app sidebar.
 *
 * Single source of truth for what appears in the sidebar and under what
 * permission. The Sidebar component is a dumb renderer over this structure,
 * so adding a menu item never means touching JSX.
 *
 * Each item shape:
 *   {
 *     label:      string   - text shown in the sidebar
 *     route:      string   - Laravel route() name
 *     icon:       string   - Icon component key (see Components/Icon.jsx)
 *     permission: string | string[] | null
 *                          - null/omitted        -> always visible (all authed users)
 *                          - 'users.view'        -> requires that permission
 *                          - ['a','b']           -> requires ALL of them
 *     match:      string   - route().current() pattern for the active state.
 *                            Defaults to `route`. Use a wildcard like
 *                            'settings.roles.*' to highlight while on child pages.
 *     children:   array    - nested items (rendered as a collapsible group)
 *     roles:      string[] - optional extra gate: visible if user has ANY of these roles
 *     rolesOnly:  boolean  - when true, `roles` is EXCLUSIVE: the item is shown
 *                            ONLY to users with one of those roles, and hidden
 *                            from everyone else - even if they hold the
 *                            `permission`. Used for member-only modules.
 *   }
 *
 * Permission strings must match database/seeders/RolesAndPermissionsSeeder.php.
 * A typo here silently hides the item, so keep them in sync.
 */

export const NAV_SECTIONS = [
    /* ------------------------------------------------------------------ *
     * MEMBER AREA - members only.
     *
     * Every item here uses rolesOnly: ['Member'], so an admin or meal manager
     * never sees this section. The member's own sidebar is deliberately
     * streamlined to just these five destinations; no administrative module is
     * reachable from it.
     * ------------------------------------------------------------------ */
    {
        heading: 'My Account',
        roles: ['Member'],
        rolesOnly: true,
        items: [
            {
                // The merged personal summary: current-month figures, deposits,
                // meal history and balance all in one cohesive view.
                label: 'Summary',
                route: 'member.dashboard',
                match: 'member.dashboard',
                icon: 'home',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Own meal entries, day by day.
                label: 'Meal Entries',
                route: 'member.meals',
                match: 'member.meals',
                icon: 'mealEntries',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Own deposit history.
                label: 'Deposits',
                route: 'member.deposits',
                match: 'member.deposits',
                icon: 'download',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Personal analytics, scoped exclusively to this member.
                label: 'Analytics',
                route: 'member.analytics',
                match: 'member.analytics',
                icon: 'analytics',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Member-initiated top-ups. A payment is PENDING until a manager
                // verifies it, so this cannot credit the balance on its own.
                label: 'Make a Payment',
                route: 'member.payments',
                match: 'member.payments',
                icon: 'cash',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Vote on the menus the kitchen is considering. One vote per menu.
                label: 'Meal Voting',
                route: 'member.menus',
                match: 'member.menus',
                icon: 'utensils',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Own claim submissions + status tracking.
                label: 'My Claims',
                route: 'claims.index',
                match: 'claims.index',
                icon: 'claimBubble',
                roles: ['Member'],
                rolesOnly: true,
            },
        ],
    },
    /* ------------------------------------------------------------------ *
     * STAFF AREA - hidden from members. Both sections carry the same exclusive
     * role gate, so members never see the org-wide Dashboard or any Meal
     * Management / Administration module.
     * ------------------------------------------------------------------ */
    {
        /*
         * SOFTWARE SUPER ADMIN - PLATFORM AREA.
         *
         * The SSA is a GLOBAL operator, not a tenant user: their navigation is
         * entirely separate from any workspace. They never get the tenant
         * Dashboard, tenant Analytics, Meal Management, or Claim Review here -
         * the only way into a tenant's operational sheets is the "Access
         * Dashboard" action inside the Institution Directory, which switches the
         * session tenant and reveals the tenant modules below.
         */
        heading: 'Platform Overview',
        roles: ['Software Super Admin'],
        rolesOnly: true,
        items: [
            {
                // The SSA's landing page IS the platform monitoring dashboard.
                label: 'Business Dashboard',
                route: 'ssa.dashboard',
                match: 'ssa.dashboard',
                icon: 'globe',
            },
            {
                // Platform-wide SaaS financial engine (MRR/ARR, conversion).
                label: 'SaaS Analytics',
                route: 'ssa.analytics',
                match: 'ssa.analytics',
                icon: 'trendingUp',
            },
            {
                label: 'Institution Directory',
                route: 'settings.institutions.index',
                match: 'settings.institutions.*',
                icon: 'city',
            },
            {
                label: 'Trial & Subscriptions',
                route: 'settings.trials.index',
                match: 'settings.trials.*',
                icon: 'hourglass',
            },
            {
                // The verification queue for institution subscription payments:
                // approving one extends that workspace's paid-up period.
                label: 'Subscription Payments',
                route: 'ssa.subscription-payments.index',
                match: 'ssa.subscription-payments.*',
                icon: 'receiptCheck',
            },
            {
                // FX rate snapshots: what makes cross-currency platform revenue
                // reporting meaningful.
                label: 'Currency & FX Rates',
                route: 'ssa.currencies.index',
                match: 'ssa.currencies.*',
                icon: 'exchange',
            },
            {
                // SSA-only: create/edit pricing tiers and assign them.
                label: 'Pricing & Plans',
                route: 'ssa.plans.index',
                match: 'ssa.plans.*',
                icon: 'tag',
                permission: 'plans.view',
            },
            {
                label: 'Security & Audit',
                route: 'ssa.audit.index',
                match: 'ssa.audit.*',
                icon: 'lock',
            },
            {
                // Public demo requests / leads the SSA can approve into trials.
                label: 'Landing Enquiries',
                route: 'ssa.enquiries.index',
                match: 'ssa.enquiries.*',
                icon: 'inbox',
            },
            {
                label: 'Broadcasts',
                route: 'ssa.broadcasts.index',
                match: 'ssa.broadcasts.*',
                icon: 'megaphone',
            },
        ],
    },
    {
        heading: 'Workspace Overview',
        roles: ['Institution Admin', 'Meal Manager'],
        rolesOnly: true,
        items: [
            {
                // Org-wide dashboard for tenant staff.
                label: 'Dashboard',
                route: 'dashboard',
                match: 'dashboard',
                icon: 'dashboard',
            },
            {
                // Org-wide analytics; members get their own under My Account.
                label: 'Analytics',
                route: 'analytics',
                icon: 'pieChart',
                permission: 'transactions.view',
            },
        ],
    },
    {
        /*
         * TENANT MEAL MANAGEMENT - only reachable inside a workspace.
         *
         * Deliberately EXCLUDES the Software Super Admin: the SSA must never see
         * individual meal sheets on their primary workspace. When an SSA uses
         * "Access Dashboard" from the Institution Directory, they are switched
         * into a tenant and this whole section can also be surfaced for that
         * session via the `can()` check on the underlying permissions - but the
         * SSA's DEFAULT nav (above) shows none of it.
         */
        heading: 'Meal Management',
        roles: ['Institution Admin', 'Meal Manager'],
        rolesOnly: true,
        // Also shown to an SSA who has explicitly switched INTO a workspace
        // (session tenant set). The renderer checks `tenantScoped` against the
        // `switched` flag passed from the tenant prop.
        tenantScoped: true,
        items: [
            {
                // termKey lets the label follow the institution type
                // (Students / Employees / Boarders) with no code change.
                label: 'Members',
                termKey: 'members',
                route: 'meals.students.index',
                match: 'meals.students.*',
                icon: 'users',
                permission: 'students.view',
            },
            {
                label: 'Subsidies',
                route: 'meals.subsidies.index',
                match: 'meals.subsidies.*',
                icon: 'handCoins',
                permission: 'subsidies.view',
            },
            {
                label: 'Departments',
                termKey: 'departments',
                route: 'meals.departments.index',
                icon: 'sitemap',
                permission: 'departments.view',
            },
            {
                label: 'Deposits',
                termKey: 'deposits',
                route: 'meals.deposits.index',
                icon: 'deposit',
                permission: 'meals.deposit',
            },
            {
                // Money paid back OUT of a member's balance (stopped meals /
                // withdrawal). Same permission as Deposits - if you can take
                // money in, you can pay it back out.
                label: 'Refunds',
                route: 'meals.refunds.index',
                match: 'meals.refunds.*',
                icon: 'refund',
                permission: 'meals.deposit',
            },
            {
                label: 'Meal Entries',
                route: 'meals.entries.index',
                icon: 'calendarCheck',
                permission: 'meals.entry',
            },
            {
                label: 'Expenses',
                route: 'meals.expenses.index',
                icon: 'receipt',
                permission: 'meals.expense',
            },
            {
                label: 'Vendors',
                route: 'meals.vendors.index',
                match: 'meals.vendors.*',
                icon: 'store',
                permission: 'vendors.view',
            },
            {
                label: 'Meal Reports',
                route: 'meals.reports.index',
                icon: 'fileChart',
                permission: 'meals.reports',
            },
            {
                // Dynamic reports builder: compose and SAVE queries over the
                // finance engine instead of living with fixed report screens.
                label: 'Report Builder',
                route: 'meals.report-builder.index',
                match: 'meals.report-builder.*',
                icon: 'reportBuilder',
                permission: 'meals.reports',
            },
            {
                // AI forecasting (RAG): tomorrow's headcount, cost per meal and
                // expected expense, with the evidence behind the estimate.
                label: 'AI Forecasting',
                route: 'meals.forecasting.index',
                match: 'meals.forecasting.*',
                icon: 'chart',
                permission: 'meals.reports',
            },
            {
                // Anomaly monitor: duplicate deposits, meal spikes, negative
                // balances and unusual expenses awaiting review.
                label: 'Anomaly Monitor',
                route: 'meals.anomalies.index',
                match: 'meals.anomalies.*',
                icon: 'alert',
                permission: 'meals.reports',
            },
            {
                // Meal menu proposals + member voting, with admin/manager approval.
                label: 'Meal Menus & Voting',
                route: 'meals.menus.index',
                match: 'meals.menus.*',
                icon: 'menuBoard',
                permission: 'meals.reports',
            },
            {
                // Weekly menu planning + the procurement forecast it drives.
                label: 'Menu & Procurement',
                route: 'meals.menu-cycle.index',
                match: 'meals.menu-cycle.*',
                icon: 'chefHat',
                permission: 'meals.reports',
            },
            {
                // Purchase orders, goods receipts and the 3-way invoice match.
                label: 'Purchase Orders',
                route: 'meals.purchase-orders.index',
                match: 'meals.purchase-orders.*',
                icon: 'cart',
                permission: 'meals.expense',
            },
            {
                // Member-submitted payments awaiting a manager's verification.
                label: 'Payment Verification',
                route: 'meals.member-payments.index',
                match: 'meals.member-payments.*',
                icon: 'verifyList',
                permission: 'meals.deposit',
            },
            {
                // Bulk CSV import with a dry-run preview before anything is written.
                label: 'Bulk Import',
                route: 'meals.import.index',
                match: 'meals.import.*',
                icon: 'tableImport',
                permission: 'students.manage',
            },
            {
                // Manager-only: review the claims members have raised.
                label: 'Claim Review',
                route: 'claims.review',
                match: 'claims.review',
                icon: 'gavel',
                permission: 'claims.review',
            },
        ],
    },
    /* ------------------------------------------------------------------ *
     * STAFF ACCOUNT - Profile + User Management, placed ABOVE Settings.
     *
     * The spec requires the Account / User-Management module to sit above the
     * Settings module for admins and meal managers. Members never see this
     * section (rolesOnly), and get their own Account block at the very bottom.
     * ------------------------------------------------------------------ */
    {
        heading: 'Account',
        roles: ['Software Super Admin', 'Institution Admin', 'Meal Manager'],
        rolesOnly: true,
        items: [
            {
                label: 'Profile Manager',
                route: 'profile.edit',
                match: 'profile.*',
                icon: 'userCircle',
                permission: null,
            },
            {
                // User Management lives in the Account module (above Settings).
                // Admins and the SSA ONLY - a Meal Manager never sees it.
                label: 'User Manager',
                route: 'settings.users.index',
                match: 'settings.users.*',
                icon: 'userCog',
                permission: 'users.view',
                roles: ['Software Super Admin', 'Institution Admin'],
                rolesOnly: true,
            },
            {
                // Theme Customizer: a dedicated settings sub-module available to
                // EVERY user. No permission gate - personalising one's own view
                // is a personal preference, not an administrative act.
                label: 'Theme Customizer',
                route: 'settings.theme.edit',
                match: 'settings.theme.*',
                icon: 'palette',
                permission: null,
            },
        ],
    },
    {
        /*
         * WORKSPACE SETTINGS - institution-scoped ONLY.
         *
         * The Software Super Admin is deliberately EXCLUDED here: these modules
         * (institution profile, currency, subsidy sources) configure ONE tenant
         * and belong strictly inside that tenant's workspace. The SSA reaches
         * them only by switching into an institution, never from the global view.
         */
        heading: 'Workspace Settings',
        roles: ['Institution Admin', 'Meal Manager'],
        rolesOnly: true,
        tenantScoped: true,
        items: [
            {
                // The institution's platform subscription: the plan, what is owed,
                // and a payment form. Only an Institution Admin may submit one, and
                // it stays PENDING until the platform owner verifies it.
                label: 'Subscription & Billing',
                route: 'settings.subscription.show',
                match: 'settings.subscription.*',
                icon: 'creditCard',
                permission: 'institution.view',
                roles: ['Institution Admin'],
                rolesOnly: true,
            },
            {
                label: 'Settings',
                icon: 'cog',
                // Group is visible if the user can reach ANY child. The renderer
                // prunes children first, then hides the group if nothing remains.
                permission: null,
                children: [
                    {
                        label: 'Institution',
                        route: 'settings.institution.edit',
                        match: 'settings.institution.*',
                        icon: 'building',
                        permission: 'institution.view',
                    },
                    {
                        // The workspace's invite code - the key a member types on
                        // the public sign-up form to join this institution.
                        label: 'Invite Code',
                        route: 'settings.invite-code.show',
                        match: 'settings.invite-code.*',
                        icon: 'ticket',
                        permission: 'institution.view',
                    },
                    {
                        // Currency format for THIS institution. Institution Admins
                        // manage it (currency.manage); managers can view only.
                        label: 'Currency Manager',
                        route: 'settings.currency',
                        match: 'settings.currency',
                        icon: 'coins',
                        permission: 'currency.view',
                    },
                    {
                        label: 'Subsidy Sources',
                        route: 'settings.subsidy-sources.index',
                        match: 'settings.subsidy-sources.*',
                        icon: 'grantFolder',
                        permission: 'subsidies.manage',
                    },
                    {
                        label: 'Activity Log',
                        route: 'settings.activity.index',
                        match: 'settings.activity.*',
                        icon: 'history',
                        permission: 'audit.view',
                    },
                    {
                        label: 'Email Log',
                        route: 'settings.emails.index',
                        match: 'settings.emails.*',
                        icon: 'mail',
                        permission: 'emails.view',
                    },
                    {
                        // Workspace-scoped broadcasts: announcements to THIS
                        // institution only (never the platform). Institution Admins
                        // hold `notifications.announce`; a Meal Manager does not, so
                        // the exclusive role gate keeps it off their sidebar.
                        label: 'Broadcasts',
                        route: 'broadcasts.index',
                        match: 'broadcasts.*',
                        icon: 'announce',
                        permission: 'notifications.announce',
                        roles: ['Institution Admin'],
                        rolesOnly: true,
                    },
                ],
            },
        ],
    },
    {
        /*
         * PLATFORM SETTINGS - Software Super Admin ONLY.
         *
         * Roles and the platform-wide audit/email streams. The SSA is the only
         * role that manages role definitions, and the only one whose audit and
         * email views span every tenant. No tenant-scoped role reaches this
         * group (rolesOnly + roles), and none of these items appear to a member.
         */
        heading: 'Platform Settings',
        roles: ['Software Super Admin'],
        rolesOnly: true,
        items: [
            {
                label: 'Platform Settings',
                icon: 'sliders',
                permission: null,
                children: [
                    {
                        label: 'Role Manager',
                        route: 'settings.roles.index',
                        match: 'settings.roles.*',
                        icon: 'key',
                        permission: 'roles.view',
                    },
                    {
                        label: 'Global Audit Log',
                        route: 'settings.activity.index',
                        match: 'settings.activity.*',
                        icon: 'history',
                        permission: 'audit.view',
                    },
                    {
                        label: 'Email Log',
                        route: 'settings.emails.index',
                        match: 'settings.emails.*',
                        icon: 'mail',
                        permission: 'emails.view',
                    },
                    {
                        // SSA-only SMTP relay configuration (dynamic mail setup,
                        // pre-filled with the production Brevo relay).
                        label: 'SMTP Settings',
                        route: 'ssa.smtp.edit',
                        match: 'ssa.smtp.*',
                        icon: 'server',
                        roles: ['Software Super Admin'],
                        rolesOnly: true,
                    },
                    // NOTE: "Pricing & Plans" is intentionally NOT repeated here.
                    // Plan management is reached once, from the Platform Overview
                    // section (route `ssa.plans.index`); duplicating it under
                    // Platform Settings was redundant.
                ],
            },
        ],
    },
    /* ------------------------------------------------------------------ *
     * MEMBER ACCOUNT - deliberately the LAST section for members.
     *
     * The spec requires the member's Account/Profile module to sit strictly
     * BELOW every operational module (Summary, Meal Entries, Deposits,
     * Analytics, My Claims). Placing this section last guarantees that order.
     * ------------------------------------------------------------------ */
    {
        heading: 'Account',
        roles: ['Member'],
        rolesOnly: true,
        items: [
            {
                label: 'Profile Manager',
                route: 'profile.edit',
                match: 'profile.*',
                icon: 'userCircle',
                roles: ['Member'],
                rolesOnly: true,
            },
            {
                // Theme Customizer is a per-user preference, so members get it too.
                label: 'Theme Customizer',
                route: 'settings.theme.edit',
                match: 'settings.theme.*',
                icon: 'palette',
                roles: ['Member'],
                rolesOnly: true,
            },
        ],
    },
];

/**
 * Filter the nav tree down to what this user is allowed to see.
 * A group with children keeps only the permitted children, and disappears
 * entirely if none survive.
 *
 * @param {function(string|string[]): boolean} isAllowed - predicate from useCan
 */
export function buildVisibleNav(sections, { can, hasRole, switched = false } = {}) {
    if (typeof can !== 'function') return [];

    const allows = (permission, roles, rolesOnly = false) => {
        if (Array.isArray(permission)) {
            // Array means ALL required.
            if (!(permission.length > 0 && permission.every((p) => can(p)))) return false;
        } else if (permission && !can(permission)) {
            return false;
        }

        if (Array.isArray(roles) && roles.length > 0) {
            const matchesRole = typeof hasRole === 'function' && roles.some((r) => hasRole(r));

            // Exclusive gate: the item is shown ONLY to those roles. Everyone
            // else is hidden even if they passed the permission check above.
            if (rolesOnly) return matchesRole;

            // Inclusive gate (legacy): any listed role grants visibility.
            return matchesRole;
        }

        return true;
    };

    return sections
        .map((section) => {
            /*
             * SECTION-LEVEL GATE.
             *
             * `tenantScoped` sections (Meal Management and friends) belong to a
             * WORKSPACE and are normally hidden from the global SSA. But an SSA
             * who has explicitly switched into an institution (session tenant
             * set -> `switched === true`) IS operating inside that workspace and
             * should see them. So a tenantScoped section is allowed for:
             *   - the normal workspace roles (Institution Admin / Meal Manager), OR
             *   - any user while they are in a switched tenant session.
             */
            if (section.tenantScoped) {
                const normalRoles = allows(section.permission, section.roles, section.rolesOnly);

                if (!normalRoles && !switched) {
                    return null;
                }
            } else if (!allows(section.permission, section.roles, section.rolesOnly)) {
                // A whole section can be role-exclusive (member area, staff areas).
                // If it does not pass, it is dropped before items are considered.
                return null;
            }

            const items = (section.items || [])
                .map((item) => {
                    if (!allows(item.permission, item.roles, item.rolesOnly)) return null;

                    if (Array.isArray(item.children)) {
                        const children = item.children.filter((child) =>
                            allows(child.permission, child.roles, child.rolesOnly)
                        );

                        // Group is only worth showing if it leads somewhere.
                        if (children.length === 0) return null;

                        return { ...item, children };
                    }

                    return item;
                })
                .filter(Boolean);

            return { ...section, items };
        })
        // Drop sections the gate rejected (null) AND sections left empty after
        // their items were pruned. Guarding against null is essential: a failed
        // section-level role gate returns null, and `null.items` would throw.
        .filter((section) => section && section.items.length > 0);
}
