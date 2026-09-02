import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { AlertOctagon, BarChart3, Clock, Download, Filter, LayoutGrid, Mail, Send, Timer, Users } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Button, Card, EmptyState, Field, Input, KpiCard, Segmented, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { cssVar, textTokens, useThemeName, vizColor, withAlpha } from '@/Components/charts/theme';
import { formatDate, formatGap } from '@/Utils/format';

// Chart.js is tree-shaken: register what this page's charts need exactly
// once, when the page module loads.
ChartJS.register(CategoryScale, LinearScale, BarElement, PointElement, LineElement, Tooltip, Legend);

// ---- Y-m-d helpers (UTC arithmetic so the business date never shifts) ----
const parseYmd = (s) => {
    const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
};
const toYmd = (date) => date.toISOString().slice(0, 10);
const shiftDays = (s, n) => {
    const d = parseYmd(s);
    d.setUTCDate(d.getUTCDate() + n);
    return toYmd(d);
};
const shortDate = (s) => {
    try {
        return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', day: '2-digit', month: 'short' }).format(parseYmd(s));
    } catch {
        return s;
    }
};
const hourLabel = (h) => `${String(h % 24).padStart(2, '0')}:00`;

const presetsFor = (today) => [
    { key: 'today', label: 'Today', from: today, to: today },
    { key: '7d', label: 'Last 7 days', from: shiftDays(today, -6), to: today },
    { key: '30d', label: 'Last 30 days', from: shiftDays(today, -29), to: today },
    { key: 'month', label: 'This month', from: `${String(today).slice(0, 7)}-01`, to: today },
];

const params = (obj) => Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== '' && v != null));

// Solid equivalent of the translucent header/zebra tint, for sticky cells
// that must hide whatever scrolls underneath them.
const ZEBRA_SOLID = 'color-mix(in srgb, var(--surface-2) 40%, var(--surface))';

/** Chart colours re-read from the CSS custom properties whenever data-theme flips (canvas can't follow var()). */
function useChartTheme() {
    const theme = useThemeName();
    return useMemo(() => ({
        theme,
        text: textTokens(),
        surface: cssVar('--surface', '#ffffff'),
        sent: vizColor(1),
        hours: vizColor(3),
        unknown: vizColor(4),
        failed: vizColor(5),
    }), [theme]);
}

const chartOptions = (t, { stacked = false, legend = false, title } = {}) => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 250 },
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            display: legend,
            position: 'top',
            align: 'end',
            labels: { color: t.text.muted, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16 },
        },
        tooltip: {
            backgroundColor: t.surface,
            titleColor: t.text.body,
            bodyColor: t.text.muted,
            borderColor: t.text.line,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            usePointStyle: true,
            callbacks: title ? { title } : {},
        },
    },
    scales: {
        x: { stacked, grid: { display: false }, border: { color: t.text.line }, ticks: { color: t.text.subtle, maxRotation: 0, autoSkip: true, maxTicksLimit: 16 } },
        y: { stacked, beginAtZero: true, grid: { color: withAlpha(t.text.line, 0.7) }, border: { display: false }, ticks: { color: t.text.subtle, precision: 0 } },
    },
});

const BAR = { borderRadius: 3, maxBarThickness: 28 };

function TimelineChart({ timeline }) {
    const t = useChartTheme();
    const data = useMemo(() => ({
        labels: timeline.map((d) => shortDate(d.date)),
        datasets: [
            { label: 'Sent', data: timeline.map((d) => d.sent), backgroundColor: t.sent, ...BAR },
            { label: 'Failed', data: timeline.map((d) => d.failed), backgroundColor: t.failed, ...BAR },
            { label: 'Unknown', data: timeline.map((d) => d.unknown), backgroundColor: t.unknown, ...BAR },
        ],
    }), [timeline, t]);
    const options = useMemo(
        () => chartOptions(t, { stacked: true, legend: true, title: (items) => formatDate(timeline[items[0]?.dataIndex]?.date) }),
        [timeline, t],
    );
    return <div className="relative h-72"><Bar data={data} options={options} /></div>;
}

function HourChart({ hours }) {
    const t = useChartTheme();
    const data = useMemo(() => ({
        labels: hours.map((_, h) => hourLabel(h)),
        datasets: [{ label: 'Sent', data: hours, backgroundColor: t.hours, ...BAR }],
    }), [hours, t]);
    const options = useMemo(
        () => chartOptions(t, { title: (items) => `${hourLabel(items[0]?.dataIndex ?? 0)} – ${hourLabel((items[0]?.dataIndex ?? 0) + 1)}` }),
        [t],
    );
    return <div className="relative h-72"><Bar data={data} options={options} /></div>;
}

