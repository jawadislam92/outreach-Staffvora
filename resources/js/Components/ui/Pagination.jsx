import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

/** Renders Laravel paginator meta ({current_page, last_page, from, to, total, prev_page_url, next_page_url}). */
export default function Pagination({ paginator, className }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const btn = 'inline-flex h-8 items-center gap-1 rounded-lg border border-line bg-surface px-2.5 text-xs font-medium text-body transition hover:bg-surface-2';
    const disabled = 'pointer-events-none opacity-40';

    return (
        <div className={cx('flex flex-wrap items-center justify-between gap-3 text-xs text-muted', className)}>
            <span>
                Showing <span className="text-body tabular-nums">{paginator.from ?? 0}–{paginator.to ?? 0}</span> of{' '}
                <span className="text-body tabular-nums">{paginator.total}</span>
            </span>
            <div className="flex items-center gap-2">
                <Link href={paginator.prev_page_url || '#'} preserveScroll preserveState className={cx(btn, !paginator.prev_page_url && disabled)}>
                    <ChevronLeft className="h-3.5 w-3.5" /> Prev
                </Link>
                <span className="tabular-nums">
                    Page {paginator.current_page} / {paginator.last_page}
                </span>
                <Link href={paginator.next_page_url || '#'} preserveScroll preserveState className={cx(btn, !paginator.next_page_url && disabled)}>
                    Next <ChevronRight className="h-3.5 w-3.5" />
                </Link>
            </div>
        </div>
    );
}
