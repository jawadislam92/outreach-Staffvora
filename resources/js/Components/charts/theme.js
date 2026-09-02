import React from 'react';

/** Current theme name off <html data-theme>; defaults to "light". */
export function readThemeName() {
    if (typeof document === 'undefined') return 'light';
    return document.documentElement.getAttribute('data-theme') || 'light';
}

/** Glow is a dark/black-only treatment — light stays crisp. */
export function themeAllowsGlow(theme = readThemeName()) {
    return theme !== 'light';
}

/** Live theme name, re-rendering when data-theme flips. */
export function useThemeName() {
    const [theme, setTheme] = React.useState(readThemeName);
    React.useEffect(() => {
        if (typeof document === 'undefined') return undefined;
        const root = document.documentElement;
        const read = () => setTheme(root.getAttribute('data-theme') || 'light');
        read();
        const observer = new MutationObserver(read);
        observer.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
        return () => observer.disconnect();
    }, []);
    return theme;
}

export function cssVar(name, fallback = '') {
    if (typeof document === 'undefined') return fallback;
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
}

export function vizColor(slot) {
    const n = Math.min(5, Math.max(1, Number(slot) || 1));
    return cssVar(`--viz-${n}`, '#2563eb');
}

export function vizPalette() {
    return [1, 2, 3, 4, 5].map(vizColor);
}

/** Text tokens for on-canvas text (Chart.js can't consume var() strings). */
export function textTokens() {
    return {
        body: cssVar('--text', '#0f172a'),
        muted: cssVar('--text-muted', '#475569'),
        subtle: cssVar('--text-subtle', '#616f85'),
        line: cssVar('--border', '#e2e8f0'),
    };
}

export function withAlpha(color, alpha) {
    const hex = /^#([0-9a-f]{6})$/i.exec((color || '').trim());
    if (hex) {
        const int = parseInt(hex[1], 16);
        return `rgba(${(int >> 16) & 255}, ${(int >> 8) & 255}, ${int & 255}, ${alpha})`;
    }
    const rgb = /^rgba?\(([^)]+)\)$/i.exec((color || '').trim());
    if (rgb) {
        const [r, g, b] = rgb[1].split(',').map((s) => s.trim());
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }
    return color;
}
