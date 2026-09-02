import React from 'react';
import { useThemeName, themeAllowsGlow } from './theme';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * Tiny smooth trend line that stretches to its box. Colour follows
 * currentColor unless `accent` (1..5) picks a --viz slot.
 */
export default function Sparkline({ data = [], height = 32, area = false, accent, glow = false, className, style, ...rest }) {
    const W = 100;
    const H = Math.max(1, height);
    const pad = 2;
    const gid = React.useId();
    const theme = useThemeName();

    const hasAccent = accent != null;
    const drawArea = area || hasAccent;
    const drawGlow = glow && themeAllowsGlow(theme);
    const svgStyle = hasAccent ? { color: `var(--viz-${accent})`, ...style } : style;

    const nums = Array.isArray(data) ? data.filter((n) => typeof n === 'number' && !Number.isNaN(n)) : [];

    const { line, fill } = React.useMemo(() => {
        if (nums.length === 0) {
            const y = H / 2;
            return { line: `M0,${y} L${W},${y}`, fill: '' };
        }
        const min = Math.min(...nums);
        const max = Math.max(...nums);
        const span = max - min || 1;
        const n = nums.length;
        const stepX = n > 1 ? W / (n - 1) : 0;
        const pts = nums.map((v, i) => [n > 1 ? i * stepX : W / 2, pad + (1 - (v - min) / span) * (H - pad * 2)]);

        if (n === 1) {
            const y = pts[0][1];
            const l = `M0,${y} L${W},${y}`;
            return { line: l, fill: drawArea ? `${l} L${W},${H} L0,${H} Z` : '' };
        }

        let d = `M${pts[0][0]},${pts[0][1]}`;
        for (let i = 0; i < n - 1; i++) {
            const p0 = pts[i - 1] || pts[i];
            const p1 = pts[i];
            const p2 = pts[i + 1];
            const p3 = pts[i + 2] || p2;
            const c1x = p1[0] + (p2[0] - p0[0]) / 6;
            const c1y = p1[1] + (p2[1] - p0[1]) / 6;
            const c2x = p2[0] - (p3[0] - p1[0]) / 6;
            const c2y = p2[1] - (p3[1] - p1[1]) / 6;
            d += ` C${c1x.toFixed(2)},${c1y.toFixed(2)} ${c2x.toFixed(2)},${c2y.toFixed(2)} ${p2[0].toFixed(2)},${p2[1].toFixed(2)}`;
        }
        return { line: d, fill: drawArea ? `${d} L${W},${H} L0,${H} Z` : '' };
    }, [nums, H, drawArea]);

    return (
        <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="none" fill="none" role="img" aria-hidden="true"
            className={cx('block overflow-visible', className)} style={svgStyle} {...rest}>
            {drawArea && fill ? (
                <>
                    <defs>
                        <linearGradient id={`spark-${gid}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
                            <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <path d={fill} fill={`url(#spark-${gid})`} stroke="none" />
                </>
            ) : null}
            <path d={line} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke"
                style={drawGlow ? { filter: 'drop-shadow(0 0 5px currentColor)' } : undefined} />
        </svg>
    );
}
