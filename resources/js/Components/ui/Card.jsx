const cx = (...a) => a.filter(Boolean).join(' ');

export function CardHeader({ title, description, actions, className, ...rest }) {
    return (
        <div className={cx('flex items-start justify-between gap-4 px-5 py-4 border-b border-line', className)} {...rest}>
            <div className="min-w-0">
                {title != null && <div className="text-body font-semibold">{title}</div>}
                {description != null && <p className="text-sm text-muted mt-0.5">{description}</p>}
            </div>
            {actions != null && <div className="flex-shrink-0 flex items-center gap-2">{actions}</div>}
        </div>
    );
}

/** Surface panel with optional header/footer. `padded={false}` for tables. */
export default function Card({ title, description, actions, header, footer, padded = true, className, children, ...rest }) {
    const hasHeader = header != null || title != null || description != null;

    return (
        <div className={cx('bg-surface border border-line rounded-xl shadow-sm', className)} {...rest}>
            {hasHeader && (header != null ? header : <CardHeader title={title} description={description} actions={actions} />)}
            {children != null && <div className={cx(padded && 'p-5')}>{children}</div>}
            {footer != null && <div className="px-5 py-4 border-t border-line">{footer}</div>}
        </div>
    );
}