function UtilBar({ pct, cap, days }) {
    if (pct == null) return <span className="text-xs text-subtle">No cap</span>;
    const width = Math.min(100, Math.max(0, pct));
    return (
        <div className="flex items-center gap-2" title={`${cap} per day × ${days} days`}>
            <div className="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-2">
                <div className={`h-full rounded-full transition-all ${pct >= 100 ? 'bg-danger' : 'bg-accent'}`} style={{ width: `${width}%` }} />
            </div>
            <span className="text-xs tabular-nums text-muted">{pct}%</span>
        </div>
    );
}

function PivotTable({ pivot }) {
    const { accounts, members, cells } = pivot;
    const cell = (a, m) => cells?.[`${a.id}:${m.id}`] ?? 0;
    const accountTotals = accounts.map((a) => members.reduce((sum, m) => sum + cell(a, m), 0));
    const grand = accountTotals.reduce((sum, n) => sum + n, 0);
    const sticky = 'sticky left-0 z-10 whitespace-nowrap shadow-[inset_-1px_0_0_var(--border)]';

    return (
        <Table>
            <THead>
                <Tr>
                    <Th className={sticky} style={{ backgroundColor: ZEBRA_SOLID }}>Member</Th>
                    {accounts.map((a) => <Th key={a.id} className="text-right">{a.label}</Th>)}
                    <Th className="text-right">Total</Th>
                </Tr>
            </THead>
            <TBody>
                {members.map((m, i) => {
                    const counts = accounts.map((a) => cell(a, m));
                    const total = counts.reduce((sum, n) => sum + n, 0);
                    const zebra = i % 2 === 1;
                    return (
                        <Tr key={m.id} className={zebra ? 'bg-surface-2/40' : undefined}>
                            <Td className={`${sticky} font-medium`} style={{ backgroundColor: zebra ? ZEBRA_SOLID : 'var(--surface)' }}>{m.name}</Td>
                            {counts.map((n, j) => <Td key={accounts[j].id} className={`text-right tabular-nums ${n ? '' : 'text-subtle'}`}>{n}</Td>)}
                            <Td className="text-right font-medium tabular-nums">{total}</Td>
                        </Tr>
                    );
                })}
                <Tr className="bg-surface-2/40 font-medium">
                    <Td className={sticky} style={{ backgroundColor: ZEBRA_SOLID }}>Total</Td>
                    {accountTotals.map((n, j) => <Td key={accounts[j].id} className="text-right tabular-nums">{n}</Td>)}
                    <Td className="text-right tabular-nums">{grand}</Td>
                </Tr>
            </TBody>
        </Table>
    );
}

