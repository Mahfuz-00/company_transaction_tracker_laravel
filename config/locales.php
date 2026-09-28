<?php

/**
 * SUPPORTED LOCALES.
 *
 * The single source of truth for which languages the platform offers. Adding a
 * language is a THREE-step change, by design:
 *
 *   1. add an entry here,
 *   2. add `lang/<code>/*.php` translation files,
 *   3. add the matching front-end dictionary in
 *      `resources/js/i18n/locales/<code>.js`.
 *
 * Nothing else has to change — the switcher, the validation messages, the Inertia
 * prop and the React provider all read this list. That is what makes a new
 * customer base a configuration change rather than a code change.
 *
 * Each entry carries:
 *   - `label`    : the language's OWN name (never translated — a Bengali speaker
 *                  looking for Bengali should see "বাংলা", not "Bengali" in a
 *                  language they cannot read).
 *   - `english`  : the English name, for admins scanning a list.
 *   - `rtl`      : right-to-left script? Drives `dir="rtl"` on <html>.
 *   - `date_locale` : the locale string handed to Carbon/intl for date formatting.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | The default locale for a brand-new visitor
    |--------------------------------------------------------------------------
    */
    'default' => env('APP_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Fallback chain
    |--------------------------------------------------------------------------
    | Used when a key is missing from the active locale. English is complete, so a
    | partially-translated language degrades to readable English rather than
    | showing a raw key.
    */
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */
    'supported' => [

        'en' => [
            'label' => 'English',
            'english' => 'English',
            'rtl' => false,
            'date_locale' => 'en_US',
        ],

        'bn' => [
            // Presented in Bengali, because that is how a Bengali speaker finds it.
            'label' => 'বাংলা',
            'english' => 'Bengali',
            'rtl' => false,
            'date_locale' => 'bn_BD',
        ],

        // ------------------------------------------------------------------
        // Add future languages here. The switcher, the Inertia prop and the
        // React provider pick them up automatically.
        //
        // 'ar' => ['label' => 'العربية', 'english' => 'Arabic',  'rtl' => true,  'date_locale' => 'ar_SA'],
        // 'hi' => ['label' => 'हिन्दी',  'english' => 'Hindi',   'rtl' => false, 'date_locale' => 'hi_IN'],
        // 'es' => ['label' => 'Español', 'english' => 'Spanish', 'rtl' => false, 'date_locale' => 'es_ES'],
        // 'id' => ['label' => 'Bahasa',  'english' => 'Indonesian', 'rtl' => false, 'date_locale' => 'id_ID'],
        // ------------------------------------------------------------------
    ],
];
