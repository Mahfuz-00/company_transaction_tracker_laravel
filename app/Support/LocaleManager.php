<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

/**
 * LOCALE RESOLUTION.
 *
 * Decides which language a request should be rendered in, and remembers the
 * user's choice.
 *
 * RESOLUTION ORDER (first match wins)
 * -----------------------------------
 *   1. The signed-in user's SAVED preference (`users.locale`).
 *      A logged-in choice always beats a browser hint: a Bengali-speaking admin
 *      working on an English-configured browser must not have to re-pick English
 *      on every page load.
 *   2. The session value — set when a guest picks a language from the switcher.
 *      This is what lets a visitor choose before they have an account.
 *   3. The `Accept-Language` header — a first-visit convenience, so a Bengali
 *      browser opens in Bengali without anyone configuring anything.
 *   4. `config('locales.default')`.
 *
 * WHY THE USER ROW OUTRANKS THE SESSION
 * -------------------------------------
 * Signing in on a shared machine must not inherit the previous person's
 * language, and signing out must not forget yours.
 */
class LocaleManager
{
    /** The session key holding a guest's chosen locale. */
    public const SESSION_KEY = 'locale';

    /** Every supported locale code, e.g. ['en', 'bn']. */
    public static function supported(): array
    {
        return array_keys(config('locales.supported', ['en' => []]));
    }

    /** Is this a locale the platform actually ships? */
    public static function isSupported(?string $code): bool
    {
        return $code !== null && in_array($code, static::supported(), true);
    }

    /**
     * Resolve the locale for the current request.
     *
     * Returns a SUPPORTED code always — an unknown value anywhere in the chain is
     * skipped rather than applied, so a stale `users.locale` from a language we
     * later dropped cannot break rendering.
     */
    public static function resolve(): string
    {
        $user = auth()->user();

        if ($user && static::isSupported($user->locale ?? null)) {
            return $user->locale;
        }

        $session = Session::get(static::SESSION_KEY);

        if (static::isSupported($session)) {
            return $session;
        }

        $fromHeader = static::fromAcceptLanguage(request()->header('Accept-Language'));

        if (static::isSupported($fromHeader)) {
            return $fromHeader;
        }

        return config('locales.default', 'en');
    }

    /**
     * Apply a locale to the current request/process.
     *
     * Also sets Carbon's locale so `->translatedFormat('F Y')` renders month names
     * in the active language — a date is content too.
     */
    public static function apply(?string $code = null): string
    {
        $code = static::isSupported($code) ? $code : static::resolve();

        App::setLocale($code);

        // Carbon ships its own locale files; an unsupported one simply no-ops.
        try {
            Carbon::setLocale($code);
        } catch (\Throwable $e) {
            // Never let a missing date locale break a page render.
        }

        return $code;
    }

    /**
     * Parse `Accept-Language` into our best supported match.
     *
     * Handles the real-world header shape: `bn-BD,bn;q=0.9,en;q=0.8`. We compare
     * on the PRIMARY subtag, so `bn-BD` matches our `bn`.
     */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        if (blank($header)) {
            return null;
        }

        // Sort by q-value so a preference order is respected.
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0] ?? ''));

            if ($tag === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($bits, 1) as $param) {
                if (str_contains($param, 'q=')) {
                    $quality = (float) str_replace('q=', '', trim($param));
                }
            }

            $candidates[$tag] = $quality;
        }

        arsort($candidates);

        foreach (array_keys($candidates) as $tag) {
            $primary = explode('-', $tag)[0];

            if (static::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }

    /**
     * Persist a choice: to the user's row when signed in, to the session otherwise.
     *
     * Returns false when the code is not one we ship, so a crafted request cannot
     * write an arbitrary value onto the user.
     */
    public static function persist(string $code): bool
    {
        if (! static::isSupported($code)) {
            return false;
        }

        $user = auth()->user();

        if ($user) {
            // `forceFill` + `save` rather than update(): locale is not mass-assignable
            // on User, and this keeps the write explicit and auditable.
            $user->forceFill(['locale' => $code])->save();
        }

        // Always mirror into the session as well, so signing out keeps the choice.
        Session::put(static::SESSION_KEY, $code);

        return true;
    }

    /** The metadata for a locale (label, rtl, date_locale), or null. */
    public static function meta(string $code): ?array
    {
        return config("locales.supported.{$code}");
    }

    /**
     * The FLAT translation catalogue for the active locale, for the front end.
     *
     * Laravel's `__()` is the right tool for PHP-rendered strings, but the React
     * side needs the same dictionary to translate its own labels — and shipping it
     * as one small JSON object avoids a second HTTP request per page and keeps both
     * halves of the UI on exactly the same wording.
     *
     * Keys are flattened to dot notation (`auth.sign_in`) so a component can call
     * `t('auth.sign_in')` and get a plain string, and a missing key is visible as a
     * dotted path rather than `[object Object]`.
     *
     * A missing key is resolved against the FALLBACK locale first, so a partially
     * translated language degrades to readable English rather than a raw key.
     *
     * @return array<string, string>
     */
    public static function messages(?string $code = null): array
    {
        $code = static::isSupported($code) ? $code : app()->getLocale();
        $fallback = config('locales.fallback', 'en');

        $flatten = static function (array $array, string $prefix = '') use (&$flatten): array {
            $flat = [];

            foreach ($array as $key => $value) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

                if (is_array($value)) {
                    $flat += $flatten($value, $path);
                } else {
                    $flat[$path] = (string) $value;
                }
            }

            return $flat;
        };

        try {
            $base = $flatten((array) trans($fallback === '' ? 'app' : 'app', [], $fallback));
            $active = $code === $fallback ? [] : $flatten((array) trans('app', [], $code));
        } catch (\Throwable $e) {
            // A broken/missing lang file must never take the app down - the React
            // side falls back to its own bundled English defaults.
            return [];
        }

        // Active locale wins; anything it omits keeps the English value.
        return array_merge($base, $active);
    }

    /**
     * The catalogue for the front end: every supported locale with its labels.
     *
     * @return array<int, array{code:string,label:string,english:string,rtl:bool}>
     */
    public static function catalogue(): array
    {
        return collect(config('locales.supported', []))
            ->map(fn (array $meta, string $code) => [
                'code' => $code,
                'label' => $meta['label'],
                'english' => $meta['english'],
                'rtl' => (bool) ($meta['rtl'] ?? false),
            ])
            ->values()
            ->all();
    }
}
