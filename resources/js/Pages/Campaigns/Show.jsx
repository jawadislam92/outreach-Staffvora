import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Download, Pencil, Play, Square, Trash2, Upload, ListChecks, Sparkles } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, Pagination, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import { formatUsd } from '@/Utils/format';
import notify from '@/Utils/notify';
import { CampaignForm } from './Index';

const LEAD_TONE = { pending: 'neutral', generating: 'info', ready: 'accent', sent: 'success', skipped: 'neutral', failed: 'danger' };
const SOURCE_TONE = { ai: 'accent', csv: 'info', manual: 'neutral' };
const SEGMENTS = [['sent', 'var(--viz-2)'], ['ready', 'var(--viz-3)'], ['generating', 'var(--viz-1)'], ['pending', 'var(--border)'], ['failed', 'var(--viz-5)'], ['skipped', 'var(--text-subtle)']];

function ProgressBar({ counts, total }) {
    if (!total) return null;
    return (
        <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-surface-2">
            {SEGMENTS.map(([key, color]) => counts[key] > 0 && (
                <div key={key} title={`${key}: ${counts[key]}`} style={{ width: `${(counts[key] / total) * 100}%`, backgroundColor: color }} />
            ))}
        </div>
    );
}

function Generator({ campaign, progress, aiConfigured, aiModel }) {
    const [running, setRunning] = useState(false);
    const [live, setLive] = useState(progress);
    const [last, setLast] = useState(null);
    const [done, setDone] = useState(0);
    const runningRef = useRef(false);
    const timer = useRef();

    useEffect(() => setLive(progress), [progress]);
    useEffect(() => () => { runningRef.current = false; clearTimeout(timer.current); }, []);

    const stop = (reload = true) => {
        runningRef.current = false;
        setRunning(false);
        clearTimeout(timer.current);
        if (reload) router.reload({ only: ['leads', 'progress'] });
    };

    const step = async () => {
        if (!runningRef.current) return;
        try {
            const { data } = await window.axios.post(route('campaigns.generate', campaign.id), { batch: 2 });
            setLast(data);
            setLive(data.progress);
            setDone((d) => d + data.generated);
            if (data.stopped === 'rate_limited') {
                notify.warning('The AI provider is busy — pausing for 30 seconds.');
                timer.current = setTimeout(step, 30000);
                return;
            }
            if (data.stopped || data.progress.remaining <= 0) {
                if (data.stopped && data.last_error) notify.error(data.last_error);
                else notify.success('All drafts generated.');
                stop();
                return;
            }
            step();
        } catch (err) {
            notify.error(err?.response?.data?.message || 'Generation request failed.');
            stop();
        }
    };

    const start = () => {
        runningRef.current = true;
        setRunning(true);
        setDone(0);
        step();
    };

    const remaining = live.remaining;
    const estimate = remaining * 0.035;

    return (
        <Card title="Drafts" description={`AI personalises one email per lead (${aiModel}). Review everything before it goes out.`}
            actions={running
                ? <Button variant="danger-soft" icon={<Square className="h-4 w-4" />} onClick={() => stop()}>Stop</Button>
                : <Button icon={<Play className="h-4 w-4" />} disabled={!aiConfigured || remaining === 0} onClick={start} title={!aiConfigured ? 'Set ANTHROPIC_API_KEY on the server first' : undefined}>Generate {remaining > 0 ? `${remaining} draft${remaining === 1 ? '' : 's'}` : 'drafts'}</Button>}>
            <ProgressBar counts={live.counts} total={live.total} />
            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                {SEGMENTS.map(([key, color]) => (
                    <span key={key} className="inline-flex items-center gap-1.5 text-muted"><span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} /> {key}: <span className="tabular-nums text-body">{live.counts[key]}</span></span>
                ))}
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <div className="rounded-lg bg-surface-2/60 p-3 text-sm"><div className="text-xs uppercase tracking-wide text-subtle">AI spend so far</div><div className="mt-0.5 font-semibold tabular-nums text-body">{formatUsd(live.ai_cost_usd)}</div><div className="text-xs text-subtle">{live.ai_calls} calls · {live.ai_input_tokens + live.ai_output_tokens} tokens</div></div>
                <div className="rounded-lg bg-surface-2/60 p-3 text-sm"><div className="text-xs uppercase tracking-wide text-subtle">Estimated for the rest</div><div className="mt-0.5 font-semibold tabular-nums text-body">≈ {formatUsd(estimate)}</div><div className="text-xs text-subtle">{remaining} leads × ~3.5¢ on Opus 5</div></div>
                <div className="rounded-lg bg-surface-2/60 p-3 text-sm"><div className="text-xs uppercase tracking-wide text-subtle">Limit</div><div className="mt-0.5 font-semibold tabular-nums text-body">{live.ai_calls} / {live.max_generations}</div><div className="text-xs text-subtle">generations allowed for this campaign</div></div>
            </div>
            {running && <p className="mt-3 text-sm text-accent">Generating… {done} done this run. You can close this tab; progress is saved per lead and “Generate” resumes.</p>}
            {last?.last_error && !running && <p className="mt-3 flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />{last.last_error}</p>}
        </Card>
    );
}

