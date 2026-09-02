import { Link, usePage } from '@inertiajs/react';
import { AlertOctagon, Gauge, Mail, Send, Timer, Users } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Button, Card, EmptyState, KpiCard, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatDate, formatGap, formatTime } from '@/Utils/format';

const has = (name) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};

const STATUS_TONE = { sent: 'success', failed: 'danger', unknown: 'warning', pending: 'info' };

function AccountCard({ row }) {
    const pct = row.cap > 0 ? Math.min(100, Math.round((row.used / row.cap) * 100)) : 0;
    const full = row.cap > 0 && row.remaining === 0;
    return (
        <div className="rounded-xl border border-line bg-surface p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="truncate font-medium text-body">{row.account.label}</div>
                    <div className="truncate text-xs text-subtle">{row.account.from_email}</div>
                </div>
                <Badge tone={row.account.status === 'active' ? 'success' : 'warning'} dot>{row.account.status_label}</Badge>
            </div>
            <div className="mt-3 flex items-baseline gap-2">
                <span className="text-2xl font-semibold tabular-nums text-body">{row.used}</span>
                <span className="text-sm text-muted">of {row.cap} today</span>
                <span className={`ml-auto text-xs font-medium ${full ? 'text-danger' : 'text-muted'}`}>{full ? 'Cap reached' : `${row.remaining} left`}</span>
            </div>
            <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-2">
                <div className={`h-full rounded-full transition-all ${full ? 'bg-danger' : 'bg-accent'}`} style={{ width: `${pct}%` }} />
            </div>
            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-subtle">
                <span>{row.sent} sent</span>
                {row.failed > 0 && <span className="text-danger">{row.failed} failed</span>}
                {row.unknown > 0 && <span className="text-warning">{row.unknown} unknown</span>}
                {row.pending > 0 && <span className="text-info">{row.pending} sending</span>}
            </div>
        </div>
    );
}

export default function Dashboard({ today, timezone, accounts, totals, members, series, recent, my_sent_today }) {
    const user = usePage().props.auth.user;
    const spark = series.map((d) => d.sent);
    const yesterday = series.length > 1 ? series[series.length - 2].sent : 0;
    const delta = yesterday > 0
        ? { pct: Math.round(((totals.sent - yesterday) / yesterday) * 100), direction: totals.sent >= yesterday ? 'up' : 'down', good: null, label: 'vs yesterday' }
        : null;

    return (
        <AppShell title="Dashboard">
            <PageShell>
                <PageHeader
                    eyebrow={formatDate(today)}
                    title={`Hi ${user.name.split(' ')[0]}`}
                    description={`Team activity for today (${timezone}).`}
                    actions={has('compose.create') ? <Button to={route('compose.create')} icon={<Send className="h-4 w-4" />}>Compose</Button> : null}
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard accent={1} icon={<Send className="h-5 w-5" />} label="Sent today" value={totals.sent} sub="Team total" delta={delta} spark={spark} />
                    <KpiCard accent={3} icon={<Users className="h-5 w-5" />} label="Active senders" value={totals.senders} sub={`You: ${my_sent_today} sent today`} />
                    <KpiCard accent={2} icon={<Timer className="h-5 w-5" />} label="Average gap" value={totals.avg_gap == null ? '—' : formatGap(totals.avg_gap)} sub="Between sends, same account" />
                    <KpiCard accent={totals.failed + totals.unknown > 0 ? 4 : 5} icon={<AlertOctagon className="h-5 w-5" />} label="Needs attention" value={totals.failed + totals.unknown} sub={`${totals.failed} failed · ${totals.unknown} unknown`} />
                </div>

                <Card title="Sending accounts" description="Daily cap usage per connected mailbox." actions={user.is_admin && has('admin.accounts.index') ? <Button variant="secondary" size="sm" to={route('admin.accounts.index')}>Manage</Button> : null}>
                    {accounts.length === 0 ? (
                        <EmptyState icon={<Mail className="h-5 w-5" />} title="No sending accounts yet" description={user.is_admin ? 'Connect a Gmail or SMTP mailbox to start sending.' : 'Ask an admin to give you access to a sending account.'} />
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                            {accounts.map((row) => <AccountCard key={row.account.id} row={row} />)}
                        </div>
                    )}
                </Card>

                <div className="grid gap-6 xl:grid-cols-3">
                    <Card className="xl:col-span-2" padded={false} title="Recent activity" description="Latest sends across the team." actions={has('log.index') ? <Button variant="ghost" size="sm" to={route('log.index')}>Full log</Button> : null}>
                        {recent.length === 0 ? (
                            <EmptyState title="Nothing sent yet" description="Sends will show up here with who sent them, from which account, and the gap since the previous one." />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Time</Th>
                                        <Th>To</Th>
                                        <Th>Subject</Th>
                                        <Th>By</Th>
                                        <Th>Account</Th>
                                        <Th className="text-right">Gap</Th>
                                        <Th>Status</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {recent.map((r) => (
                                        <Tr key={r.id}>
                                            <Td className="whitespace-nowrap tabular-nums text-muted">{formatTime(r.sent_at || r.reserved_at, timezone)}</Td>
                                            <Td className="max-w-[220px] truncate">{r.to_email}</Td>
                                            <Td className="max-w-[260px] truncate text-muted">{r.subject}</Td>
                                            <Td className="whitespace-nowrap">{r.user}</Td>
                                            <Td className="whitespace-nowrap text-muted">{r.account}</Td>
                                            <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatGap(r.gap_seconds)}</Td>
                                            <Td><Badge tone={STATUS_TONE[r.status] || 'neutral'} dot title={r.error || undefined}>{r.status_label}</Badge></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card padded={false} title="By member" description="Sent today.">
                        {members.length === 0 ? (
                            <EmptyState icon={<Gauge className="h-5 w-5" />} title="No sends today" />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Member</Th>
                                        <Th className="text-right">Sent</Th>
                                        <Th className="text-right">Failed</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {members.map((m) => (
                                        <Tr key={m.id}>
                                            <Td>{m.name}{m.daily_cap != null && <span className="ml-1 text-xs text-subtle">/ {m.daily_cap}</span>}</Td>
                                            <Td className="text-right tabular-nums">{m.sent}</Td>
                                            <Td className={`text-right tabular-nums ${m.failed ? 'text-danger' : 'text-subtle'}`}>{m.failed}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>
            </PageShell>
        </AppShell>
    );
}
