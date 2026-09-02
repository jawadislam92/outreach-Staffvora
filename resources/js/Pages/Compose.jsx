import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import AccountPicker from '@/Components/compose/AccountPicker';
import AttachmentsField from '@/Components/compose/AttachmentsField';
import BodyEditor from '@/Components/compose/BodyEditor';
import RecipientStatus from '@/Components/compose/RecipientStatus';
import { Button, Card, Field, Input, Select } from '@/Components/ui';
import notify from '@/Utils/notify';

const newToken = (fallback) => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : fallback);

export default function Compose({ accounts, templates, library, settings, prefill, client_token }) {
    const { flash } = usePage().props;
    const firstUsable = accounts.find((r) => r.account.status === 'active' && r.remaining > 0);
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        client_token: newToken(client_token),
        email_account_id: firstUsable?.account.id ?? '',
        to: prefill.to || '',
        to_name: prefill.to_name || '',
        company: prefill.company || '',
        cc: '',
        bcc: '',
        subject: '',
        body_md: '',
        ack_duplicate: false,
    });
    const [uploaded, setUploaded] = useState([]);
    const [selectedLibrary, setSelectedLibrary] = useState([]);
    const [showCc, setShowCc] = useState(false);
    const [check, setCheck] = useState(null);
    const [checking, setChecking] = useState(false);
    const [templateId, setTemplateId] = useState('');
    const checkTimer = useRef();

    const selectedRow = accounts.find((r) => r.account.id === Number(data.email_account_id));
    const [firstName, ...rest] = (data.to_name || '').trim().split(/\s+/);
    const lastName = rest.join(' ');
    const variables = useMemo(
        () => ({ name: data.to_name || '', first_name: firstName || '', last_name: lastName, company: data.company || '', email: data.to || '' }),
        [data.to_name, data.company, data.to, firstName, lastName]
    );

    const runCheck = useCallback(async (email) => {
        if (!email || !email.includes('@')) {
            setCheck(null);
            return;
        }
        setChecking(true);
        try {
            const { data: result } = await window.axios.get(route('recipients.check'), { params: { email } });
            setCheck(result);
        } catch {
            setCheck(null);
        } finally {
            setChecking(false);
        }
    }, []);

    useEffect(() => {
        clearTimeout(checkTimer.current);
        setData('ack_duplicate', false);
        checkTimer.current = setTimeout(() => runCheck(data.to.trim()), 500);
        return () => clearTimeout(checkTimer.current);
    }, [data.to]);

    const applyTemplate = () => {
        const t = templates.find((x) => String(x.id) === String(templateId));
        if (!t) return;
        setData((d) => ({ ...d, subject: t.subject, body_md: t.body_md }));
        notify.info(`Template “${t.name}” inserted.`);
    };

    const splitList = (s) => s.split(/[,;\s]+/).map((x) => x.trim()).filter(Boolean);

    const submit = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, cc: splitList(d.cc), bcc: splitList(d.bcc), attachment_ids: [...uploaded.map((a) => a.id), ...selectedLibrary] }));
        post(route('compose.send'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('to', 'to_name', 'company', 'cc', 'bcc', 'subject', 'body_md', 'ack_duplicate');
                setUploaded([]);
                setSelectedLibrary([]);
                setCheck(null);
                setData('client_token', newToken(client_token));
            },
            onError: () => setData('client_token', newToken(client_token)),
        });
    };

    const blocked = check?.suppressed || (check?.history?.count > 0 && !data.ack_duplicate);
    const canSend = !processing && accounts.length > 0 && data.email_account_id && data.to && data.subject && data.body_md && !blocked;

    return (
        <AppShell title="Compose">
            <PageShell>
                <PageHeader title="Compose" description="One personal email, sent from a connected mailbox and logged for the team." />

                <form onSubmit={submit} className="grid gap-6 xl:grid-cols-3">
                    <div className="space-y-6 xl:col-span-2">
                        <Card title="Recipient">
                            <div className="grid gap-4 md:grid-cols-2">
                                <Field label="To" htmlFor="to" required error={errors.to} className="md:col-span-2">
                                    <Input id="to" type="email" value={data.to} onChange={(e) => setData('to', e.target.value)} placeholder="jane@company.com" autoComplete="off" />
                                </Field>
                                <div className="md:col-span-2"><RecipientStatus check={check} checking={checking} ack={data.ack_duplicate} onAck={(v) => setData('ack_duplicate', v)} timezone={settings.timezone} /></div>
                                <Field label="Recipient name" htmlFor="to_name" error={errors.to_name} hint="Fills {{name}} and {{first_name}}.">
                                    <Input id="to_name" value={data.to_name} onChange={(e) => setData('to_name', e.target.value)} placeholder="Jane Doe" />
                                </Field>
                                <Field label="Company" htmlFor="company" error={errors.company} hint="Fills {{company}}.">
                                    <Input id="company" value={data.company} onChange={(e) => setData('company', e.target.value)} placeholder="Acme Inc." />
                                </Field>
                                {showCc ? (
                                    <>
                                        <Field label="Cc" htmlFor="cc" error={errors.cc || errors['cc.0']} hint="Separate with commas.">
                                            <Input id="cc" value={data.cc} onChange={(e) => setData('cc', e.target.value)} />
                                        </Field>
                                        <Field label="Bcc" htmlFor="bcc" error={errors.bcc || errors['bcc.0']}>
                                            <Input id="bcc" value={data.bcc} onChange={(e) => setData('bcc', e.target.value)} />
                                        </Field>
                                    </>
                                ) : (
                                    <button type="button" onClick={() => setShowCc(true)} className="text-left text-xs font-medium text-accent hover:underline md:col-span-2">+ Add Cc / Bcc</button>
                                )}
                            </div>
                        </Card>

                        <Card title="Message" actions={templates.length > 0 ? (
                            <div className="flex items-center gap-2">
                                <Select value={templateId} onChange={(e) => setTemplateId(e.target.value)} className="w-52">
                                    <option value="">Use a template…</option>
                                    {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                                </Select>
                                <Button type="button" variant="secondary" size="sm" onClick={applyTemplate} disabled={!templateId}>Insert</Button>
                            </div>
                        ) : null}>
                            <div className="space-y-4">
                                <Field label="Subject" htmlFor="subject" required error={errors.subject}>
                                    <Input id="subject" value={data.subject} onChange={(e) => setData('subject', e.target.value)} placeholder="Quick question about {{company}}" />
                                </Field>
                                <BodyEditor value={data.body_md} onChange={(v) => setData('body_md', v)} variables={variables} signatureMd={selectedRow?.account.signature_md} error={errors.body_md} />
                            </div>
                        </Card>

                        <Card title="Attachments">
                            <AttachmentsField uploaded={uploaded} setUploaded={setUploaded} library={library} selectedLibrary={selectedLibrary} setSelectedLibrary={setSelectedLibrary} maxMb={settings.max_attachment_mb} allowed={settings.allowed_extensions} error={errors.attachment_ids} />
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card title="Send from" description="Which mailbox this goes out of.">
                            <AccountPicker accounts={accounts} value={data.email_account_id} onChange={(id) => setData('email_account_id', id)} minGap={settings.min_gap_warning_seconds} error={errors.email_account_id} />
                        </Card>

                        <Card>
                            <Button type="submit" block loading={processing} disabled={!canSend} icon={<Send className="h-4 w-4" />}>Send email</Button>
                            <p className="mt-3 text-center text-xs text-subtle">Logged with your name, the account, the time and the gap since that account's last send.</p>
                            {flash?.blocked?.reason === 'cap_reached' && <p className="mt-2 text-center text-xs text-danger">{errors.email_account_id}</p>}
                        </Card>
                    </div>
                </form>
            </PageShell>
        </AppShell>
    );
}
