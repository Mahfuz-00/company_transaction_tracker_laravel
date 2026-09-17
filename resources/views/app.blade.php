<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" /> --}}

        <!-- Fonts (Figtree + Hind Siliguri for Bengali support) -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|hind-siliguri:400,500,600,700&display=swap" rel="stylesheet" />

        {{--
            THEME INJECTION (server-side, no flash).

            The active institution's theme tokens are written as CSS custom
            properties on :root BEFORE React mounts, so the very first paint
            already uses the admin's chosen accent / radius / mode. React's
            ThemeProvider keeps them in sync on client-side navigations.

            Values come from the Inertia page props (shared `institution`), which
            read the institution row the SSA configured in Settings.
        --}}
        @php
            $themeInstitution = data_get($page, 'props.institution');
            $accent = data_get($themeInstitution, 'accent', []);
            $theme = data_get($themeInstitution, 'theme', []);
            $accentHex = $accent['hex'] ?? '#4f46e5';
            $accentSoft = $accent['soft'] ?? '#eef2ff';
            $radiusMap = ['sm' => '0.375rem', 'md' => '0.5rem', 'lg' => '0.75rem', 'xl' => '1rem'];
            $radius = $radiusMap[$theme['radius'] ?? 'lg'] ?? '0.75rem';
            $mode = $theme['mode'] ?? 'light';
        @endphp
        <style>
            :root {
                --accent: {{ $accentHex }};
                --accent-soft: {{ $accentSoft }};
                --accent-ring: {{ $accentHex }}33;
                --primary-color: {{ $accentHex }};
                --primary-soft: {{ $accentSoft }};
                --radius: {{ $radius }};
                color-scheme: {{ $mode }};
            }
        </style>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased" data-theme-mode="{{ $mode }}">
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
