import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // A large-screen / TV breakpoint beyond Tailwind's stock 2xl (1536px),
            // so the app shell and dense grids can use the extra width on 1920px+
            // displays instead of staying capped in the middle of the screen.
            screens: {
                '3xl': '1920px',
            },
        },
    },

    plugins: [forms],
};
