import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, RefreshCw, Save, Send, SkipForward } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import AccountPicker from '@/Components/compose/AccountPicker';
import AttachmentsField from '@/Components/compose/AttachmentsField';
import BodyEditor from '@/Components/compose/BodyEditor';
import RecipientStatus from '@/Components/compose/RecipientStatus';
import ScheduleModal from '@/Components/ScheduleModal';
import { Badge, Button, Card, EmptyState, Field, Input } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';
import notify from '@/Utils/notify';

const cx = (...a) => a.filter(Boolean).join(' ');
const newToken = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
const SOURCE_TONE = { ai: 'accent', csv: 'info', manual: 'neutral' };

export default function Queue({ campaign, queue, current, accounts, library, progress, settings }) {
    const { errors, flash } = usePage().props;
    const firstUsable = accounts.find((r) => r.account.status === 'active' && r.remaining > 0);
    const [accountId, setAccountId] = useState(firstUsable?.account.id ?? '');
    const [subject, setSubject] = useState(current?.draft_full?.subject || '');
    const [body, setBody] = useState(current?.draft_full?.body_md || '');
    const [ack, setAck] = useState(false);
    const [uploaded, setUploaded] = useState([]);
    const [selectedLibrary, setSelectedLibrary] = useState([]);
    const [token, setToken] = useState(newToken());
    const [busy, setBusy] = useState(null);
    const [scheduling, setScheduling] = useState(false);

    useEffect(() => {
        setSubject(current?.draft_full?.subject || '');
        setBody(current?.draft_full?.body_md || '');
        setAck(false);
        setUploaded([]);
        setSelectedLibrary([]);
        setToken(newToken());
        setScheduling(false);
    }, [current?.id, current?.draft_full?.updated_at]);

    useEffect(() => {
        if (flash?.error || Object.keys(errors || {}).length) setToken(newToken());
    }, [flash, errors]);

    const selectedRow = accounts.find((r) => r.account.id === Number(accountId));
    const dirty = current && (subject !== (current.draft_full?.subject || '') || body !== (current.draft_full?.body_md || ''));

    const saveDraft = async () => {
        if (!current) return;
        setBusy('save');
        try {
            await window.axios.patch(route('leads.draft', current.id), { subject, body_md: body });
            notify.success('Draft saved.');
            router.reload({ only: ['current', 'queue', 'progress'] });
        } catch (err) {
            notify.error(err?.response?.data?.message || 'Could not save the draft.');
        } finally {
            setBusy(null);
        }
    };

    const send = () => {
        setBusy('send');
        router.post(route('leads.send', current.id), {
            client_token: token,
            email_account_id: accountId,
            subject,
            body_md: body,
            attachment_ids: [...uploaded.map((a) => a.id), ...selectedLibrary],
            ack_duplicate: ack,
        }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const schedule = (when) => {
        setBusy('schedule');
        router.post(route('schedule.store'), {
            lead_id: current.id,
            email_account_id: accountId,
            subject,
            body_md: body,
            attachment_ids: [...uploaded.map((a) => a.id), ...selectedLibrary],
            ack_duplicate: ack,
            scheduled_for: when,
        }, {
            preserveScroll: true,
            onSuccess: () => setScheduling(false),
            onFinish: () => setBusy(null),
        });
    };

    const check = current ? { suppressed: current.suppressed, history: current.history } : null;
    const blocked = !current || current.suppressed || (current.history?.count > 0 && !ack);
    const canSend = current && accountId && subject && body && !blocked && !busy;

    return (
        <AppShell title={`Review · ${campaign.name}`}>
            <PageShell>
                <PageHeader
                    eyebrow={<Link href={route('campaigns.show', campaign.id)} className="inline-flex items-center gap-1 hover:underline"><ArrowLeft className="h-3 w-3" /> {campaign.name}</Link>}
                    title="Review & send"
                    description={`${queue.length} ready · ${progress.counts.sent} sent · ${progress.counts.pending} still need a draft`}
                />

                <div className="grid gap-6 xl:grid-cols-4">
                    <Card padded={false} title="Ready to send" description="Click a lead to review it." className="xl:col-span-1">
                        {queue.length === 0 ? (
                            <EmptyState title="Queue is empty" description="Generate drafts on the campaign page, or write one by hand from the leads list." />
                        ) : (
                            <ul className="max-h-[70vh] divide-y divide-line overflow-y-auto scroll-thin">
                                {queue.map((l) => (
                                    <li key={l.id}>
                                        <Link href={route('campaigns.queue', { campaign: campaign.id, lead: l.id })} preserveScroll only={['current']}
                                            className={cx('block px-4 py-2.5 transition hover:bg-surface-2', current?.id === l.id && 'border-l-2 border-accent bg-accent/5')}>
                                            <div className="truncate text-sm font-medium text-body">{l.name || l.email}</div>
                                            <div className="flex items-center gap-2 truncate text-xs text-subtle">{l.company || l.email}{l.previously_contacted_count > 0 && <Badge tone="warning">contacted</Badge>}</div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <div className="space-y-6 xl:col-span-2">
                        {!current ? (
                            <Card><EmptyState title="Nothing selected" description="Pick a lead on the left." /></Card>
                        ) : (
                            <>
                                <Card title={current.name || current.email} description={[current.company, current.email].filter(Boolean).join(' · ')}
                                    actions={current.draft_full && <Badge tone={SOURCE_TONE[current.draft_full.source]}>{current.draft_full.source === 'ai' ? `AI draft${current.draft_full.generation_count > 1 ? ` ×${current.draft_full.generation_count}` : ''}` : current.draft_full.source === 'csv' ? 'From CSV' : 'Hand-written'}</Badge>}>
                                    <RecipientStatus check={check} ack={ack} onAck={setAck} timezone={settings.timezone} />
                                    {Object.keys(current.attributes || {}).length > 0 && (
                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                            {Object.entries(current.attributes).map(([k, v]) => (
                                                <span key={k} className="inline-flex max-w-full items-center gap-1 rounded-full border border-line bg-surface-2/60 px-2.5 py-0.5 text-xs" title={String(v)}>
                                                    <span className="text-subtle">{k}:</span> <span className="truncate text-body">{String(v)}</span>
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </Card>

                                <Card title="Draft" description={current.draft_full ? `Last edited ${formatDateTime(current.draft_full.updated_at, settings.timezone)}` : 'No draft yet — write one below.'}
                                    actions={
                                        <div className="flex items-center gap-2">
                                            <Button size="sm" variant="secondary" icon={<RefreshCw className="h-3.5 w-3.5" />} loading={busy === 'regen'} onClick={() => { setBusy('regen'); router.post(route('leads.regenerate', current.id), {}, { preserveScroll: true, onFinish: () => setBusy(null) }); }}>Regenerate</Button>
                                            <Button size="sm" variant="secondary" icon={<Save className="h-3.5 w-3.5" />} loading={busy === 'save'} disabled={!dirty} onClick={saveDraft}>Save</Button>
                                        </div>
                                    }>
                                    <div className="space-y-4">
                                        <Field label="Subject" htmlFor="q-subject" required error={errors.subject}>
                                            <Input id="q-subject" value={subject} onChange={(e) => setSubject(e.target.value)} />
                                        </Field>
                                        <BodyEditor value={body} onChange={setBody} variables={current.variables} signatureMd={selectedRow?.account.signature_md} error={errors.body_md} rows={16} />
                                        <div className="border-t border-line pt-4">
                                            <AttachmentsField uploaded={uploaded} setUploaded={setUploaded} library={library} selectedLibrary={selectedLibrary} setSelectedLibrary={setSelectedLibrary} maxMb={settings.max_attachment_mb} allowed={settings.allowed_extensions} error={errors.attachment_ids} />
                                        </div>
                                    </div>
                                </Card>
                            </>
                        )}
                    </div>

                    <div className="space-y-6 xl:col-span-1">
                        <Card title="Send from">
                            <AccountPicker accounts={accounts} value={accountId} onChange={setAccountId} minGap={settings.min_gap_warning_seconds} error={errors.email_account_id} />
                            {errors.to && <p className="mt-2 text-xs text-danger">{errors.to}</p>}
                        </Card>
                        <Card>
                            <Button block icon={<Send className="h-4 w-4" />} disabled={!canSend} loading={busy === 'send'} onClick={send}>Send to {current?.first_name || 'lead'}</Button>
                            <Button block variant="secondary" className="mt-2" icon={<CalendarClock className="h-4 w-4" />} disabled={!canSend} onClick={() => setScheduling(true)}>Schedule for later</Button>
                            <Button block variant="ghost" className="mt-2" icon={<SkipForward className="h-4 w-4" />} disabled={!current} onClick={() => router.post(route('leads.skip', current.id))}>Skip this lead</Button>
                            <p className="mt-3 text-center text-xs text-subtle">Sending moves you to the next ready lead. Edits are saved with the send.</p>
                        </Card>
                    </div>
                </div>

                {current && <ScheduleModal open={scheduling} onClose={() => setScheduling(false)} onConfirm={schedule} timezone={settings.timezone} processing={busy === 'schedule'} error={errors.scheduled_for} />}
            </PageShell>
        </AppShell>
    );
}
