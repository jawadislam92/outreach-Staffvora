import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Pencil, UserPlus } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import Modal from '@/Components/Modal';
import CredentialsReveal from '@/Components/CredentialsReveal';
import { Badge, Button, Card, ConfirmDialog, Field, Input, Select, Table, TBody, Td, Th, THead, Toggle, Tr } from '@/Components/ui';
import { formatDateTime } from '@/Utils/format';

function UserForm({ initial, roles, onSubmit, submitting, errors, submitLabel, isSelf }) {
    const [data, setDataState] = useState(initial);
    const setData = (k, v) => setDataState((d) => ({ ...d, [k]: v }));
    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(data); }} className="space-y-4">
            <Field label="Name" htmlFor="u-name" error={errors.name} required>
                <Input id="u-name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
            </Field>
            <Field label="Email" htmlFor="u-email" error={errors.email} required>
                <Input id="u-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Role" htmlFor="u-role" error={errors.role}>
                    <Select id="u-role" value={data.role} onChange={(e) => setData('role', e.target.value)} disabled={isSelf}>
                        {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </Select>
                </Field>
                <Field label="Daily cap (all accounts)" htmlFor="u-cap" error={errors.daily_cap} hint="Blank = no personal limit; account caps still apply.">
                    <Input id="u-cap" type="number" min="0" max="10000" value={data.daily_cap ?? ''} onChange={(e) => setData('daily_cap', e.target.value === '' ? null : Number(e.target.value))} />
                </Field>
            </div>
            {'is_active' in data && (
                <Toggle label={data.is_active ? 'Active — can log in' : 'Deactivated — cannot log in'} checked={!!data.is_active} onChange={(v) => setData('is_active', v)} disabled={isSelf} />
            )}
            {errors.is_active && <p className="text-xs text-danger">{errors.is_active}</p>}
            <div className="flex justify-end gap-2 pt-2">
                <Button type="submit" loading={submitting}>{submitLabel}</Button>
            </div>
        </form>
    );
}

export default function Users({ users, roles }) {
    const { flash, auth, app } = usePage().props;
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);
    const [resetting, setResetting] = useState(null);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const submit = (method, url, data, onDone) => {
        setBusy(true);
        setErrors({});
        router[method](url, data, {
            preserveScroll: true,
            onError: (errs) => setErrors(errs),
            onSuccess: () => onDone?.(),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <AppShell title="Team">
            <PageShell>
                <PageHeader
                    eyebrow="Admin"
                    title="Team"
                    description="Who can log in, their role, and optional personal daily caps. Account access is set per mailbox under Email accounts."
                    actions={<Button icon={<UserPlus className="h-4 w-4" />} onClick={() => { setErrors({}); setCreating(true); }}>Add member</Button>}
                />

                {flash?.credentials && <CredentialsReveal credentials={flash.credentials} />}

                <Card padded={false}>
                    <Table>
                        <THead>
                            <Tr>
                                <Th>Member</Th>
                                <Th>Role</Th>
                                <Th className="text-right">Sent today</Th>
                                <Th className="text-right">Daily cap</Th>
                                <Th>Last login</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Actions</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {users.map((u) => (
                                <Tr key={u.id} className={!u.is_active ? 'opacity-60' : ''}>
                                    <Td>
                                        <div className="font-medium text-body">{u.name}{u.id === auth.user.id && <span className="ml-1.5 text-xs text-subtle">(you)</span>}</div>
                                        <div className="text-xs text-subtle">{u.email}</div>
                                    </Td>
                                    <Td><Badge tone={u.is_admin ? 'accent' : 'neutral'}>{u.role_label}</Badge></Td>
                                    <Td className="text-right tabular-nums">{u.sent_today}</Td>
                                    <Td className="text-right tabular-nums text-muted">{u.daily_cap ?? '—'}</Td>
                                    <Td className="whitespace-nowrap text-muted">{formatDateTime(u.last_login_at, app.timezone)}</Td>
                                    <Td><Badge tone={u.is_active ? 'success' : 'danger'} dot>{u.is_active ? 'Active' : 'Deactivated'}</Badge></Td>
                                    <Td className="text-right">
                                        <div className="inline-flex items-center gap-1">
                                            <Button variant="ghost" size="xs" icon={<Pencil className="h-3.5 w-3.5" />} onClick={() => { setErrors({}); setEditing(u); }}>Edit</Button>
                                            <Button variant="ghost" size="xs" icon={<KeyRound className="h-3.5 w-3.5" />} onClick={() => setResetting(u)}>Reset password</Button>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                </Card>

                <Modal show={creating} onClose={() => setCreating(false)} maxWidth="lg">
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-body">Add a team member</h2>
                        <p className="mt-1 text-sm text-muted">A one-time password is generated and shown to you after saving.</p>
                        <div className="mt-5">
                            <UserForm
                                initial={{ name: '', email: '', role: 'member', daily_cap: null }}
                                roles={roles}
                                errors={errors}
                                submitting={busy}
                                submitLabel="Create login"
                                onSubmit={(data) => submit('post', route('admin.users.store'), data, () => setCreating(false))}
                            />
                        </div>
                    </div>
                </Modal>

                <Modal show={!!editing} onClose={() => setEditing(null)} maxWidth="lg">
                    {editing && (
                        <div className="p-6">
                            <h2 className="text-lg font-semibold text-body">Edit {editing.name}</h2>
                            <div className="mt-5">
                                <UserForm
                                    initial={{ name: editing.name, email: editing.email, role: editing.role, daily_cap: editing.daily_cap, is_active: editing.is_active }}
                                    roles={roles}
                                    errors={errors}
                                    submitting={busy}
                                    submitLabel="Save changes"
                                    isSelf={editing.id === auth.user.id}
                                    onSubmit={(data) => submit('patch', route('admin.users.update', editing.id), data, () => setEditing(null))}
                                />
                            </div>
                        </div>
                    )}
                </Modal>

                <ConfirmDialog
                    open={!!resetting}
                    title={`Reset password for ${resetting?.name}?`}
                    description="Their current password stops working immediately and a new one is shown to you once."
                    confirmLabel="Generate new password"
                    loading={busy}
                    onClose={() => setResetting(null)}
                    onConfirm={() => submit('post', route('admin.users.reset-password', resetting.id), {}, () => setResetting(null))}
                />
            </PageShell>
        </AppShell>
    );
}
