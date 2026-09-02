/** Small formatting helpers shared by the log, reports and dashboard. */

export function formatGap(seconds) {
    if (seconds == null) return '—';
    const s = Math.max(0, Math.round(seconds));
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    const rem = s % 60;
    if (m < 60) return rem ? `${m}m ${rem}s` : `${m}m`;
    const h = Math.floor(m / 60);
    const mm = m % 60;
    if (h < 24) return mm ? `${h}h ${mm}m` : `${h}h`;
    const d = Math.floor(h / 24);
    return `${d}d ${h % 24}h`;
}

export function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

/** ISO timestamp → "02 Sep 2026, 14:05" in the team timezone. */
export function formatDateTime(iso, timezone) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone || undefined,
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export function formatTime(iso, timezone) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat('en-GB', { timeZone: timezone || undefined, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export function formatDate(ymd) {
    if (!ymd) return '—';
    const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number);
    if (!y) return ymd;
    return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(Date.UTC(y, m - 1, d)));
}

export function formatUsd(n) {
    const v = Number(n || 0);
    return v < 0.01 && v > 0 ? `$${v.toFixed(4)}` : `$${v.toFixed(2)}`;
}

export function pluralize(n, one, many = `${one}s`) {
    return `${n} ${n === 1 ? one : many}`;
}
