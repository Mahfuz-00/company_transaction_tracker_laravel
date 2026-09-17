<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a user with a temporary password to change it before continuing.
 *
 * Runs right after auth. Any authenticated request from a flagged user is
 * redirected to the change-password screen, EXCEPT the few routes needed to
 * actually change it or log out - otherwise they'd be stuck in a loop.
 *
 * Once SMTP is available and account creation is invite-only, no user is ever
 * flagged, so this middleware becomes a harmless no-op.
 */
class EnsurePasswordIsChanged
{
    /**
     * Routes a flagged user is still allowed to reach.
     */
    protected array $allowed = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->mustChangePassword()) {
            return $next($request);
        }

        $current = $request->route()?->getName();

        // Let the change-password screen (and logout) through.
        if ($current && in_array($current, $this->allowed, true)) {
            return $next($request);
        }

        // Inertia expects a redirect response; a plain one is followed correctly.
        return redirect()->route('password.change');
    }
}
