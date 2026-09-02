import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button, Checkbox, Field, Input } from '@/Components/ui';

export default function Login({ status }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout title="Log in">
            <h1 className="text-lg font-semibold text-body">Welcome back</h1>
            <p className="mt-1 text-sm text-muted">Sign in with the login your admin gave you.</p>

            {status && <div className="mt-4 rounded-lg bg-success-soft px-3 py-2 text-sm text-success">{status}</div>}

            <form onSubmit={submit} className="mt-6 space-y-4">
                <Field label="Email" htmlFor="email" error={errors.email}>
                    <Input id="email" type="email" name="email" value={data.email} autoComplete="username" autoFocus required onChange={(e) => setData('email', e.target.value)} />
                </Field>

                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" value={data.password} autoComplete="current-password" required onChange={(e) => setData('password', e.target.value)} />
                </Field>

                <Checkbox label="Keep me signed in" name="remember" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />

                <Button type="submit" block loading={processing}>
                    Log in
                </Button>
            </form>

            <p className="mt-6 text-center text-xs text-subtle">Forgot your password? Ask an admin to reset it for you.</p>
        </GuestLayout>
    );
}
