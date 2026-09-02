import { useRef } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Button, Card, Field, Input } from '@/Components/ui';
import notify from '@/Utils/notify';

function ProfileForm({ user }) {
    const { data, setData, patch, processing, errors } = useForm({ name: user.name, email: user.email });
    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };
    return (
        <Card title="Your details" description="How your name appears in the send log.">
            <form onSubmit={submit} className="space-y-4">
                <Field label="Name" htmlFor="name" error={errors.name}>
                    <Input id="name" value={data.name} required onChange={(e) => setData('name', e.target.value)} />
                </Field>
                <Field label="Email" htmlFor="email" error={errors.email} hint="Used only to log in — outreach is sent from the connected mailboxes.">
                    <Input id="email" type="email" value={data.email} required onChange={(e) => setData('email', e.target.value)} />
                </Field>
                <div className="flex justify-end">
                    <Button type="submit" loading={processing}>Save</Button>
                </div>
            </form>
        </Card>
    );
}

function PasswordForm() {
    const currentRef = useRef();
    const { data, setData, put, processing, errors, reset } = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e) => {
        e.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                notify.success('Password updated.');
            },
            onError: (errs) => {
                if (errs.current_password) {
                    reset('current_password');
                    currentRef.current?.focus();
                }
            },
        });
    };
    return (
        <Card title="Change password" description="Use a long passphrase you do not use anywhere else.">
            <form onSubmit={submit} className="space-y-4">
                <Field label="Current password" htmlFor="current_password" error={errors.current_password}>
                    <Input id="current_password" ref={currentRef} type="password" autoComplete="current-password" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} />
                </Field>
                <Field label="New password" htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>
                <Field label="Confirm new password" htmlFor="password_confirmation" error={errors.password_confirmation}>
                    <Input id="password_confirmation" type="password" autoComplete="new-password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                </Field>
                <div className="flex justify-end">
                    <Button type="submit" loading={processing}>Update password</Button>
                </div>
            </form>
        </Card>
    );
}

export default function Edit() {
    const user = usePage().props.auth.user;
    return (
        <AppShell title="Profile">
            <PageShell>
                <PageHeader
                    title="Profile"
                    description="Your login details."
                    actions={<Badge tone="accent" size="md">{user.role_label}</Badge>}
                />
                <div className="grid gap-6 xl:grid-cols-2">
                    <ProfileForm user={user} />
                    <PasswordForm />
                </div>
            </PageShell>
        </AppShell>
    );
}
