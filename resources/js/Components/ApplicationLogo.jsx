/** Envelope mark in the brand accent. */
export default function ApplicationLogo({ className = 'h-6 w-6' }) {
    return (
        <svg viewBox="0 0 64 64" className={className} aria-hidden="true">
            <rect width="64" height="64" rx="14" fill="var(--accent)" />
            <path d="M14 22h36v22H14z" fill="none" stroke="#fff" strokeWidth="4" strokeLinejoin="round" />
            <path d="M14 24l18 12 18-12" fill="none" stroke="#fff" strokeWidth="4" strokeLinejoin="round" />
        </svg>
    );
}
