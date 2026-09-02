const cx = (...a) => a.filter(Boolean).join(' ');

/** Small spinning arc drawn in currentColor. */
export default function Spinner({ size = 16, className, ...rest }) {
    return (
        <svg
            className={cx('animate-spin', className)}
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            role="status"
            aria-label="Loading"
            {...rest}
        >
            <circle cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="2" className="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className="opacity-90" />
        </svg>
    );
}
