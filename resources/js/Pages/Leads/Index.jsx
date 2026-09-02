import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Download, FolderInput, PenSquare, Plus, Trash2, Upload, UsersRound } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, Checkbox, ConfirmDialog, EmptyState, Field, Input, Pagination, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatDate } from '@/Utils/format';

const LEAD_TONE = { pending: 'neutral', generating: 'info', ready: 'accent', sent: 'success', skipped: 'neutral', failed: 'danger' };

function AddLeadModal({ open, onClose }) {
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', first_name: '', last_name: '', company: '', notes: '' });
    const submit = (e) => {
        e.preventDefault();
        post(route('leads.store'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };
    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-semibold text-body">Add a lead</h2>
                <p className="mt-1 text-sm text-muted">Goes into your shared list — add it to a campaign whenever you're ready.</p>
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Email" htmlFor="l-email" required error={errors.email} className="sm:col-span-2">
                        <Input id="l-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required autoFocus />
                    </Field>
                    <Field label="First name" htmlFor="l-first" error={errors.first_name}>
                        <Input id="l-first" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} />
                    </Field>
                    <Field label="Last name" htmlFor="l-last" error={errors.last_name}>
                        <Input id="l-last" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} />
                    </Field>
                    <Field label="Company" htmlFor="l-company" error={errors.company} className="sm:col-span-2">
                        <Input id="l-company" value={data.company} onChange={(e) => setData('company', e.target.value)} />
                    </Field>
                    <Field label="Notes (used for AI personalisation)" htmlFor="l-notes" error={errors.notes} className="sm:col-span-2">
                        <Input id="l-notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Met at the expo, runs a 12-person agency…" />
                    </Field>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={processing}>Add lead</Button>
                </div>
            </form>
        </Modal>
    );
}

