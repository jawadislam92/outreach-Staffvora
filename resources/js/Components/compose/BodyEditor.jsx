import { useEffect, useState } from 'react';
import { Eye, PenLine } from 'lucide-react';
import { Spinner, Tabs, Textarea } from '@/Components/ui';
import notify from '@/Utils/notify';

/** Markdown textarea with a server-rendered preview (identical to what is sent). */
export default function BodyEditor({ value, onChange, variables, signatureMd, error, rows = 14, placeholder }) {
    const [tab, setTab] = useState('write');
    const [preview, setPreview] = useState({ html: '', unresolved: [] });
    const [signature, setSignature] = useState('');
    const [loading, setLoading] = useState(false);
    const varsKey = JSON.stringify(variables || {});

    useEffect(() => {
        if (tab !== 'preview') return undefined;
        let cancelled = false;
        setLoading(true);
        (async () => {
            try {
                const { data } = await window.axios.post(route('markdown.preview'), { markdown: value, variables: variables || {} });
                if (!cancelled) setPreview(data);
                if (signatureMd) {
                    const sig = await window.axios.post(route('markdown.preview'), { markdown: signatureMd, variables: {} });
                    if (!cancelled) setSignature(sig.data.html);
                } else if (!cancelled) {
                    setSignature('');
                }
            } catch {
                if (!cancelled) notify.error('Preview failed.');
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [tab, value, signatureMd, varsKey]);

    return (
        <div>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <Tabs size="sm" value={tab} onChange={setTab} tabs={[{ key: 'write', label: 'Write', icon: <PenLine className="h-3.5 w-3.5" /> }, { key: 'preview', label: 'Preview', icon: <Eye className="h-3.5 w-3.5" /> }]} />
                <span className="text-xs text-subtle">Markdown: **bold**, _italic_, [link](https://…), blank line = new paragraph</span>
            </div>
            {tab === 'write' ? (
                <Textarea rows={rows} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder ?? 'Hi {{first_name}},\n\nI noticed …'} className="font-mono text-[13px] leading-relaxed" />
            ) : (
                <div className="min-h-[20rem] rounded-lg border border-line bg-surface p-4">
                    {loading ? <p className="flex items-center gap-2 text-sm text-subtle"><Spinner size={14} /> Rendering…</p> : (
                        <>
                            {preview.unresolved.length > 0 && (
                                <p className="mb-3 rounded-md bg-warning-soft px-3 py-2 text-xs text-warning">Unfilled variables will go out literally: {preview.unresolved.map((v) => `{{${v}}}`).join(', ')}</p>
                            )}
                            <div className="md-preview" dangerouslySetInnerHTML={{ __html: preview.html || '<p class="text-subtle">Nothing to preview yet.</p>' }} />
                            {signature && <div className="md-preview mt-4 border-t border-line pt-3 text-muted" dangerouslySetInnerHTML={{ __html: signature }} />}
                        </>
                    )}
                </div>
            )}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
