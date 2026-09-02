/** Page container: full width, fluid at any resolution (never centred/max-width). */
export default function PageShell({ children, className = '' }) {
    return <div className={`w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8 ${className}`}>{children}</div>;
}
