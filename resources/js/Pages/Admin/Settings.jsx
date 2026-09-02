import { useForm } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Button, Card, Field, Input, Select } from '@/Components/ui';

function EnvRow({ ok, label, detail }) {
    return (
        <div className="flex items-start gap-3 py-2">
            {ok ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-danger" />}
            <div className="min-w-0">
                <div className="text-sm font-medium text-body">{label}</div>
                {detail && <div className="truncate text-xs text-subtle">{detail}</div>}
            </div>
        </div>
    );
}

export default function Settings({ settings, timezones, models, efforts, env }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({ ...settings });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.settings.update'), { preserveScroll: true });
    };

    return (
        <AppShell title="Settings">
            <PageShell>
                <PageHeader eyebrow="Admin" title="Settings" description="Operational defaults. Secrets (Google client, Anthropic key) live in the server's .env." />

                <div className="grid gap-6 xl:grid-cols-3">
                    <form onSubmit={submit} className="space-y-6 xl:col-span-2">
                        <Card title="Sending" description="How the daily cap and pacing are measured.">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Business timezone" htmlFor="timezone" error={errors.timezone} hint="Defines when a 'day' rolls over for caps and reports.">
                                    <Select id="timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)}>
                                        {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                                    </Select>
                                </Field>
                                <Field label="Default daily cap per account" htmlFor="default_daily_cap" error={errors.default_daily_cap} hint="Pre-filled when a new mailbox is connected; each account keeps its own cap.">
                                    <Input id="default_daily_cap" type="number" min="0" max="10000" value={data.default_daily_cap} onChange={(e) => setData('default_daily_cap', Number(e.target.value))} />
                                </Field>
                                <Field label="Minimum gap warning (seconds)" htmlFor="min_gap_warning_seconds" error={errors.min_gap_warning_seconds} hint="Compose warns (but does not block) when the account's last send was more recent than this.">
                                    <Input id="min_gap_warning_seconds" type="number" min="0" max="86400" value={data.min_gap_warning_seconds} onChange={(e) => setData('min_gap_warning_seconds', Number(e.target.value))} />
                                </Field>
                                <Field label="Max attachments per email (MB)" htmlFor="max_attachment_mb" error={errors.max_attachment_mb}>
                                    <Input id="max_attachment_mb" type="number" min="1" max="25" value={data.max_attachment_mb} onChange={(e) => setData('max_attachment_mb', Number(e.target.value))} />
                                </Field>
                            </div>
                        </Card>

                        <Card title="AI drafting" description="Model used to personalise campaign drafts. Cost is shown per campaign.">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Model" htmlFor="ai_model" error={errors.ai_model}>
                                    <Select id="ai_model" value={data.ai_model} onChange={(e) => setData('ai_model', e.target.value)}>
                                        {models.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                                    </Select>
                                </Field>
                                <Field label="Effort" htmlFor="ai_effort" error={errors.ai_effort} hint="How hard the model thinks per draft. Medium is the sweet spot for short emails.">
                                    <Select id="ai_effort" value={data.ai_effort} onChange={(e) => setData('ai_effort', e.target.value)}>
                                        {efforts.map((v) => <option key={v} value={v}>{v}</option>)}
                                    </Select>
                                </Field>
                            </div>
                        </Card>

                        <div className="flex justify-end">
                            <Button type="submit" loading={processing} disabled={!isDirty}>Save settings</Button>
                        </div>
                    </form>

                    <Card title="Server setup" description="Read from .env — edit the file and restart to change.">
                        <div className="divide-y divide-line">
                            <EnvRow ok={env.google_configured} label="Google OAuth client" detail={env.google_configured ? `Redirect: ${env.google_redirect_uri}` : 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to connect Gmail accounts.'} />
                            <EnvRow ok={env.anthropic_configured} label="Anthropic API key" detail={env.anthropic_configured ? 'AI drafting enabled.' : 'Set ANTHROPIC_API_KEY to enable AI drafts.'} />
                            <EnvRow ok={true} label="App URL" detail={env.app_url} />
                            <EnvRow ok={true} label="SMTP CA bundle" detail={env.smtp_cafile || 'System certificate store'} />
                        </div>
                    </Card>
                </div>
            </PageShell>
        </AppShell>
    );
}
