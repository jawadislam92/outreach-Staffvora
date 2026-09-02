import { useRef, useState } from 'react';
import { FileText, Paperclip, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui';
import { formatBytes } from '@/Utils/format';
import notify from '@/Utils/notify';

const cx = (...a) => a.filter(Boolean).join(' ');

/** Per-send uploads (posted immediately to /attachments) plus picks from the shared library. */
export default function AttachmentsField({ uploaded, setUploaded, library, selectedLibrary, setSelectedLibrary, maxMb, allowed, error }) {
    const inputRef = useRef();
    const [busy, setBusy] = useState(false);
    const total = uploaded.reduce((s, a) => s + a.size, 0) + library.filter((l) => selectedLibrary.includes(l.id)).reduce((s, a) => s + a.size, 0);
    const over = total > maxMb * 1024 * 1024;

    const upload = async (files) => {
        setBusy(true);
        for (const file of files) {
            const fd = new FormData();
            fd.append('file', file);
            try {
                const { data } = await window.axios.post(route('attachments.store'), fd, { headers: { 'Content-Type': 'multipart/form-data' } });
                setUploaded((u) => [...u, data]);
            } catch (err) {
                notify.error(err?.response?.data?.message || err?.response?.data?.errors?.file?.[0] || `Could not upload ${file.name}`);
            }
        }
        setBusy(false);
        if (inputRef.current) inputRef.current.value = '';
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <input ref={inputRef} type="file" multiple accept={allowed.map((e) => `.${e}`).join(',')} className="hidden" onChange={(e) => upload(Array.from(e.target.files || []))} />
                <Button type="button" variant="secondary" size="sm" icon={<Paperclip className="h-4 w-4" />} loading={busy} onClick={() => inputRef.current?.click()}>Attach file</Button>
                <span className="text-xs text-subtle">{allowed.join(', ')} · up to {maxMb} MB total</span>
                <span className={cx('ml-auto text-xs tabular-nums', over ? 'font-medium text-danger' : 'text-subtle')}>{formatBytes(total)}{over ? ' — too large' : ''}</span>
            </div>
            {uploaded.length > 0 && (
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {uploaded.map((a) => (
                        <li key={a.id} className="flex items-center gap-2 px-3 py-2 text-sm">
                            <FileText className="h-4 w-4 shrink-0 text-muted" />
                            <span className="min-w-0 flex-1 truncate text-body">{a.name}</span>
                            <span className="text-xs text-subtle">{formatBytes(a.size)}</span>
                            <button type="button" onClick={() => setUploaded((u) => u.filter((x) => x.id !== a.id))} className="rounded p-1 text-muted hover:bg-surface-2 hover:text-danger" aria-label={`Remove ${a.name}`}><Trash2 className="h-4 w-4" /></button>
                        </li>
                    ))}
                </ul>
            )}
            {library.length > 0 && (
                <div>
                    <div className="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted">Shared library</div>
                    <div className="flex flex-wrap gap-2">
                        {library.map((l) => {
                            const on = selectedLibrary.includes(l.id);
                            return (
                                <button key={l.id} type="button" onClick={() => setSelectedLibrary((s) => (on ? s.filter((x) => x !== l.id) : [...s, l.id]))}
                                    className={cx('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition', on ? 'border-accent bg-accent/10 text-accent' : 'border-line bg-surface text-muted hover:text-body')}>
                                    <Paperclip className="h-3 w-3" /> {l.name} <span className="text-subtle">{formatBytes(l.size)}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
            {error && <p className="text-xs text-danger">{error}</p>}
        </div>
    );
}
