import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Download, Filter, Paperclip, X } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, EmptyState, Field, Input, Pagination, Select, Spinner, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatDateTime, formatGap } from '@/Utils/format';

const STATUS_TONE = { sent: 'success', failed: 'danger', unknown: 'warning', pending: 'info' };

function Detail({ id, timezone, onClose }) {
    const [row, setRow] = useState(null);
    const [error, setError] = useState(null);

    useState(() => {
        window.axios.get(route('log.show', id)).then(({ data }) => setRow(data)).catch(() => setError('Could not load this entry.'));
    });

    return (
        <Modal show onClose={onClose} maxWidth="3xl">
            <div className="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-semibold text-body">{row?.subject || 'Email'}</h2>
                    {row && <p className="text-sm text-muted">To {row.to_name ? `${row.to_name} <${row.to_email}>` : row.to_email} · from {row.account} ({row.from_email})</p>}
                </div>
                <button type="button" onClick={onClose} className="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-body" aria-label="Close"><X className="h-5 w-5" /></button>
            </div>
            <div className="max-h-[70vh] overflow-y-auto p-6">
                {error && <p className="text-sm text-danger">{error}</p>}
                {!row && !error && <p className="flex items-center gap-2 text-sm text-subtle"><Spinner size={14} /> Loading…</p>}
                {row && (
                    <div className="space-y-4">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            {[
                                ['Status', <Badge tone={STATUS_TONE[row.status]} dot>{row.status_label}</Badge>],
                                ['Sent by', row.user],
                                ['Sent at', formatDateTime(row.sent_at || row.reserved_at, timezone)],
                                ['Gap since previous', formatGap(row.gap_seconds)],
                                ['Cc', row.cc?.length ? row.cc.join(', ') : '—'],
                                ['Bcc', row.bcc?.length ? row.bcc.join(', ') : '—'],
                                ['Message-ID', <span className="break-all font-mono text-xs">{row.message_id_header || '—'}</span>],
                                ['Provider id', <span className="break-all font-mono text-xs">{row.provider_message_id || '—'}</span>],
                                ['Duration', row.duration_ms != null ? `${row.duration_ms} ms` : '—'],
                                ['Campaign', row.campaign || '—'],
                            ].map(([k, v]) => (
                                <div key={k} className="flex gap-3"><dt className="w-32 shrink-0 text-subtle">{k}</dt><dd className="min-w-0 text-body">{v}</dd></div>
                            ))}
                        </dl>
                        {row.error && <div className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{row.error}</div>}
                        {row.attachments?.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {row.attachments.map((a) => (
                                    <a key={a.id} href={route('attachments.download', a.id)} className="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs text-body hover:bg-surface-2">
                                        <Paperclip className="h-3 w-3" /> {a.filename_as_sent || a.name}
                                    </a>
                                ))}
                            </div>
                        )}
                        <div className="overflow-hidden rounded-lg border border-line bg-white">
                            <iframe title="Email body" sandbox="" srcDoc={row.body_html || `<pre>${row.body_text || ''}</pre>`} className="h-[28rem] w-full" />
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}

export default function Index({ rows, filters, accounts, members, statuses, timezone }) {
    const [f, setF] = useState({ ...filters, account_id: filters.account_id || '', user_id: filters.user_id || '', status: filters.status || '', q: filters.q || '' });
    const [open, setOpen] = useState(null);

    const params = (obj) => Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v != null));
    const apply = (e) => {
        e?.preventDefault();
        router.get(route('log.index'), params(f), { preserveState: true, replace: true });
    };
    const exportUrl = route('log.export', params(f));

    return (
        <AppShell title="Send log">
            <PageShell>
                <PageHeader
                    title="Send log"
                    description="Every send attempt by anyone on the team, with the gap since the account's previous send."
                    actions={<Button href={exportUrl} variant="secondary" icon={<Download className="h-4 w-4" />}>Export CSV</Button>}
                />

                <Card padded={false}>
                    <form onSubmit={apply} className="grid gap-3 border-b border-line p-4 md:grid-cols-6">
                        <Field label="From" htmlFor="f-from"><Input id="f-from" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                        <Field label="To" htmlFor="f-to"><Input id="f-to" type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                        <Field label="Account" htmlFor="f-acc">
                            <Select id="f-acc" value={f.account_id} onChange={(e) => setF({ ...f, account_id: e.target.value })}>
                                <option value="">All</option>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="Member" htmlFor="f-user">
                            <Select id="f-user" value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
                                <option value="">All</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="Status" htmlFor="f-status">
                            <Select id="f-status" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
                                <option value="">All</option>
                                {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="Search" htmlFor="f-q">
                            <div className="flex gap-2">
                                <Input id="f-q" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder="to, name, subject" />
                                <Button type="submit" size="md" icon={<Filter className="h-4 w-4" />} aria-label="Apply filters" />
                            </div>
                        </Field>
                    </form>

                    {rows.data.length === 0 ? (
                        <EmptyState title="No sends match" description="Widen the date range or clear a filter." />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>When</Th>
                                    <Th>To</Th>
                                    <Th>Subject</Th>
                                    <Th>By</Th>
                                    <Th>Account</Th>
                                    <Th className="text-right">Gap</Th>
                                    <Th>Status</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.data.map((r) => (
                                    <Tr key={r.id} className="cursor-pointer" onClick={() => setOpen(r.id)}>
                                        <Td className="whitespace-nowrap text-muted">{formatDateTime(r.sent_at || r.reserved_at, timezone)}</Td>
                                        <Td>
                                            <div className="max-w-[240px] truncate text-body">{r.to_email}</div>
                                            {r.to_name && <div className="max-w-[240px] truncate text-xs text-subtle">{r.to_name}</div>}
                                        </Td>
                                        <Td className="max-w-[320px]">
                                            <div className="flex items-center gap-1.5">
                                                <span className="truncate text-muted">{r.subject}</span>
                                                {r.attachments_count > 0 && <Paperclip className="h-3.5 w-3.5 shrink-0 text-subtle" />}
                                            </div>
                                            {r.campaign && <div className="text-xs text-subtle">{r.campaign}</div>}
                                        </Td>
                                        <Td className="whitespace-nowrap">{r.user}</Td>
                                        <Td className="whitespace-nowrap text-muted">{r.account}</Td>
                                        <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatGap(r.gap_seconds)}</Td>
                                        <Td><Badge tone={STATUS_TONE[r.status] || 'neutral'} dot title={r.error || undefined}>{r.status_label}</Badge></Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <div className="border-t border-line px-4 py-3"><Pagination paginator={rows} /></div>
                </Card>

                {open && <Detail id={open} timezone={timezone} onClose={() => setOpen(null)} />}
            </PageShell>
        </AppShell>
    );
}
