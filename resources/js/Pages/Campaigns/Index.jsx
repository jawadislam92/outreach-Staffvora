import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ListChecks, Plus, Sparkles } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, EmptyState, Field, Input, Select, Table, TBody, Td, Th, THead, Textarea, Tr } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

const STATUS_TONE = { active: 'success', done: 'neutral', archived: 'neutral' };

export function CampaignForm({ initial, templates, submitLabel, onSubmit, processing, errors }) {
    const [data, set] = useState(initial);
    const setData = (k, v) => set((d) => ({ ...d, [k]: v }));
    const template = templates.find((t) => String(t.id) === String(data.template_id));

    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(data); }} className="space-y-5">
            <Field label="Campaign name" htmlFor="c-name" required error={errors.name}>
                <Input id="c-name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Shopify agencies — September" required />
            </Field>

            <div className="rounded-xl border border-line bg-surface-2/40 p-4">
                <div className="mb-3 flex items-center gap-2 text-sm font-medium text-body"><Sparkles className="h-4 w-4 text-accent" /> Brief for the AI drafts</div>
                <div className="grid gap-4">
                    <Field label="What we offer" htmlFor="c-offer" error={errors.brief_offer} hint="Who you are, what you do, proof points. The model may only use facts written here.">
                        <Textarea id="c-offer" rows={3} value={data.brief_offer || ''} onChange={(e) => setData('brief_offer', e.target.value)} placeholder="Sparking Asia builds automation and dashboards for agencies. Clients include … We typically save 10+ hours a week of manual reporting." />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Goal of the email (the ask)" htmlFor="c-goal" error={errors.brief_goal}>
                            <Input id="c-goal" value={data.brief_goal || ''} onChange={(e) => setData('brief_goal', e.target.value)} placeholder="Book a 15-minute call next week" />
                        </Field>
                        <Field label="Tone" htmlFor="c-tone" error={errors.brief_tone}>
                            <Input id="c-tone" value={data.brief_tone || ''} onChange={(e) => setData('brief_tone', e.target.value)} placeholder="Friendly, direct, no hype" />
                        </Field>
                    </div>
                    <Field label="Extra instructions (optional)" htmlFor="c-extra" error={errors.extra_instructions}>
                        <Textarea id="c-extra" rows={2} value={data.extra_instructions || ''} onChange={(e) => setData('extra_instructions', e.target.value)} placeholder="Mention their website if the CSV has one. Never mention pricing." />
                    </Field>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Base template" htmlFor="c-template" error={errors.template_id} hint="Starting point the AI personalises. Optional.">
                    <Select id="c-template" value={data.template_id || ''} onChange={(e) => setData('template_id', e.target.value ? Number(e.target.value) : null)}>
                        <option value="">No template — write from the brief</option>
                        {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </Select>
                </Field>
                <Field label="Generation limit" htmlFor="c-max" error={errors.max_generations} hint="Hard cap on AI drafts for this campaign (cost guard).">
                    <Input id="c-max" type="number" min="1" max="20000" value={data.max_generations ?? 500} onChange={(e) => setData('max_generations', Number(e.target.value))} />
                </Field>
            </div>
            {template && (
                <div className="rounded-lg border border-line bg-surface p-3 text-xs text-muted">
                    <div className="font-medium text-body">{template.subject}</div>
                    <pre className="mt-1 whitespace-pre-wrap font-sans">{template.body_md}</pre>
                </div>
            )}

            <details className="rounded-lg border border-line p-3">
                <summary className="cursor-pointer text-sm font-medium text-body">Override subject / body for this campaign</summary>
                <div className="mt-3 grid gap-3">
                    <Field label="Subject" htmlFor="c-subject" error={errors.base_subject}>
                        <Input id="c-subject" value={data.base_subject || ''} onChange={(e) => setData('base_subject', e.target.value)} />
                    </Field>
                    <Field label="Body (Markdown)" htmlFor="c-body" error={errors.base_body_md}>
                        <Textarea id="c-body" rows={5} value={data.base_body_md || ''} onChange={(e) => setData('base_body_md', e.target.value)} className="font-mono text-[13px]" />
                    </Field>
                </div>
            </details>

            <div className="flex justify-end">
                <Button type="submit" loading={processing}>{submitLabel}</Button>
            </div>
        </form>
    );
}

