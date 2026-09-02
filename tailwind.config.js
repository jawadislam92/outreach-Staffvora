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
                sans: ['Figtree', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Semantic theme tokens backed by the CSS vars in resources/css/app.css.
                app: 'var(--bg)',
                surface: 'var(--surface)',
                'surface-2': 'var(--surface-2)',
                line: 'var(--border)',
                body: 'var(--text)',
                muted: 'var(--text-muted)',
                subtle: 'var(--text-subtle)',
                accent: 'var(--accent)',
                success: 'var(--success)',
                'success-soft': 'var(--success-soft)',
                warning: 'var(--warning)',
                'warning-soft': 'var(--warning-soft)',
                danger: 'var(--danger)',
                'danger-soft': 'var(--danger-soft)',
                info: 'var(--info)',
                'info-soft': 'var(--info-soft)',
            },
        },
    },

    plugins: [forms],
};
