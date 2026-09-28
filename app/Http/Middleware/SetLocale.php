<?php

namespace App\Http\Middleware;

use App\Support\LocaleManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SET THE APPLICATION LOCALE FOR THIS REQUEST.
 *
 * Registered in the `web` group, before anything that renders a response, so every
 * Inertia page, validation message and flash string is produced in the user's
 * language.
 *
 * It only ever SETS the locale — resolution order and persistence live in
 * LocaleManager, so there is one place to reason about "which language is this
 * request in?".
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        LocaleManager::apply();

        return $next($request);
    }
}
