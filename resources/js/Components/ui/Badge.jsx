const cx = (...a) => a.filter(Boolean).join(' ');

const TONES = {
    neutral: 'bg-surface-2 text-muted',
    accent: 'bg-accent/10 text-accent',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
};

const SIZES = {
    sm: 'text-xs px-2 py-0.5',
    md: 'text-sm px-2.5 py-1',
};

/** Compact status pill. */
export default function Badge({ tone = 'neutral', dot = false, size = 'sm', className, children, ...rest }) {
    return (
        <span
            className={cx('inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap', TONES[tone] || TONES.neutral, SIZES[size] || SIZES.sm, className)}
            {...rest}
        >
            {dot && <span aria-hidden="true" className={cx('shrink-0 rounded-full bg-current', size === 'md' ? 'h-2 w-2' : 'h-1.5 w-1.5')} />}
            {children}
        </span>
    );
}