export default function Index({ campaigns, templates, timezone, ai_configured }) {
    const [creating, setCreating] = useState(false);
    const form = useForm({});

    const create = (data) => {
        form.transform(() => data);
        form.post(route('campaigns.store'), { preserveScroll: true });
    };

    return (
        <AppShell title="Campaigns">
            <PageShell>
                <PageHeader
                    title="Campaigns"
                    description="Import a lead list, let the AI draft a personal email for each person, review, then send."
                    actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>New campaign</Button>}
                />

                {!ai_configured && (
                    <div className="rounded-xl border border-line bg-surface-2/50 px-4 py-3 text-sm text-muted">
                        AI drafting is off until <code className="rounded bg-surface px-1">ANTHROPIC_API_KEY</code> is set on the server. You can still import CSVs that already contain <code className="rounded bg-surface px-1">subject</code> and <code className="rounded bg-surface px-1">body</code> columns, and write drafts by hand.
                    </div>
                )}

                <Card padded={false}>
                    {campaigns.length === 0 ? (
                        <EmptyState icon={<ListChecks className="h-5 w-5" />} title="No campaigns yet" description="Create one, import a CSV of leads, and generate drafts." action={<Button onClick={() => setCreating(true)}>New campaign</Button>} />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Campaign</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Leads</Th>
                                    <Th className="text-right">Needs draft</Th>
                                    <Th className="text-right">Ready</Th>
                                    <Th className="text-right">Sent</Th>
                                    <Th className="text-right">Failed</Th>
                                    <Th>Updated</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {campaigns.map((c) => (
                                    <Tr key={c.id}>
                                        <Td>
                                            <Link href={route('campaigns.show', c.id)} className="font-medium text-body hover:text-accent">{c.name}</Link>
                                            <div className="text-xs text-subtle">by {c.creator || '—'}</div>
                                        </Td>
                                        <Td><Badge tone={STATUS_TONE[c.status]} dot>{c.status_label}</Badge></Td>
                                        <Td className="text-right tabular-nums">{c.leads_count}</Td>
                                        <Td className="text-right tabular-nums text-muted">{c.pending_count}</Td>
                                        <Td className="text-right tabular-nums text-accent">{c.ready_count}</Td>
                                        <Td className="text-right tabular-nums text-success">{c.sent_count}</Td>
                                        <Td className={`text-right tabular-nums ${c.failed_count ? 'text-danger' : 'text-subtle'}`}>{c.failed_count}</Td>
                                        <Td className="whitespace-nowrap text-muted">{formatDateTime(c.updated_at, timezone)}</Td>
                                        <Td className="text-right">
                                            <div className="inline-flex gap-1">
                                                <Button size="xs" variant="ghost" to={route('campaigns.show', c.id)}>Open</Button>
                                                {c.ready_count > 0 && <Button size="xs" variant="secondary" to={route('campaigns.queue', c.id)}>Review {c.ready_count}</Button>}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <Modal show={creating} onClose={() => setCreating(false)} maxWidth="2xl">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">New campaign</h2>
                        <p className="mt-1 text-sm text-muted">The brief tells the AI what you offer and what to ask for. You import the leads on the next screen.</p>
                        <div className="mt-5">
                            <CampaignForm initial={{ name: '', template_id: null, brief_offer: '', brief_goal: '', brief_tone: '', extra_instructions: '', base_subject: '', base_body_md: '', max_generations: 500 }} templates={templates} submitLabel="Create & import leads" onSubmit={create} processing={form.processing} errors={form.errors} />
                        </div>
                    </div>
                </Modal>
            </PageShell>
        </AppShell>
    );
}
