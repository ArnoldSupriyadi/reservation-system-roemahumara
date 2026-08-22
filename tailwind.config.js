import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/Http/Controllers/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: '#111111',
                paper: '#FFFDF5',
                brand: '#FFD400',
                taken: '#FF5A36',
                tentative: '#7FB3FF',
            },
            boxShadow: {
                brut: '6px 6px 0 0 #111111',
                'brut-sm': '3px 3px 0 0 #111111',
            },
        },
    },

    plugins: [],
};
