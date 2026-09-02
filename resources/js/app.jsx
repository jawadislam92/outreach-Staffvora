import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'react-hot-toast';
import ErrorBoundary from '@/Components/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Outreach';

// Keep <html data-theme> in sync with the server-resolved theme after every
// visit (the cookie is what app.blade.php reads for a flash-free first paint).
const applyTheme = (page) => {
    const t = page?.props?.theme;
    if (t === 'light' || t === 'dark' || t === 'black') {
        document.documentElement.dataset.theme = t;
    }
};
router.on('success', (event) => applyTheme(event.detail.page));

// A deploy that ships new asset hashes can 404 a dynamic import in a browser
// still holding the old page; reload once instead of rendering blank.
window.addEventListener('vite:preloadError', () => {
    const last = Number(sessionStorage.getItem('vitePreloadReloadAt') || 0);
    if (Date.now() - last > 10000) {
        sessionStorage.setItem('vitePreloadReloadAt', String(Date.now()));
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        applyTheme(props.initialPage);
        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
                <Toaster position="top-right" gutter={8} />
            </ErrorBoundary>
        );
    },
    progress: {
        color: '#2563eb',
        includeCSS: true,
        showSpinner: false,
    },
});
