import { router } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Card, EmptyState, Pagination, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

function Diff({ before, after }) {
    const keys = Array.from(new Set([...Object.keys(before || {}), ...Object.keys(after || {})]));
    if (keys.length === 0) return <span className="text-subtle">—</span>;
    const show = (v) => (v === null || v === undefined ? '∅' : typeof v === 'object' ? JSON.stringify(v) : String(v));
    return (
        <dl className="space-y-0.5 text-xs">
            {keys.map((k) => (
                <div key={k} className="flex flex-wrap gap-x-1.5">
                    <dt className="text-subtle">{k}:</dt>
                    {before && k in before && <dd className="text-danger line-through">{show(before[k])}</dd>}
                    {after && k in after && <dd className="text-success">{show(after[k])}</dd>}
                </div>
            ))}
        </dl>
    );
}

export default function Audit({ logs, filters, actions, timezone }) {
    const filter = (action) => router.get(route('admin.audit.index'), action ? { action } : {}, { preserveState: true, replace: true });

    return (
        <AppShell title="Audit trail">
            <PageShell>
                <PageHeader
                    eyebrow="Admin"
                    title="Audit trail"
                    description="Who changed caps, accounts, users and settings — and when."
                    actions={
                        <div className="w-56">
                            <Select value={filters.action || ''} onChange={(e) => filter(e.target.value)}>
                                <option value="">All actions</option>
                                {actions.map((a) => <option key={a} value={a}>{a}</option>)}
                            </Select>
                        </div>
                    }
                />
                <Card padded={false} footer={<Pagination paginator={logs} />}>
                    {logs.data.length === 0 ? (
                        <EmptyState title="No audit entries yet" description="Changes to settings, accounts and users are recorded here." />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>When</Th>
                                    <Th>Who</Th>
                                    <Th>Action</Th>
                                    <Th>Subject</Th>
                                    <Th>Change</Th>
                                    <Th>IP</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {logs.data.map((l) => (
                                    <Tr key={l.id}>
                                        <Td className="whitespace-nowrap text-muted">{formatDateTime(l.created_at, timezone)}</Td>
                                        <Td className="whitespace-nowrap">{l.user || <span className="text-subtle">system</span>}</Td>
                                        <Td><Badge tone="accent">{l.action}</Badge></Td>
                                        <Td className="whitespace-nowrap text-muted">{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '—'}</Td>
                                        <Td><Diff before={l.before} after={l.after} /></Td>
                                        <Td className="whitespace-nowrap text-subtle">{l.ip || '—'}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </PageShell>
        </AppShell>
    );
}
