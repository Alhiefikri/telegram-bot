/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './app/Filament/**/*.php',
        './resources/views/**/*.blade.php',
        './resources/views/filament/**/*.blade.php',
        './resources/js/**/*.js',
        './storage/framework/views/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/filament/**/*.php',
    ],
    theme: {
        extend: {
            animation: {
                'storefront-float': 'storefront-float 7s ease-in-out infinite',
                'storefront-reveal': 'storefront-reveal 700ms cubic-bezier(0.22, 1, 0.36, 1) both',
            },
            fontFamily: {
                sans: ['Instrument Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            keyframes: {
                'storefront-float': {
                    '0%, 100%': { transform: 'translate3d(0, 0, 0) rotate(0deg)' },
                    '50%': { transform: 'translate3d(0, -18px, 0) rotate(1.5deg)' },
                },
                'storefront-reveal': {
                    from: { opacity: '0', transform: 'translate3d(0, 18px, 0) scale(0.98)' },
                    to: { opacity: '1', transform: 'translate3d(0, 0, 0) scale(1)' },
                },
            },
        },
    },
    plugins: [],
};
