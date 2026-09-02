import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button, Field, Input } from '@/Components/ui';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout title="Confirm password">
            <h1 className="text-lg font-semibold text-body">Confirm your password</h1>
            <p className="mt-1 text-sm text-muted">This is a sensitive area. Please confirm your password before continuing.</p>

            <form onSubmit={submit} className="mt-6 space-y-4">
                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input id="password" type="password" name="password" value={data.password} autoFocus required onChange={(e) => setData('password', e.target.value)} />
                </Field>
                <Button type="submit" block loading={processing}>Confirm</Button>
            </form>
        </GuestLayout>
    );
}
