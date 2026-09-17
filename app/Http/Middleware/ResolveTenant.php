<?php

namespace App\Http\Middleware;

use App\Models\Institution;
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
