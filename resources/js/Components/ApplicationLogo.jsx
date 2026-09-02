/** Paper-plane mark on the brand gradient. */
export default function ApplicationLogo({ className = 'h-9 w-9' }) {
    return (
        <svg viewBox="0 0 64 64" className={className} aria-hidden="true">
            <defs>
                <linearGradient id="outreach-brand" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stopColor="var(--grad-from, #7c3aed)" />
                    <stop offset="100%" stopColor="var(--grad-to, #d946ef)" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="16" fill="url(#outreach-brand)" />
            <path
                d="M51 21 15.5 35.5c-1.8.75-1.7 3.3.15 3.9l10.1 3.3 3.3 10.1c.6 1.85 3.15 1.95 3.9.15L47.5 17.5"
                fill="none"
                stroke="#fff"
                strokeWidth="4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path d="M26 42.5 48.5 24" fill="none" stroke="#fff" strokeWidth="4" strokeLinecap="round" />
        </svg>
    );
}
