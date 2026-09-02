import { Badge } from '@/Components/ui';
import { formatGap } from '@/Utils/format';

const cx = (...a) => a.filter(Boolean).join(' ');

/** Sending-account chooser with today's cap usage and a soft minimum-gap warning. */
export default function AccountPicker({ accounts, value, onChange, minGap, error }) {
    return (
        <div className="space-y-2">
            {accounts.map((row) => {
                const a = row.account;
                const usable = a.status === 'active' && row.remaining > 0;
                const selected = Number(value) === a.id;
                const sinceLast = row.last_sent_at ? Math.round((Date.now() - new Date(row.last_sent_at).getTime()) / 1000) : null;
                const tooSoon = usable && sinceLast != null && sinceLast < minGap;
                return (
                    <button key={a.id} type="button" disabled={!usable} onClick={() => onChange(a.id)}
                        className={cx('w-full rounded-xl border p-3 text-left transition',
                            selected ? 'border-accent bg-accent/5 ring-1 ring-accent' : 'border-line bg-surface hover:bg-surface-2',
                            !usable && 'cursor-not-allowed opacity-60')}>
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="truncate text-sm font-medium text-body">{a.label}</div>
                                <div className="truncate text-xs text-subtle">{a.from_email}</div>
                            </div>
                            <Badge tone={a.status !== 'active' ? 'warning' : row.remaining === 0 ? 'danger' : 'success'}>
                                {a.status !== 'active' ? a.status_label : row.remaining === 0 ? 'Cap reached' : `${row.remaining} of ${row.cap} left`}
                            </Badge>
                        </div>
                        <div className="mt-2 h-1 w-full overflow-hidden rounded-full bg-surface-2">
                            <div className={cx('h-full rounded-full', row.remaining === 0 ? 'bg-danger' : 'bg-accent')} style={{ width: `${row.cap ? Math.min(100, Math.round((row.used / row.cap) * 100)) : 0}%` }} />
                        </div>
                        <div className="mt-1.5 flex items-center justify-between text-[11px] text-subtle">
                            <span>Last send: {sinceLast == null ? 'never' : `${formatGap(sinceLast)} ago`}</span>
                            {tooSoon && <span className="font-medium text-warning">Under the {formatGap(minGap)} gap</span>}
                        </div>
                    </button>
                );
            })}
            {accounts.length === 0 && <p className="text-sm text-muted">You have no sending accounts yet. Ask an admin to give you access to one.</p>}
            {error && <p className="text-xs text-danger">{error}</p>}
        </div>
    );
}
