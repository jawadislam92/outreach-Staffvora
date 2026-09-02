import { Head, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

/** Centered card for the unauthenticated pages (login, confirm password). */
export default function GuestLayout({ title, children }) {
    const appName = usePage().props.app?.name || 'Outreach';
    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center bg-app px-4 py-10">
            {title && <Head title={title} />}
            <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div className="absolute -top-32 left-1/2 h-96 w-[48rem] -translate-x-1/2 rounded-full bg-accent/10 blur-3xl" />
            </div>
            <div className="relative mb-6 flex items-center gap-3">
                <ApplicationLogo className="h-11 w-11" />
                <span className="text-2xl font-bold tracking-tight text-body">{appName}</span>
            </div>
            <div className="relative w-full max-w-md rounded-2xl border border-line bg-surface p-7 shadow-sm">{children}</div>
        </div>
    );
}