export default function Show({ campaign, leads, filters, progress, statuses, templates, ai_configured, ai_model, timezone, can_manage }) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [removing, setRemoving] = useState(null);
    const [f, setF] = useState({ status: filters.status || '', q: filters.q || '' });
    const form = useForm({});

    const apply = (next) => {
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== ''));
        router.get(route('campaigns.show', campaign.id), params, { preserveState: true, replace: true, only: ['leads', 'filters'] });
    };

    const save = (data) => {
        form.transform(() => data);
        form.patch(route('campaigns.update', campaign.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const readyCount = progress.counts.ready;

    return (
        <AppShell title={campaign.name}>
            <PageShell>
                <PageHeader
                    eyebrow="Campaign"
                    title={campaign.name}
                    description={campaign.brief_goal || 'No goal written yet — edit the brief so the AI knows what to ask for.'}
                    actions={
                        <>
                            <Button variant="secondary" icon={<Upload className="h-4 w-4" />} to={route('campaigns.import.create', campaign.id)}>Import leads</Button>
                            <Button variant="secondary" icon={<Download className="h-4 w-4" />} href={route('campaigns.export', campaign.id)}>Export CSV</Button>
                            {can_manage && <Button variant="ghost" icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(true)}>Edit brief</Button>}
                            <Button icon={<ListChecks className="h-4 w-4" />} to={route('campaigns.queue', campaign.id)} disabled={readyCount === 0}>Review & send {readyCount > 0 ? `(${readyCount})` : ''}</Button>
                        </>
                    }
                />

                <Generator campaign={campaign} progress={progress} aiConfigured={ai_configured} aiModel={ai_model} />

                <Card padded={false} title="Leads" description={`${progress.total} in this campaign.`} actions={
                    <div className="flex items-center gap-2">
                        <Select value={f.status} onChange={(e) => { const next = { ...f, status: e.target.value }; setF(next); apply(next); }} className="w-40">
                            <option value="">All statuses</option>
                            {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                        </Select>
                        <Input value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply(f)} placeholder="Search email, name, company" className="w-56" />
                    </div>
                }>
                    {leads.data.length === 0 ? (
                        <EmptyState title={progress.total === 0 ? 'No leads yet' : 'No leads match'} description={progress.total === 0 ? 'Import a CSV to get started.' : 'Try a different filter.'} action={progress.total === 0 ? <Button to={route('campaigns.import.create', campaign.id)}>Import leads</Button> : null} />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Lead</Th>
                                    <Th>Company</Th>
                                    <Th>Status</Th>
                                    <Th>Draft</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {leads.data.map((l) => (
                                    <Tr key={l.id}>
                                        <Td>
                                            <div className="font-medium text-body">{l.name || <span className="text-subtle">—</span>}</div>
                                            <div className="text-xs text-subtle">{l.email}{l.previously_contacted_count > 0 && <Badge tone="warning" className="ml-2">contacted {l.previously_contacted_count}×</Badge>}</div>
                                        </Td>
                                        <Td className="text-muted">{l.company || '—'}</Td>
                                        <Td>
                                            <Badge tone={LEAD_TONE[l.status]} dot>{l.status_label}</Badge>
                                            {l.generation_error && <div className="mt-1 max-w-xs truncate text-xs text-danger" title={l.generation_error}>{l.generation_error}</div>}
                                        </Td>
                                        <Td className="max-w-[320px]">
                                            {l.draft ? (
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate text-body">{l.draft.subject}</span>
                                                    <Badge tone={SOURCE_TONE[l.draft.source]}>{l.draft.source}{l.draft.generation_count > 1 ? ` ×${l.draft.generation_count}` : ''}</Badge>
                                                </div>
                                            ) : <span className="text-subtle">—</span>}
                                        </Td>
                                        <Td className="text-right">
                                            <div className="inline-flex gap-1">
                                                {l.status !== 'sent' && <Button size="xs" variant="secondary" to={route('campaigns.queue', { campaign: campaign.id, lead: l.id })}>{l.draft ? 'Review' : 'Write'}</Button>}
                                                {l.status !== 'sent' && <Button size="xs" variant="ghost" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={() => setRemoving(l)} aria-label="Remove lead" />}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                    <div className="border-t border-line px-4 py-3"><Pagination paginator={leads} /></div>
                </Card>

                {can_manage && (
                    <div className="flex justify-end">
                        <Button variant="ghost" className="text-danger" icon={<Trash2 className="h-4 w-4" />} onClick={() => setDeleting(true)}>Delete campaign</Button>
                    </div>
                )}

                <Modal show={editing} onClose={() => setEditing(false)} maxWidth="2xl">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">Edit campaign</h2>
                        <div className="mt-5">
                            <CampaignForm initial={{ name: campaign.name, template_id: campaign.template_id, brief_offer: campaign.brief_offer || '', brief_goal: campaign.brief_goal || '', brief_tone: campaign.brief_tone || '', extra_instructions: campaign.extra_instructions || '', base_subject: campaign.base_subject || '', base_body_md: campaign.base_body_md || '', max_generations: campaign.max_generations }} templates={templates} submitLabel="Save" onSubmit={save} processing={form.processing} errors={form.errors} />
                        </div>
                    </div>
                </Modal>

                <ConfirmDialog open={deleting} title={`Delete “${campaign.name}”?`} description="Its leads and drafts are removed. Emails already sent stay in the log." confirmLabel="Delete campaign" tone="danger" onClose={() => setDeleting(false)} onConfirm={() => router.delete(route('campaigns.destroy', campaign.id))} />

                <ConfirmDialog open={!!removing} title={`Remove ${removing?.email}?`} description="The lead and its draft are deleted from this campaign." confirmLabel="Remove" tone="danger" onClose={() => setRemoving(null)} onConfirm={() => router.delete(route('leads.destroy', removing.id), { preserveScroll: true, onSuccess: () => setRemoving(null) })} />
            </PageShell>
        </AppShell>
    );
}
