import { usePage } from '@inertiajs/react';

/**
 * CENTRALISED PLATFORM BRANDING.
 *
 * The one place the frontend reads the platform's master name and chrome
 * labels. The values come from `config/platform.php` (via the `platform`
 * Inertia prop), so changing that single PHP file updates the landing header,
 * the login top bar AND the SSA sidebar at once - no component edits.
 *
 * It deliberately exposes PLATFORM branding (never a tenant) - the SSA and the
 * public pages must not show a single institution's name in global chrome.
 *
 *   const { name, tagline, adminSubtitle, controlCenter, logoUrl } = usePlatformBranding();
 */
const FALLBACK = {
    name: 'Meal Tracking Platform',
    tagline: 'Multi-institution meal & expense platform',
    admin_subtitle: 'Super Admin Portal',
    control_center: 'Meal Tracking Platform',
    logo_url: null,
};

export default function usePlatformBranding() {
    const { props } = usePage();
    const platform = props?.platform || {};

    return {
        name: platform.name || FALLBACK.name,
        tagline: platform.tagline || FALLBACK.tagline,
        adminSubtitle: platform.admin_subtitle || FALLBACK.admin_subtitle,
        controlCenter: platform.control_center || FALLBACK.control_center,
        logoUrl: platform.logo_url ?? FALLBACK.logo_url,
        // SSA credential guardrails (config/platform.php). When an operator locks
        // SSA credentials to the CLI/seeder path, the Profile Manager hides the
        // password + editable fields and shows the CLI instructions instead.
        ssaProfileEditable: platform.ssa_profile_editable !== false,
        ssaSelfServicePassword: platform.ssa_self_service_password !== false,
        // The raw payload, for any consumer that needs the whole object.
        raw: platform,
    };
}