export default function Index({ leads, filters, campaigns, counts, timezone }) {
    const [adding, setAdding] = useState(false);
    const [selected, setSelected] = useState([]);
    const [assignOpen, setAssignOpen] = useState(false);
    const [assignCampaign, setAssignCampaign] = useState('');
    const [removing, setRemoving] = useState(null);
    const [busy, setBusy] = useState(false);
    const [f, setF] = useState({ q: filters.q || '', scope: filters.scope || '' });

    const apply = (next) => {
        setF(next);
        router.get(route('leads.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    };

    const pageIds = leads.data.filter((l) => !l.campaign).map((l) => l.id);
    const allChecked = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggleAll = () => setSelected(allChecked ? selected.filter((id) => !pageIds.includes(id)) : [...new Set([...selected, ...pageIds])]);
    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const assign = () => {
        if (!assignCampaign) return;
        setBusy(true);
        router.post(route('leads.assign'), { lead_ids: selected, campaign_id: Number(assignCampaign) }, {
            preserveScroll: true,
            onSuccess: () => { setSelected([]); setAssignOpen(false); },
            onFinish: () => setBusy(false),
        });
    };

    const composeUrl = (l) => route('compose.create', { to: l.email, to_name: l.name || '', company: l.company || '' });

    return (
        <AppShell title="Leads">
            <PageShell>
                <PageHeader
                    title="Leads"
                    description={`${counts.all} people in your list · ${counts.unassigned} not in any campaign yet.`}
                    actions={
                        <>
                            <Button variant="secondary" icon={<Download className="h-4 w-4" />} href={route('leads.export')}>Export</Button>
                            <Button variant="secondary" icon={<Upload className="h-4 w-4" />} to={route('leads.import.create')}>Import CSV</Button>
                            <Button icon={<Plus className="h-4 w-4" />} onClick={() => setAdding(true)}>Add lead</Button>
                        </>
                    }
                />

                <Card padded={false}>
                    <div className="flex flex-wrap items-center gap-3 border-b border-line p-4">
                        <div className="w-60">
                            <Select value={f.scope} onChange={(e) => apply({ ...f, scope: e.target.value })}>
                                <option value="">Everyone ({counts.all})</option>
                                <option value="unassigned">Not in a campaign ({counts.unassigned})</option>
                                {campaigns.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                            </Select>
                        </div>
                        <div className="w-72 max-w-full">
                            <Input value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply(f)} placeholder="Search name, email, company…" />
                        </div>
                        {selected.length > 0 && (
                            <div className="ml-auto flex items-center gap-2">
                                <span className="text-sm text-muted">{selected.length} selected</span>
                                <Button size="sm" icon={<FolderInput className="h-4 w-4" />} onClick={() => setAssignOpen(true)}>Add to campaign</Button>
                            </div>
                        )}
                    </div>

                    {leads.data.length === 0 ? (
                        <EmptyState
                            icon={<UsersRound className="h-5 w-5" />}
                            title={counts.all === 0 ? 'No leads yet' : 'No leads match'}
                            description={counts.all === 0 ? 'Add people by hand or import a CSV — they live here until you put them in a campaign.' : 'Try a different search or filter.'}
                            action={counts.all === 0 ? <Button onClick={() => setAdding(true)}>Add your first lead</Button> : null}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th className="w-10"><Checkbox checked={allChecked} onChange={toggleAll} aria-label="Select unassigned on this page" /></Th>
                                    <Th>Lead</Th>
                                    <Th>Company</Th>
                                    <Th>Campaign</Th>
                                    <Th>Status</Th>
                                    <Th>Added</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {leads.data.map((l) => (
                                    <Tr key={l.id}>
                                        <Td>{!l.campaign && <Checkbox checked={selected.includes(l.id)} onChange={() => toggle(l.id)} aria-label={`Select ${l.email}`} />}</Td>
                                        <Td>
                                            <div className="font-medium text-body">{l.name || <span className="text-subtle">—</span>}</div>
                                            <div className="flex items-center gap-2 text-xs text-subtle">
                                                {l.email}
                                                {l.previously_contacted_count > 0 && <Badge tone="warning">contacted {l.previously_contacted_count}×</Badge>}
                                            </div>
                                        </Td>
                                        <Td className="text-muted">{l.company || '—'}</Td>
                                        <Td>{l.campaign ? <Badge tone="accent">{l.campaign.name}</Badge> : <span className="text-subtle">—</span>}</Td>
                                        <Td><Badge tone={LEAD_TONE[l.status]} dot>{l.campaign ? l.status_label : 'In list'}</Badge></Td>
                                        <Td className="whitespace-nowrap text-muted">{formatDate(l.created_at?.slice(0, 10))}</Td>
                                        <Td className="text-right">
                                            <div className="inline-flex gap-1">
                                                {l.status !== 'sent' && <Button size="xs" variant="secondary" icon={<PenSquare className="h-3.5 w-3.5" />} to={composeUrl(l)}>Compose</Button>}
                                                {l.campaign && l.status !== 'sent' && <Button size="xs" variant="ghost" to={route('campaigns.queue', { campaign: l.campaign.id, lead: l.id })}>Open</Button>}
                                                {l.status !== 'sent' && (
                                                    <Button size="xs" variant="ghost" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={() => setRemoving(l)} aria-label={`Remove ${l.email}`} />
                                                )}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <div className="border-t border-line px-4 py-3"><Pagination paginator={leads} /></div>
                </Card>

                <AddLeadModal open={adding} onClose={() => setAdding(false)} />

                <Modal show={assignOpen} onClose={() => setAssignOpen(false)} maxWidth="md">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">Add {selected.length} lead{selected.length === 1 ? '' : 's'} to a campaign</h2>
                        <p className="mt-1 text-sm text-muted">They join the campaign's draft queue; already-present addresses are skipped.</p>
                        <div className="mt-4">
                            <Field label="Campaign" htmlFor="assign-campaign">
                                <Select id="assign-campaign" value={assignCampaign} onChange={(e) => setAssignCampaign(e.target.value)}>
                                    <option value="">Choose…</option>
                                    {campaigns.filter((c) => c.active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            </Field>
                        </div>
                        <div className="mt-6 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setAssignOpen(false)}>Cancel</Button>
                            <Button loading={busy} disabled={!assignCampaign} onClick={assign}>Add to campaign</Button>
                        </div>
                    </div>
                </Modal>

                <ConfirmDialog
                    open={!!removing}
                    title={`Remove ${removing?.email}?`}
                    description="The lead is deleted from your list (and its campaign, if any). Emails already sent stay in the log."
                    confirmLabel="Remove"
                    tone="danger"
                    loading={busy}
                    onClose={() => setRemoving(null)}
                    onConfirm={() => {
                        setBusy(true);
                        router.delete(route('leads.destroy', removing.id), { preserveScroll: true, onSuccess: () => setRemoving(null), onFinish: () => setBusy(false) });
                    }}
                />
            </PageShell>
        </AppShell>
    );
}
