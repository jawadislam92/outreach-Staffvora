import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Mail, Pencil, Plug, Plus, Power, RefreshCw, Send, Trash2, Users } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, Checkbox, ConfirmDialog, EmptyState, Field, Input, Select, Textarea } from '@/Components/ui';
import { formatDateTime, formatGap } from '@/Utils/format';

const cx = (...a) => a.filter(Boolean).join(' ');
const STATUS_TONE = { active: 'success', needs_reconnect: 'warning', disabled: 'neutral' };

function SmtpForm({ initial, presets, users, defaultCap, errors, submitting, submitLabel, onSubmit, isEdit = false, isGmail = false }) {
    const [data, set] = useState(initial);
    const setData = (k, v) => set((d) => ({ ...d, [k]: v }));
    const applyPreset = (key) => {
        const p = presets.find((x) => x.key === key);
        if (!p) return;
        set((d) => ({ ...d, preset: key, smtp_host: p.host || d.smtp_host, smtp_port: p.port, smtp_encryption: p.encryption }));
    };
    const toggleMember = (id) => setData('member_ids', data.member_ids.includes(id) ? data.member_ids.filter((x) => x !== id) : [...data.member_ids, id]);

    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(data); }} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Label" htmlFor="a-label" required error={errors.label} hint="How the team sees it in the picker.">
                    <Input id="a-label" value={data.label} onChange={(e) => setData('label', e.target.value)} required />
                </Field>
                <Field label="From name" htmlFor="a-from-name" required error={errors.from_name}>
                    <Input id="a-from-name" value={data.from_name} onChange={(e) => setData('from_name', e.target.value)} required />
                </Field>
                <Field label="From email" htmlFor="a-from-email" required error={errors.from_email} hint={isGmail ? 'Fixed to the connected Google mailbox.' : 'Must be an address the mailbox is allowed to send as.'}>
                    <Input id="a-from-email" type="email" value={data.from_email} onChange={(e) => setData('from_email', e.target.value)} required disabled={isGmail} />
                </Field>
                <Field label="Reply-to (optional)" htmlFor="a-reply" error={errors.reply_to}>
                    <Input id="a-reply" type="email" value={data.reply_to || ''} onChange={(e) => setData('reply_to', e.target.value)} />
                </Field>
            </div>

            {!isGmail && (
                <div className="rounded-xl border border-line bg-surface-2/40 p-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Provider preset" htmlFor="a-preset" className="sm:col-span-2">
                            <Select id="a-preset" value={data.preset || ''} onChange={(e) => applyPreset(e.target.value)}>
                                <option value="">Choose…</option>
                                {presets.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="SMTP host" htmlFor="a-host" required error={errors.smtp_host}>
                            <Input id="a-host" value={data.smtp_host} onChange={(e) => setData('smtp_host', e.target.value)} placeholder="smtp.hostinger.com" required />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Port" htmlFor="a-port" error={errors.smtp_port}>
                                <Select id="a-port" value={data.smtp_port} onChange={(e) => setData('smtp_port', Number(e.target.value))}>
                                    <option value={465}>465</option>
                                    <option value={587}>587</option>
                                </Select>
                            </Field>
                            <Field label="Encryption" htmlFor="a-enc" error={errors.smtp_encryption}>
                                <Select id="a-enc" value={data.smtp_encryption} onChange={(e) => setData('smtp_encryption', e.target.value)}>
                                    <option value="ssl">SSL/TLS (465)</option>
                                    <option value="starttls">STARTTLS (587)</option>
                                </Select>
                            </Field>
                        </div>
                        <Field label="Username" htmlFor="a-user" required error={errors.smtp_username} hint="Usually the full email address.">
                            <Input id="a-user" value={data.smtp_username} onChange={(e) => setData('smtp_username', e.target.value)} autoComplete="off" required />
                        </Field>
                        <Field label={isEdit ? 'Password (leave blank to keep)' : 'Password'} htmlFor="a-pass" required={!isEdit} error={errors.smtp_password} hint="Stored encrypted; never shown again.">
                            <Input id="a-pass" type="password" value={data.smtp_password} onChange={(e) => setData('smtp_password', e.target.value)} autoComplete="new-password" required={!isEdit} />
                        </Field>
                    </div>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Daily cap" htmlFor="a-cap" required error={errors.daily_cap} hint={`Hard limit per business day (team default ${defaultCap}).`}>
                    <Input id="a-cap" type="number" min="0" max="10000" value={data.daily_cap} onChange={(e) => setData('daily_cap', Number(e.target.value))} required />
                </Field>
                <Field label="Signature (Markdown)" htmlFor="a-sig" error={errors.signature_md} hint="Appended to every email from this account." className="sm:col-span-2">
                    <Textarea id="a-sig" rows={3} value={data.signature_md || ''} onChange={(e) => setData('signature_md', e.target.value)} placeholder={'Best,\nJawad · Sparking Asia'} />
                </Field>
            </div>

            <div>
                <div className="mb-1.5 text-sm font-medium text-body">Who may send from it</div>
                <p className="mb-2 text-xs text-subtle">Admins can always use every account.</p>
                <div className="flex flex-wrap gap-x-4 gap-y-2">
                    {users.filter((u) => u.role !== 'admin').map((u) => (
                        <Checkbox key={u.id} label={u.name} checked={data.member_ids.includes(u.id)} onChange={() => toggleMember(u.id)} />
                    ))}
                    {users.filter((u) => u.role !== 'admin').length === 0 && <span className="text-xs text-subtle">No members yet — add them under Team.</span>}
                </div>
                {errors.member_ids && <p className="mt-1 text-xs text-danger">{errors.member_ids}</p>}
            </div>

            <div className="flex justify-end pt-2">
                <Button type="submit" loading={submitting}>{submitLabel}</Button>
            </div>
        </form>
    );
}

function AccountCard({ a, timezone, onEdit, onTest, onSendTest, onToggle, onRemove, busyKey }) {
    const u = a.usage;
    const pct = u && u.cap ? Math.min(100, Math.round((u.used / u.cap) * 100)) : 0;
    return (
        <div className={cx('rounded-xl border border-line bg-surface p-5', a.status === 'disabled' && 'opacity-70')}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-base font-semibold text-body">{a.label}</h3>
                        <Badge tone={a.provider === 'gmail_oauth' ? 'accent' : 'neutral'}>{a.provider_label}</Badge>
                        <Badge tone={STATUS_TONE[a.status] || 'neutral'} dot>{a.status_label}</Badge>
                    </div>
                    <div className="mt-0.5 text-sm text-muted">{a.from_name} &lt;{a.from_email}&gt;{a.reply_to ? ` · replies to ${a.reply_to}` : ''}</div>
                    <div className="mt-0.5 text-xs text-subtle">
                        {a.provider === 'smtp' ? `${a.smtp_host}:${a.smtp_port} (${a.smtp_encryption}) as ${a.smtp_username}` : `Google send-only · ${a.connected_email}`}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <Button size="sm" variant="secondary" icon={<Plug className="h-3.5 w-3.5" />} loading={busyKey === `test-${a.id}`} onClick={onTest}>Test connection</Button>
                    <Button size="sm" variant="secondary" icon={<Send className="h-3.5 w-3.5" />} loading={busyKey === `send-${a.id}`} onClick={onSendTest}>Send test to me</Button>
                    {a.provider === 'gmail_oauth' && (
                        <Button size="sm" variant="secondary" icon={<RefreshCw className="h-3.5 w-3.5" />} href={route('admin.accounts.gmail.connect', { account: a.id })}>Reconnect</Button>
                    )}
                    <Button size="sm" variant="ghost" icon={<Pencil className="h-3.5 w-3.5" />} onClick={onEdit}>Edit</Button>
                    {a.status !== 'needs_reconnect' && (
                        <Button size="sm" variant="ghost" icon={<Power className="h-3.5 w-3.5" />} onClick={onToggle}>{a.status === 'disabled' ? 'Enable' : 'Disable'}</Button>
                    )}
                    <Button size="sm" variant="ghost" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={onRemove}>Remove</Button>
                </div>
            </div>

            <div className="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <div className="flex items-baseline justify-between text-sm">
                        <span className="text-muted">Today</span>
                        <span className="tabular-nums text-body">{u ? `${u.used} / ${u.cap}` : `0 / ${a.daily_cap}`}</span>
                    </div>
                    <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-2">
                        <div className={cx('h-full rounded-full', u && u.remaining === 0 ? 'bg-danger' : 'bg-accent')} style={{ width: `${pct}%` }} />
                    </div>
                    <div className="mt-1 text-xs text-subtle">{u?.last_sent_at ? `Last send ${formatGap(Math.round((Date.now() - new Date(u.last_sent_at).getTime()) / 1000))} ago` : 'No sends yet'}</div>
                </div>
                <div>
                    <div className="text-sm text-muted">Members with access</div>
                    <div className="mt-1 flex flex-wrap gap-1">
                        {a.members.length === 0 ? <span className="text-xs text-subtle">Admins only</span> : a.members.map((m) => <Badge key={m}>{m}</Badge>)}
                    </div>
                </div>
                <div>
                    <div className="text-sm text-muted">Last test</div>
                    <div className="mt-1 text-xs text-body">{a.last_test_at ? formatDateTime(a.last_test_at, timezone) : 'Never'}</div>
                    {a.last_error && <div className="mt-1 rounded-md bg-danger-soft px-2 py-1 text-xs text-danger">{a.last_error}</div>}
                </div>
            </div>
        </div>
    );
}

export default function Accounts({ accounts, users, presets, default_daily_cap, google_configured, timezone }) {
    const { auth } = usePage().props;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [errors, setErrors] = useState({});
    const [busyKey, setBusyKey] = useState(null);

    const call = (method, url, data, key, onDone) => {
        setBusyKey(key);
        setErrors({});
        router[method](url, data, {
            preserveScroll: true,
            onError: (errs) => setErrors(errs),
            onSuccess: () => onDone?.(),
            onFinish: () => setBusyKey(null),
        });
    };

    const blank = { preset: '', label: '', from_name: auth.user.name, from_email: '', reply_to: '', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl', smtp_username: '', smtp_password: '', daily_cap: default_daily_cap, signature_md: '', member_ids: [] };

    return (
        <AppShell title="Email accounts">
            <PageShell>
                <PageHeader
                    eyebrow="Admin"
                    title="Email accounts"
                    description="The mailboxes outreach is sent from. Gmail connects send-only (the app can never read mail); SMTP mailboxes are outbound only."
                    actions={
                        <>
                            <Button variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => { setErrors({}); setAdding(true); }}>Add SMTP mailbox</Button>
                            <Button icon={<Mail className="h-4 w-4" />} href={google_configured ? route('admin.accounts.gmail.connect') : undefined} disabled={!google_configured} title={google_configured ? undefined : 'Set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in .env first'}>Connect Gmail</Button>
                        </>
                    }
                />

                {!google_configured && (
                    <div className="rounded-xl border border-line bg-surface-2/50 px-4 py-3 text-sm text-muted">
                        Gmail connections need a Google OAuth client. See <code className="rounded bg-surface px-1">docs/GOOGLE-OAUTH-SETUP.md</code>, then set <code className="rounded bg-surface px-1">GOOGLE_CLIENT_ID</code> and <code className="rounded bg-surface px-1">GOOGLE_CLIENT_SECRET</code> in <code className="rounded bg-surface px-1">.env</code>.
                    </div>
                )}

                {accounts.length === 0 ? (
                    <Card><EmptyState icon={<Mail className="h-5 w-5" />} title="No sending accounts yet" description="Add an SMTP mailbox or connect a Gmail account to start sending." /></Card>
                ) : (
                    <div className="space-y-4">
                        {accounts.map((a) => (
                            <AccountCard
                                key={a.id}
                                a={a}
                                timezone={timezone}
                                busyKey={busyKey}
                                onEdit={() => { setErrors({}); setEditing(a); }}
                                onTest={() => call('post', route('admin.accounts.test', a.id), {}, `test-${a.id}`)}
                                onSendTest={() => call('post', route('admin.accounts.send-test', a.id), {}, `send-${a.id}`)}
                                onToggle={() => call('patch', route('admin.accounts.status', a.id), { status: a.status === 'disabled' ? 'active' : 'disabled' }, `toggle-${a.id}`)}
                                onRemove={() => setRemoving(a)}
                            />
                        ))}
                    </div>
                )}

                <Modal show={adding} onClose={() => setAdding(false)} maxWidth="2xl">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">Add an SMTP mailbox</h2>
                        <p className="mt-1 text-sm text-muted">Hostinger, cPanel, Outlook, or Gmail with an app password. Only sending is ever used.</p>
                        <div className="mt-5">
                            <SmtpForm initial={blank} presets={presets} users={users} defaultCap={default_daily_cap} errors={errors} submitting={busyKey === 'add'} submitLabel="Add mailbox"
                                onSubmit={(data) => call('post', route('admin.accounts.store'), data, 'add', () => setAdding(false))} />
                        </div>
                    </div>
                </Modal>

                <Modal show={!!editing} onClose={() => setEditing(null)} maxWidth="2xl">
                    {editing && (
                        <div className="p-6">
                            <h2 className="text-lg font-semibold text-body">Edit {editing.label}</h2>
                            <div className="mt-5">
                                <SmtpForm
                                    isEdit
                                    isGmail={editing.provider === 'gmail_oauth'}
                                    initial={{ preset: '', label: editing.label, from_name: editing.from_name, from_email: editing.from_email, reply_to: editing.reply_to || '', smtp_host: editing.smtp_host || '', smtp_port: editing.smtp_port || 465, smtp_encryption: editing.smtp_encryption || 'ssl', smtp_username: editing.smtp_username || '', smtp_password: '', daily_cap: editing.daily_cap, signature_md: editing.signature_md || '', member_ids: editing.member_ids }}
                                    presets={presets} users={users} defaultCap={default_daily_cap} errors={errors} submitting={busyKey === 'edit'} submitLabel="Save changes"
                                    onSubmit={(data) => call('patch', route('admin.accounts.update', editing.id), data, 'edit', () => setEditing(null))} />
                            </div>
                        </div>
                    )}
                </Modal>

                <ConfirmDialog
                    open={!!removing}
                    title={`Remove ${removing?.label}?`}
                    description="Its credentials are erased and nobody can send from it any more. Everything it already sent stays in the log."
                    confirmLabel="Remove account"
                    tone="danger"
                    loading={busyKey === 'remove'}
                    onClose={() => setRemoving(null)}
                    onConfirm={() => call('delete', route('admin.accounts.destroy', removing.id), {}, 'remove', () => setRemoving(null))}
                />
            </PageShell>
        </AppShell>
    );
}
