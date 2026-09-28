<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BLOCKS THE SOFTWARE SUPER ADMIN FROM THE MOBILE API.
 *
 * WHY THIS EXISTS
 * ---------------
 * The mobile client is a TENANT field tool — it operates inside one institution.
 * The Software Super Admin is a GLOBAL operator whose token has no tenant scope
 * (`TenantManager::resolveTenantId()` returns null for them), so an SSA request
 * would run tenant queries WITHOUT an institution filter and expose every
 * workspace's data. That is the exact opposite of what the app is for.
 *
 * The rule is already enforced at the door (`AuthController::login()` refuses to
 * mint an SSA token, and `::me()` refuses to honour one). This middleware is the
 * third layer: it sits on the whole authenticated route group, so a token that
 * somehow reached a protected endpoint — minted by an older client, a direct
 * database insert, or a future auth path that forgot the check — is still
 * refused. Defence in depth on the boundary that matters most.
 *
 * It must NEVER produce a 500 on a legitimately authenticated tenant user, so it
 * only inspects the role and otherwise passes the request straight through.
 */
class EnsureNotSoftwareSuperAdmin
{
    /** The refusal text, identical to AuthController::SSA_MOBILE_REFUSAL. */
    public const MESSAGE = 'Platform administrators must use the web console.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
