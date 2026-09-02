import { useState } from 'react';
import { Copy, KeyRound, X } from 'lucide-react';
import notify from '@/Utils/notify';

/** One-time reveal of a generated login. Rendered from flash.credentials. */
export default function CredentialsReveal({ credentials }) {
    const [dismissed, setDismissed] = useState(false);
    if (!credentials || dismissed) return null;

    const copy = async (text, label) => {
        try {
            await navigator.clipboard.writeText(text);
            notify.success(`${label} copied.`);
        } catch {
            notify.error('Copy failed — select the text manually.');
        }
    };
    const both = `Email: ${credentials.email}\nPassword: ${credentials.password}`;

    return (
        <div className="relative rounded-xl border border-accent/40 bg-accent/5 p-4">
            <button type="button" onClick={() => setDismissed(true)} aria-label="Dismiss" className="absolute right-3 top-3 rounded-md p-1 text-muted hover:bg-surface-2 hover:text-body">
                <X className="h-4 w-4" />
            </button>
            <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent"><KeyRound className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1">
                    <div className="font-semibold text-body">Login for {credentials.name} — shown once</div>
                    <p className="mt-0.5 text-sm text-muted">Send these to them privately. They can change the password from their profile.</p>
                    <dl className="mt-3 grid gap-2 sm:grid-cols-2">
                        {[['Email', credentials.email], ['Password', credentials.password]].map(([label, value]) => (
                            <div key={label} className="flex items-center justify-between gap-2 rounded-lg border border-line bg-surface px-3 py-2">
                                <div className="min-w-0">
                                    <dt className="text-[11px] uppercase tracking-wide text-subtle">{label}</dt>
                                    <dd className="truncate font-mono text-sm text-body">{value}</dd>
                                </div>
                                <button type="button" onClick={() => copy(value, label)} className="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-body" aria-label={`Copy ${label}`}>
                                    <Copy className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                    </dl>
                    <button type="button" onClick={() => copy(both, 'Login details')} className="mt-3 text-sm font-medium text-accent hover:underline">Copy both</button>
                </div>
            </div>
        </div>
    );
}
