import { forwardRef } from 'react';
import { Link } from '@inertiajs/react';
import Spinner from './Spinner';

const cx = (...a) => a.filter(Boolean).join(' ');

const VARIANTS = {
    primary: 'bg-accent text-white hover:brightness-110 active:brightness-95 border border-transparent shadow-sm',
    secondary: 'bg-surface text-body border border-line hover:bg-surface-2',
    ghost: 'bg-transparent text-muted border border-transparent hover:bg-surface-2 hover:text-body',
    danger: 'bg-danger text-white hover:brightness-110 active:brightness-95 border border-transparent',
    'danger-soft': 'bg-danger-soft text-danger border border-transparent hover:brightness-95',
};

const SIZES = {
    xs: 'h-7 px-2.5 text-xs gap-1',
    sm: 'h-8 px-3 text-xs gap-1.5',
    md: 'h-10 px-4 text-sm gap-2',
};

/**
 * Button — renders a <button>, an <a> (href) or an Inertia <Link> (to).
 * `loading` keeps the width and swaps the label for a spinner.
 */
const Button = forwardRef(function Button(
    { variant = 'primary', size = 'md', loading = false, icon, iconTrailing, block = false, href, to, disabled = false, type, className, children, ...rest },
    ref
) {
    const isDisabled = disabled || loading;
    const classes = cx(
        'relative inline-flex items-center justify-center font-medium rounded-lg select-none whitespace-nowrap',
        'cursor-pointer transition',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        VARIANTS[variant] || VARIANTS.primary,
        SIZES[size] || SIZES.md,
        block && 'w-full',
        loading && 'pointer-events-none',
        isDisabled && !loading && 'opacity-50 pointer-events-none',
        className
    );

    const inner = (
        <>
            {loading && (
                <span className="absolute inset-0 flex items-center justify-center">
                    <Spinner size={size === 'md' ? 16 : 14} />
                </span>
            )}
            <span className={cx('inline-flex items-center justify-center', size === 'md' ? 'gap-2' : 'gap-1.5', loading && 'invisible')}>
                {icon != null && <span className="inline-flex shrink-0 items-center">{icon}</span>}
                {children != null && <span>{children}</span>}
                {iconTrailing != null && <span className="inline-flex shrink-0 items-center">{iconTrailing}</span>}
            </span>
        </>
    );

    if (to) {
        return (
            <Link ref={ref} href={to} className={classes} aria-disabled={isDisabled || undefined} {...rest}>
                {inner}
            </Link>
        );
    }
    if (href) {
        return (
            <a ref={ref} href={isDisabled ? undefined : href} className={classes} aria-disabled={isDisabled || undefined} {...rest}>
                {inner}
            </a>
        );
    }
    return (
        <button ref={ref} type={type || 'button'} disabled={isDisabled} className={classes} {...rest}>
            {inner}
        </button>
    );
});

export default Button;
