import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { FileText, Pencil, PenSquare, Plus, Trash2 } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Field, Input, Spinner, Textarea, Toggle } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

const cx = (...a) => a.filter(Boolean).join(' ');
const SAMPLE_VARS = { first_name: 'Jane', name: 'Jane Doe', company: 'Acme' };
const SUPPORTED = ['first_name', 'last_name', 'name', 'company', 'email'];
const braces = (v) => `{{${v}}}`;
const excerpt = (s, n = 180) => (s.length > n ? `${s.slice(0, n).trimEnd()}…` : s);

/** Server-rendered Markdown (route markdown.preview) with sample lead data, debounced 400 ms. */
function LivePreview({ markdown }) {
    const [preview, setPreview] = useState({ html: '', unresolved: [] });
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!markdown.trim()) {
            setPreview({ html: '', unresolved: [] });
            setLoading(false);
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.post(route('markdown.preview'), { markdown, variables: SAMPLE_VARS });
                if (!cancelled) {
                    setPreview({ html: data.html || '', unresolved: data.unresolved || [] });
                    setFailed(false);
                }
            } catch {
                if (!cancelled) setFailed(true);
            } finally {
                if (!cancelled) setLoading(false);
            }
        }, 400);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [markdown]);

    return (
        <div className="flex h-full min-h-[12rem] flex-col rounded-lg border border-line bg-surface-2/40 p-4">
            {preview.unresolved.length > 0 && (
                <p className="mb-3 rounded-md bg-warning-soft px-3 py-2 text-xs text-warning">
                    No sample value and no fallback — these would go out literally: {preview.unresolved.map(braces).join(', ')}
                </p>
            )}
            {failed && <p className="mb-3 text-xs text-danger">Preview unavailable right now.</p>}
            {preview.html ? (
                <div className="md-preview" dangerouslySetInnerHTML={{ __html: preview.html }} />
            ) : (
                <p className="text-sm text-subtle">{loading ? 'Rendering…' : 'Start typing to see the rendered email.'}</p>
            )}
            {loading && preview.html && <p className="mt-3 flex items-center gap-1.5 text-xs text-subtle"><Spinner size={12} /> Updating…</p>}
        </div>
    );
}

function TemplateForm({ initial, errors, submitting, submitLabel, onSubmit }) {
    const [data, setDataState] = useState(initial);
    const setData = (k, v) => setDataState((d) => ({ ...d, [k]: v }));
    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(data); }} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" htmlFor="t-name" error={errors.name} required hint="How it appears in the Compose picker.">
                    <Input id="t-name" value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={120} required />
                </Field>
                <Field label="Subject" htmlFor="t-subject" error={errors.subject} required hint="Variables work here too.">
                    <Input id="t-subject" value={data.subject} onChange={(e) => setData('subject', e.target.value)} placeholder="Quick question, {{first_name|there}}" maxLength={255} required />
                </Field>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Body (Markdown)" htmlFor="t-body" error={errors.body_md} required>
                    <Textarea id="t-body" rows={14} value={data.body_md} onChange={(e) => setData('body_md', e.target.value)} placeholder={'Hi {{first_name|there}},\n\nI noticed {{company|your team}} …'} className="font-mono text-[13px] leading-relaxed" required />
                </Field>
                <div className="flex flex-col">
                    <div className="mb-1.5 text-sm font-medium text-body">Preview <span className="font-normal text-subtle">— as Jane Doe at Acme</span></div>
                    <LivePreview markdown={data.body_md} />
                </div>
            </div>
            <Toggle label={data.is_active ? 'Active — offered in Compose and campaigns' : 'Inactive — hidden from the pickers'} checked={!!data.is_active} onChange={(v) => setData('is_active', v)} />
            {errors.is_active && <p className="text-xs text-danger">{errors.is_active}</p>}
            <div className="flex justify-end gap-2 pt-2">
                <Button type="submit" loading={submitting}>{submitLabel}</Button>
            </div>
        </form>
    );
}

