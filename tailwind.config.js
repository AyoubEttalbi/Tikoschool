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
                lamaSky: "#C3EBFA",
                lamaSkyLight: "#EDF9FD",
                lamaPurple: "#CFCEFF",
                lamaPurpleLight: "#F1F0FF",
                lamaYellow: "#FAE27C",
                lamaYellowLight: "#FEFCE8",
              },
        },
    },

    plugins: [forms],
};
