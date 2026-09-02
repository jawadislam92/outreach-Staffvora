const cx = (...a) => a.filter(Boolean).join(' ');

function Skeleton({ className, rounded = 'rounded-md', ...rest }) {
    return <div aria-hidden="true" className={cx('animate-pulse bg-surface-2', rounded, className)} {...rest} />;
}

function SkeletonText({ lines = 3, className, ...rest }) {
    const widths = ['w-full', 'w-11/12', 'w-4/5', 'w-3/4', 'w-2/3', 'w-1/2'];
    return (
        <div className={cx('space-y-2.5', className)} {...rest}>
            {Array.from({ length: Math.max(0, lines) }).map((_, i) => (
                <Skeleton key={i} className={cx('h-3.5', i === lines - 1 ? 'w-1/2' : widths[i % widths.length])} />
            ))}
        </div>
    );
}

function SkeletonCard({ className, ...rest }) {
    return (
        <div className={cx('bg-surface border border-line rounded-xl p-5 shadow-sm', className)} {...rest}>
            <div className="space-y-4">
                <Skeleton className="h-5 w-1/3" rounded="rounded-lg" />
                <SkeletonText lines={2} />
                <Skeleton className="h-32 w-full" rounded="rounded-lg" />
            </div>
        </div>
    );
}

export default Skeleton;
export { Skeleton, SkeletonText, SkeletonCard };
