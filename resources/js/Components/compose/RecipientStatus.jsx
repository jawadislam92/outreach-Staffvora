import { AlertTriangle, ShieldBan, UserRoundCheck } from 'lucide-react';
import { Checkbox, Spinner } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

/**
 * Duplicate / suppression status for a recipient.
 * check = { suppressed: bool, history: { count, last_sent_at, last_by, last_account, last_subject, campaigns } | null }
 */
export default function RecipientStatus({ check, checking, ack, onAck, timezone }) {
    if (checking) return <p className="flex items-center gap-2 text-xs text-subtle"><Spinner size={12} /> Checking history…</p>;
    if (!check) return null;
    if (check.suppressed) {
        return (
            <div className="flex items-start gap-2 rounded-lg border border-danger/40 bg-danger-soft p-3 text-sm text-danger">
                <ShieldBan className="mt-0.5 h-4 w-4 shrink-0" />
                <div>This address is on the do-not-contact list. It cannot be emailed from Outreach.</div>
            </div>
        );
    }
    if (check.history && check.history.count > 0) {
        const h = check.history;
        return (
            <div className="rounded-lg border border-warning/40 bg-warning-soft p-3 text-sm">
                <div className="flex items-start gap-2 text-warning">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <div className="font-medium">Already contacted {h.count === 1 ? 'once' : `${h.count} times`}</div>
                        <div className="mt-0.5 text-xs text-muted">
                            Last: {formatDateTime(h.last_sent_at, timezone)} by {h.last_by || '—'} from {h.last_account || '—'}
                            {h.last_subject ? ` · “${h.last_subject}”` : ''}
                            {h.campaigns?.length ? ` · campaigns: ${h.campaigns.join(', ')}` : ''}
                        </div>
                    </div>
                </div>
                <div className="mt-2 pl-6">
                    <Checkbox label="I know — send anyway" checked={!!ack} onChange={(e) => onAck(e.target.checked)} />
                </div>
            </div>
        );
    }
    return <p className="flex items-center gap-1.5 text-xs text-success"><UserRoundCheck className="h-3.5 w-3.5" /> New contact — never emailed before.</p>;
}
