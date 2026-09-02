import { Head, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

/** Centered card for the unauthenticated pages (login, confirm password). */
export default function GuestLayout({ title, children }) {
    const appName = usePage().props.app?.name || 'Outreach';
    return (
        <div className="brand-glow relative flex min-h-screen flex-col items-center justify-center bg-app px-4 py-10">
            {title && <Head title={title} />}
            <div className="relative mb-7 flex flex-col items-center gap-3">
                <ApplicationLogo className="h-14 w-14 drop-shadow-xl" />
                <span className="text-3xl font-bold tracking-tight text-body">{appName}</span>
            </div>
            <div className="relative w-full max-w-md rounded-3xl border border-line bg-surface p-8 shadow-xl shadow-black/5">{children}</div>
        </div>
    );
}
