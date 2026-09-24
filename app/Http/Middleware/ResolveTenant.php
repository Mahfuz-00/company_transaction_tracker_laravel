<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use App\Support\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and ENFORCES the active tenant for every authenticated request.
 *
 * Responsibilities:
 *   1. Drop a stale session tenant (e.g. the institution was deleted) so the
 *      resolver never returns a missing workspace - a common cause of 404s.
 *   2. Prevent CROSS-TENANT ACCESS: a user who is hard-bound to an institution
 *      (Institution Admin / Meal Manager / Member) can never be switched into -
 *      or read data from - a different institution. Only a Software Super Admin
 *      may carry a session tenant that differs from any single institution.
 *   3. Expose the resolved tenant id on the request for downstream controllers.
 *
 * It does NOT itself filter queries; controllers scope by Institution::current()
 * (which reads the same session tenant). This middleware guarantees that value
 * is always valid and authorised.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $manager = app(TenantManager::class);

        $sessionTenantId = $request->session()->get('tenant_id');

        if ($sessionTenantId) {
            $tenant = Institution::query()->whereKey($sessionTenantId)->first();

            // 1. Stale tenant (deleted/invalid) -> clear it so current() falls
            //    back to the user's own institution instead of resolving nothing.
            if (! $tenant) {
                $request->session()->forget('tenant_id');
                $sessionTenantId = null;
            } elseif (! $this->mayAccessTenant($user, $tenant)) {
                // 2. Cross-tenant attempt by a hard-bound user -> refuse and clear.
                $request->session()->forget('tenant_id');
                $sessionTenantId = null;
            }
        }

        // 3. Publish the resolved tenant id for controllers / logging.
        $resolved = $sessionTenantId
            ? (int) $sessionTenantId
            : ($user->institution_id ? (int) $user->institution_id : null);

        $request->attributes->set('tenant_id', $resolved);

        /* 4. PIN the tenant onto the shared TenantManager.
         *
         * This is the linchpin of strict isolation: from here on, EVERY
         * tenant-owned model's global scope reads this value and filters to it,
         * so no controller can accidentally query another institution's rows.
         *
         * $resolved is null ONLY for a Software Super Admin who has not switched
         * into an institution - the deliberate, global platform view. A bound
         * user (Institution Admin / Meal Manager / Member) always has a non-null
         * institution_id, so they can never reach the unscoped context.
         */
        if ($resolved === null && $user->isSuperAdmin()) {
            // SSA on the platform view: cross-tenant by design.
            $manager->withoutScope();
        } else {
            $manager->force($resolved);
        }

        /*
         * 5. REFLECT THE WORKSPACE TIMEZONE in the operational context.
         *
         * The active institution's timezone (set in Settings → Institution)
         * becomes the request's default timezone, so every `now()`, date format,
         * report heading and scheduled reminder inside this tenant renders in the
         * workspace's own local time. UTC-stored values are unaffected; only the
         * presentation/derivation context shifts per tenant. Guarded so an
         * unknown/blank value never overrides the platform default.
         */
        if ($resolved !== null) {
            $timezone = Institution::query()->whereKey($resolved)->value('timezone');

            if (\App\Support\Timezones::isValid($timezone)) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        }

        return $next($request);
    }

    /**
     * A Software Super Admin may view any workspace. Everyone else may only ever
     * see the institution they are hard-bound to.
     */
    protected function mayAccessTenant($user, Institution $tenant): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->institution_id !== null
            && (int) $user->institution_id === (int) $tenant->id;
    }
}
