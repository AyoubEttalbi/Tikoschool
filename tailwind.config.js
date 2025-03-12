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
            backgroundImage: {
                "gradient-radial": "radial-gradient(var(--tw-gradient-stops))",
                "gradient-conic":
                  "conic-gradient(from 180deg at 50% 50%, var(--tw-gradient-stops))",
              },
              colors: {
                lamaSky: "#A0D8EF",        // Soft sky blue
                lamaSkyLight: "#E0F7FA",   // Lighter sky blue
                lamaPurple: "#B39DDB",
                lamaPurpleLight: "#F1F0FF",
                lamaYellow: "#FFD54F",     // Warm amber yellow
                lamaYellowLight: "#FFF8E1",// Light warm yellow
                lamaBlue: "#6495ED",       // Darker blue
              }
        },
    },

    plugins: [forms],
};
