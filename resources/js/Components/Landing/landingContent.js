/**
 * Static content for the public landing page.
 *
 * Kept in one module so the section components stay purely presentational and
 * copy edits never risk touching JSX.
 */

export const NAV_LINKS = [
    ['#features', 'Features'],
    ['#workflow', 'How it works'],
    ['#pricing', 'Pricing'],
    ['#contact', 'Contact'],
];

export const FEATURES = [
    {
        title: 'Strict multi-tenant isolation',
        body: 'Every institution keeps its own roster, ledger and branding. Data never bleeds between tenants — an office canteen and a university hall stay fully separate.',
        icon: 'M3 21h18M4 10h16M5 10V21M19 10V21M9 21v-7M15 21v-7M12 3l9 6H3l9-6z',
        tone: 'indigo',
    },
    {
        title: 'Automated meal & expense tracking',
        body: 'Log breakfast, lunch and dinner per participant, per day. Contributions and vendor expenses reconcile automatically against a live, always-balanced ledger.',
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
        tone: 'emerald',
    },
    {
        title: 'Cost efficiency you can prove',
        body: 'The per-meal rate is derived from real spending and real meal counts — so members pay exactly their fair share and managers can defend every number.',
        icon: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        tone: 'sky',
    },
    {
        title: 'Role hierarchies that just work',
        body: 'Platform super admin, institution admin, meal manager and member each see exactly their scope — scoped to their own institution, never anyone else’s.',
        icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-6.93 4 4 0 004 6.93z',
        tone: 'violet',
    },
    {
        title: 'Custom terminology',
        body: 'Rename “Students” to “Employees”, “Departments” to “Teams”, and every label, table header and email follows — tuned to each institution type.',
        icon: 'M4 6h16M4 12h10M4 18h7',
        tone: 'amber',
    },
    {
        title: 'Reports & analytics',
        body: 'Daily, weekly and month-end breakdowns of meals served and money spent — plus personal dashboards for every member.',
        icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        tone: 'rose',
    },
];

export const WORKFLOW = [
    {
        step: '01',
        title: 'Create your institution',
        body: 'Set up a workspace in seconds — an office canteen, a university hall, a college mess. Each gets its own isolated roster, ledger and branding.',
        scene: 'institution',
    },
    {
        step: '02',
        title: 'Invite your members',
        body: 'Members join with a secure signed link and set their own password — no shared credentials, ever. Accounts map automatically to your workspace.',
        scene: 'invite',
    },
    {
        step: '03',
        title: 'Track daily meals',
        body: 'Log breakfast, lunch and dinner in one pass. Meal rates turn counts into exact costs, and every balance updates live.',
        scene: 'meals',
    },
    {
        step: '04',
        title: 'Watch the ledger balance',
        body: 'Contributions in, vendor expenses out — reconciled automatically. Members see their own balance and can raise claims any time.',
        scene: 'ledger',
    },
];

export const INSTITUTION_TYPES = [
    { label: 'Corporate Offices', detail: 'Staff cafeterias & office meal programs', icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
    { label: 'University Halls', detail: 'Residential halls & shared messes', icon: 'M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z' },
    { label: 'General Messes', detail: 'Hostels, clubs & shared households', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
    { label: 'Colleges & Hostels', detail: 'Campus boarding & dining', icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253' },
];

export const TONE = {
    indigo: { chip: 'bg-indigo-50 text-indigo-600', ring: 'group-hover:bg-indigo-600', glow: 'group-hover:shadow-indigo-500/20' },
    emerald: { chip: 'bg-emerald-50 text-emerald-600', ring: 'group-hover:bg-emerald-600', glow: 'group-hover:shadow-emerald-500/20' },
    sky: { chip: 'bg-sky-50 text-sky-600', ring: 'group-hover:bg-sky-600', glow: 'group-hover:shadow-sky-500/20' },
    violet: { chip: 'bg-violet-50 text-violet-600', ring: 'group-hover:bg-violet-600', glow: 'group-hover:shadow-violet-500/20' },
    amber: { chip: 'bg-amber-50 text-amber-600', ring: 'group-hover:bg-amber-600', glow: 'group-hover:shadow-amber-500/20' },
    rose: { chip: 'bg-rose-50 text-rose-600', ring: 'group-hover:bg-rose-600', glow: 'group-hover:shadow-rose-500/20' },
};

export const formatMoney = (n) =>
    Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