function TemplateCard({ t, timezone, canManage, busyKey, onEdit, onToggle, onRemove }) {
    return (
        <div className={cx('flex flex-col rounded-xl border border-line bg-surface p-5', !t.is_active && 'opacity-70')}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-base font-semibold text-body">{t.name}</h3>
                    <p className="mt-0.5 truncate text-sm text-muted" title={t.subject}>{t.subject}</p>
                </div>
                <Badge tone={t.is_active ? 'success' : 'neutral'} dot>{t.is_active ? 'Active' : 'Inactive'}</Badge>
            </div>

            <p className="mt-3 flex-1 whitespace-pre-line text-sm text-muted">{excerpt(t.body_md)}</p>

            <div className="mt-3 flex flex-wrap gap-1">
                {t.variables.length === 0
                    ? <span className="text-xs text-subtle">No variables</span>
                    : t.variables.map((v) => <Badge key={v} tone="accent"><code>{braces(v)}</code></Badge>)}
            </div>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                <div className="text-xs text-subtle">{t.creator ? `${t.creator} · ` : ''}updated {formatDateTime(t.updated_at, timezone)}</div>
                <div className="flex flex-wrap items-center gap-1">
                    <Button size="xs" variant="secondary" icon={<PenSquare className="h-3.5 w-3.5" />} to={route('compose.create')}>Use in Compose</Button>
                    {canManage && (
                        <>
                            <Toggle checked={t.is_active} disabled={busyKey === `toggle-${t.id}`} onChange={onToggle} aria-label={`${t.name} active`} className="ml-1" />
                            <Button size="xs" variant="ghost" icon={<Pencil className="h-3.5 w-3.5" />} onClick={onEdit}>Edit</Button>
                            <Button size="xs" variant="ghost" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={onRemove}>Delete</Button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function Index({ templates, can_manage }) {
    const { app } = usePage().props;
    const [creating, setCreating] = useState(false);
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

    const blank = { name: '', subject: '', body_md: '', is_active: true };

    return (
        <AppShell title="Templates">
            <PageShell>
                <PageHeader
                    title="Templates"
                    description={can_manage
                        ? 'Reusable subject and body text with variables. Active templates are offered in Compose and as the base text for campaigns.'
                        : 'Reusable subject and body text with variables. Pick one in Compose; admins maintain the list.'}
                    actions={can_manage ? <Button icon={<Plus className="h-4 w-4" />} onClick={() => { setErrors({}); setCreating(true); }}>New template</Button> : null}
                />

                <div className="rounded-xl border border-line bg-surface-2/50 px-4 py-3 text-sm text-muted">
                    <span className="font-medium text-body">Variables:</span>{' '}
                    {SUPPORTED.map((v, i) => (
                        <span key={v}>
                            <code className="rounded bg-surface px-1">{braces(v)}</code>{i < SUPPORTED.length - 1 ? ', ' : ''}
                        </span>
                    ))}
                    . Add a fallback with a pipe for when a value is missing — <code className="rounded bg-surface px-1">{braces('company|your team')}</code>.
                    In campaigns every extra CSV column becomes a variable too (<code className="rounded bg-surface px-1">{braces('city')}</code>, <code className="rounded bg-surface px-1">{braces('role')}</code>, …).
                    Unknown variables without a fallback are left visible so the reviewer notices.
                </div>

                {templates.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={<FileText className="h-5 w-5" />}
                            title="No templates yet"
                            description={can_manage ? 'Create the first one — a short intro with {{first_name}} and {{company}} is a good start.' : 'An admin has not added any templates yet.'}
                            action={can_manage ? <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => { setErrors({}); setCreating(true); }}>New template</Button> : null}
                        />
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {templates.map((t) => (
                            <TemplateCard
                                key={t.id}
                                t={t}
                                timezone={app.timezone}
                                canManage={can_manage}
                                busyKey={busyKey}
                                onEdit={() => { setErrors({}); setEditing(t); }}
                                onToggle={() => call('patch', route('templates.update', t.id), { is_active: !t.is_active }, `toggle-${t.id}`)}
                                onRemove={() => setRemoving(t)}
                            />
                        ))}
                    </div>
                )}

                <Modal show={creating} onClose={() => setCreating(false)} maxWidth="3xl">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">New template</h2>
                        <p className="mt-1 text-sm text-muted">Write it once with variables; every send fills in the lead's details.</p>
                        <div className="mt-5">
                            <TemplateForm
                                initial={blank}
                                errors={errors}
                                submitting={busyKey === 'create'}
                                submitLabel="Create template"
                                onSubmit={(data) => call('post', route('templates.store'), data, 'create', () => setCreating(false))}
                            />
                        </div>
                    </div>
                </Modal>

                <Modal show={!!editing} onClose={() => setEditing(null)} maxWidth="3xl">
                    {editing && (
                        <div className="p-6">
                            <h2 className="text-lg font-semibold text-body">Edit {editing.name}</h2>
                            <p className="mt-1 text-sm text-muted">Changes apply to future sends only; campaigns that copied the text keep their copy.</p>
                            <div className="mt-5">
                                <TemplateForm
                                    initial={{ name: editing.name, subject: editing.subject, body_md: editing.body_md, is_active: editing.is_active }}
                                    errors={errors}
                                    submitting={busyKey === 'edit'}
                                    submitLabel="Save changes"
                                    onSubmit={(data) => call('patch', route('templates.update', editing.id), data, 'edit', () => setEditing(null))}
                                />
                            </div>
                        </div>
                    )}
                </Modal>

                <ConfirmDialog
                    open={!!removing}
                    title={`Delete ${removing?.name}?`}
                    description="It disappears from Compose and from campaign set-up. Emails already sent are unaffected; campaigns that used it keep any text they copied."
                    confirmLabel="Delete template"
                    tone="danger"
                    loading={busyKey === 'remove'}
                    onClose={() => setRemoving(null)}
                    onConfirm={() => call('delete', route('templates.destroy', removing.id), {}, 'remove', () => setRemoving(null))}
                />
            </PageShell>
        </AppShell>
    );
}
