import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Search, ShieldBan, ShieldPlus, Trash2 } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, Pagination, Table, TBody, Td, Th, THead, Textarea, Tr } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

export default function Suppressions({ rows, filters, total }) {
    const { app } = usePage().props;
    const { data, setData, post, processing, errors, reset } = useForm({ emails: '', reason: '' });
    const [q, setQ] = useState(filters.q || '');
    const [removing, setRemoving] = useState(null);
    const [busy, setBusy] = useState(false);

    const add = (e) => {
        e.preventDefault();
        post(route('admin.suppressions.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const search = (e) => {
        e?.preventDefault();
        const term = q.trim();
        router.get(route('admin.suppressions.index'), term ? { q: term } : {}, { preserveState: true, replace: true });
    };

    const clear = () => {
        setQ('');
        router.get(route('admin.suppressions.index'), {}, { preserveState: true, replace: true });
    };

    const remove = () => {
        setBusy(true);
        router.delete(route('admin.suppressions.destroy', removing.id), {
            preserveScroll: true,
            onSuccess: () => setRemoving(null),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <AppShell title="Do-not-contact list">
            <PageShell>
                <PageHeader
                    eyebrow="Admin"
                    title="Do-not-contact list"
                    description="Addresses here can never be emailed from Outreach, from any account; imports flag them and Compose blocks them."
                    actions={<Badge size="md" tone={total > 0 ? 'accent' : 'neutral'}>{total} {total === 1 ? 'address' : 'addresses'}</Badge>}
                />

                <div className="grid gap-6 xl:grid-cols-3">
                    <Card title="Add addresses" description="One per line, or separated by commas / semicolons. “Name <address>” is fine too." className="self-start">
                        <form onSubmit={add} className="space-y-4">
                            <Field label="Email addresses" htmlFor="s-emails" required error={errors.emails}>
                                <Textarea id="s-emails" rows={6} value={data.emails} onChange={(e) => setData('emails', e.target.value)} placeholder={'jane@acme.com\nJohn Doe <john@example.org>'} className="font-mono text-[13px]" maxLength={5000} required />
                            </Field>
                            <Field label="Reason (optional)" htmlFor="s-reason" error={errors.reason} hint="Shown in the list and recorded in the audit trail.">
                                <Input id="s-reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="Asked to stop, bounced, competitor…" maxLength={255} />
                            </Field>
                            <div className="flex justify-end">
                                <Button type="submit" icon={<ShieldPlus className="h-4 w-4" />} loading={processing}>Add to list</Button>
                            </div>
                        </form>
                    </Card>

                    <Card padded={false} className="xl:col-span-2" footer={<Pagination paginator={rows} />}>
                        <form onSubmit={search} className="flex gap-2 border-b border-line p-4">
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search addresses" aria-label="Search addresses" />
                            <Button type="submit" variant="secondary" icon={<Search className="h-4 w-4" />} aria-label="Search" />
                            {filters.q && <Button type="button" variant="ghost" onClick={clear}>Clear</Button>}
                        </form>

                        {rows.data.length === 0 ? (
                            <EmptyState
                                icon={<ShieldBan className="h-5 w-5" />}
                                title={filters.q ? 'No addresses match' : 'Nobody is blocked yet'}
                                description={filters.q ? `Nothing on the list contains “${filters.q}”.` : 'Add anyone who asked not to be contacted; the list applies to every account and campaign.'}
                            />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Address</Th>
                                        <Th>Reason</Th>
                                        <Th>Added by</Th>
                                        <Th>Added</Th>
                                        <Th className="text-right">Actions</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {rows.data.map((r) => (
                                        <Tr key={r.id}>
                                            <Td className="font-medium text-body">{r.email}</Td>
                                            <Td className="max-w-[320px] truncate text-muted" title={r.reason || undefined}>{r.reason || <span className="text-subtle">—</span>}</Td>
                                            <Td className="whitespace-nowrap">{r.added_by || <span className="text-subtle">—</span>}</Td>
                                            <Td className="whitespace-nowrap text-muted">{formatDateTime(r.created_at, app.timezone)}</Td>
                                            <Td className="text-right">
                                                <Button variant="ghost" size="xs" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={() => setRemoving(r)}>Remove</Button>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>

                <ConfirmDialog
                    open={!!removing}
                    title={`Remove ${removing?.email}?`}
                    description="Anyone on the team can email this address again straight away."
                    confirmLabel="Remove from list"
                    tone="danger"
                    loading={busy}
                    onClose={() => setRemoving(null)}
                    onConfirm={remove}
                />
            </PageShell>
        </AppShell>
    );
}