export default function Index({ filters, totals, by_account, by_member, pivot, timeline, hour_of_day, accounts, members, timezone, today }) {
    const errors = usePage().props.errors || {};
    const [f, setF] = useState({ from: filters.from, to: filters.to, account_id: filters.account_id || '', user_id: filters.user_id || '' });

    const presets = useMemo(() => presetsFor(today), [today]);
    const activePreset = presets.find((p) => p.from === f.from && p.to === f.to)?.key || 'custom';

    const visit = (next) => router.get(route('reports.index'), params(next), { preserveState: true, replace: true });
    const apply = (e) => {
        e?.preventDefault();
        visit(f);
    };
    const applyPreset = (key) => {
        const preset = presets.find((p) => p.key === key);
        if (!preset) return;
        const next = { ...f, from: preset.from, to: preset.to };
        setF(next);
        visit(next);
    };
    const exportUrl = route('reports.export', params(f));

    const attempts = totals.sent + totals.failed + totals.unknown + totals.pending;
    const rangeLabel = `${formatDate(filters.from)} – ${formatDate(filters.to)}`;
    const spark = timeline.map((d) => d.sent);
    const noData = <EmptyState icon={<BarChart3 className="h-5 w-5" />} title="No activity in this range" description="Widen the date range or clear a filter." />;

    return (
        <AppShell title="Reports">
            <PageShell>
                <PageHeader
                    eyebrow="Reports"
                    title="Reports"
                    description={`Sending activity by day, mailbox and member · ${rangeLabel} (${timezone}).`}
                />

                <Card padded={false}>
                    <form onSubmit={apply} className="flex flex-col gap-4 p-4 2xl:flex-row 2xl:items-end">
                        <div className="shrink-0">
                            <span className="mb-1.5 block text-sm font-medium text-body">Range</span>
                            <Segmented size="sm" value={activePreset} onChange={applyPreset} aria-label="Date range presets" tabs={presets.map(({ key, label }) => ({ key, label }))} />
                        </div>
                        <div className="grid flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Field label="From" htmlFor="r-from" error={errors.from}>
                                <Input id="r-from" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                            </Field>
                            <Field label="To" htmlFor="r-to" error={errors.to}>
                                <Input id="r-to" type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                            </Field>
                            <Field label="Account" htmlFor="r-acc">
                                <Select id="r-acc" value={f.account_id} onChange={(e) => setF({ ...f, account_id: e.target.value })}>
                                    <option value="">All accounts</option>
                                    {accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                                </Select>
                            </Field>
                            <Field label="Member" htmlFor="r-user">
                                <Select id="r-user" value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}>
                                    <option value="">All members</option>
                                    {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                </Select>
                            </Field>
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <Button type="submit" icon={<Filter className="h-4 w-4" />}>Apply</Button>
                            <Button href={exportUrl} variant="secondary" icon={<Download className="h-4 w-4" />}>Export CSV</Button>
                        </div>
                    </form>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <KpiCard accent={1} icon={<Send className="h-5 w-5" />} label="Sent" value={totals.sent} sub={`${totals.days_active} of ${timeline.length} days active`} spark={spark} />
                    <KpiCard accent={5} icon={<AlertOctagon className="h-5 w-5" />} label="Failed + unknown" value={totals.failed + totals.unknown} sub={`${totals.failed} failed · ${totals.unknown} unknown${totals.pending ? ` · ${totals.pending} sending` : ''}`} />
                    <KpiCard accent={3} icon={<Users className="h-5 w-5" />} label="Active senders" value={totals.senders} sub="Members with at least one send" />
                    <KpiCard
                        accent={2}
                        icon={<Timer className="h-5 w-5" />}
                        label="Average gap"
                        value={totals.avg_gap == null ? '—' : formatGap(totals.avg_gap)}
                        sub={totals.gap_samples ? `Median ${formatGap(totals.median_gap)} · Min ${formatGap(totals.min_gap)} · ${totals.gap_samples} paced sends` : 'No paced sends in range'}
                    />
                </div>

                <div className="grid gap-6 xl:grid-cols-3">
                    <Card className="xl:col-span-2" title="Daily activity" description="Sent, failed and unknown attempts per business day.">
                        {attempts === 0 ? noData : <TimelineChart timeline={timeline} />}
                    </Card>
                    <Card title="Hour of day" description={`Successful sends by local hour (${timezone}).`}>
                        {totals.sent === 0 ? <EmptyState icon={<Clock className="h-5 w-5" />} title="No sends in this range" /> : <HourChart hours={hour_of_day} />}
                    </Card>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card padded={false} title="By account" description="Volume, pacing and daily-cap utilisation per mailbox.">
                        {by_account.length === 0 ? (
                            <EmptyState icon={<Mail className="h-5 w-5" />} title="No account activity" />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Account</Th>
                                        <Th className="text-right">Sent</Th>
                                        <Th className="text-right">Failed</Th>
                                        <Th className="text-right">Unknown</Th>
                                        <Th className="text-right">Avg gap</Th>
                                        <Th className="text-right">Min gap</Th>
                                        <Th>Utilisation</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {by_account.map((a) => (
                                        <Tr key={a.account_id}>
                                            <Td>
                                                <div className="max-w-[220px] truncate font-medium text-body">{a.label}</div>
                                                <div className="max-w-[220px] truncate text-xs text-subtle">{a.from_email}</div>
                                            </Td>
                                            <Td className="text-right tabular-nums">{a.sent}</Td>
                                            <Td className={`text-right tabular-nums ${a.failed ? 'text-danger' : 'text-subtle'}`}>{a.failed}</Td>
                                            <Td className={`text-right tabular-nums ${a.unknown ? 'text-warning' : 'text-subtle'}`}>{a.unknown}</Td>
                                            <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatGap(a.avg_gap)}</Td>
                                            <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatGap(a.min_gap)}</Td>
                                            <Td><UtilBar pct={a.utilization_pct} cap={a.cap} days={a.days} /></Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>

                    <Card padded={false} title="By member" description="Who sent how much, and how they paced.">
                        {by_member.length === 0 ? (
                            <EmptyState icon={<Users className="h-5 w-5" />} title="No member activity" />
                        ) : (
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Member</Th>
                                        <Th className="text-right">Sent</Th>
                                        <Th className="text-right">Failed</Th>
                                        <Th className="text-right">Avg gap</Th>
                                        <Th className="text-right">Accounts used</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {by_member.map((m) => (
                                        <Tr key={m.user_id}>
                                            <Td className="font-medium">{m.name}</Td>
                                            <Td className="text-right tabular-nums">{m.sent}</Td>
                                            <Td className={`text-right tabular-nums ${m.failed ? 'text-danger' : 'text-subtle'}`}>{m.failed}</Td>
                                            <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatGap(m.avg_gap)}</Td>
                                            <Td className="text-right tabular-nums">{m.accounts_used}</Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        )}
                    </Card>
                </div>

                <Card padded={false} title="Member × account" description="Successful sends per member from each mailbox, for the whole team in this date range (the account and member filters do not apply here).">
                    {pivot.members.length === 0 ? (
                        <EmptyState icon={<LayoutGrid className="h-5 w-5" />} title="Nothing sent in this range" />
                    ) : (
                        <PivotTable pivot={pivot} />
                    )}
                </Card>
            </PageShell>
        </AppShell>
    );
}
