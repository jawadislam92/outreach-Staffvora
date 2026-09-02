import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Download, FileText, Paperclip, Trash2, Upload } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Button, Card, ConfirmDialog, EmptyState, Table, TBody, Td, Th, THead, Toggle, Tr } from '@/Components/ui';
import { formatBytes, formatDateTime } from '@/Utils/format';

const TYPE_LABEL = {
    'application/pdf': 'PDF',
    'image/png': 'PNG',
    'image/jpeg': 'JPEG',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PowerPoint',
};
const typeLabel = (f) => TYPE_LABEL[f.mime] || (f.name.includes('.') ? f.name.split('.').pop().toUpperCase() : 'File');

export default function Library({ files, allowed_extensions, max_mb }) {
    const { app } = usePage().props;
    const inputRef = useRef();
    const [errors, setErrors] = useState({});
    const [busyKey, setBusyKey] = useState(null);
    const [removing, setRemoving] = useState(null);

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

    const upload = (file) => {
        if (!file) return;
        setBusyKey('upload');
        setErrors({});
        router.post(route('admin.library.store'), { file }, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errs) => setErrors(errs),
            onSuccess: () => setErrors({}),
            onFinish: () => {
                setBusyKey(null);
                if (inputRef.current) inputRef.current.value = '';
            },
        });
    };

    const pick = () => inputRef.current?.click();

    return (
        <AppShell title="Attachment library">
            <PageShell>
                <PageHeader
                    eyebrow="Admin"
                    title="Attachment library"
                    description="Files any member can attach from Compose and campaign drafts. Removing one only hides it — emails already sent keep their copy."
                    actions={
                        <>
                            <input ref={inputRef} type="file" accept={allowed_extensions.map((e) => `.${e}`).join(',')} className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                            <Button icon={<Upload className="h-4 w-4" />} loading={busyKey === 'upload'} onClick={pick}>Upload file</Button>
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-subtle">
                    <span>Allowed: {allowed_extensions.join(', ')}</span>
                    <span aria-hidden="true">·</span>
                    <span>Up to {max_mb} MB per file (the attachment limit in Settings)</span>
                </div>
                {errors.file && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{errors.file}</p>}

                <Card padded={false}>
                    {files.length === 0 ? (
                        <EmptyState
                            icon={<Paperclip className="h-5 w-5" />}
                            title="No shared files yet"
                            description="Upload a deck, one-pager or price list once and everyone can attach it without re-uploading."
                            action={<Button size="sm" variant="secondary" icon={<Upload className="h-4 w-4" />} loading={busyKey === 'upload'} onClick={pick}>Upload file</Button>}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>File</Th>
                                    <Th>Type</Th>
                                    <Th className="text-right">Size</Th>
                                    <Th>Uploaded by</Th>
                                    <Th className="text-right">Times used</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {files.map((f) => (
                                    <Tr key={f.id} className={!f.is_active ? 'opacity-60' : ''}>
                                        <Td className="max-w-[320px]">
                                            <div className="flex items-center gap-2">
                                                <FileText className="h-4 w-4 shrink-0 text-muted" />
                                                <a href={route('attachments.download', f.id)} className="truncate font-medium text-body hover:text-accent hover:underline" title="Download">{f.name}</a>
                                            </div>
                                        </Td>
                                        <Td><Badge>{typeLabel(f)}</Badge></Td>
                                        <Td className="whitespace-nowrap text-right tabular-nums text-muted">{formatBytes(f.size)}</Td>
                                        <Td className="whitespace-nowrap">
                                            <div>{f.uploader || <span className="text-subtle">—</span>}</div>
                                            <div className="text-xs text-subtle">{formatDateTime(f.created_at, app.timezone)}</div>
                                        </Td>
                                        <Td className="text-right tabular-nums">{f.times_used}</Td>
                                        <Td>
                                            <Toggle
                                                checked={f.is_active}
                                                label={f.is_active ? 'Active' : 'Hidden'}
                                                disabled={busyKey === `toggle-${f.id}`}
                                                onChange={(v) => call('patch', route('admin.library.update', f.id), { is_active: v }, `toggle-${f.id}`)}
                                            />
                                        </Td>
                                        <Td className="text-right">
                                            <div className="inline-flex items-center gap-1">
                                                <Button variant="ghost" size="xs" icon={<Download className="h-3.5 w-3.5" />} href={route('attachments.download', f.id)}>Download</Button>
                                                <Button variant="ghost" size="xs" className="text-danger" icon={<Trash2 className="h-3.5 w-3.5" />} onClick={() => setRemoving(f)}>Remove</Button>
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <ConfirmDialog
                    open={!!removing}
                    title={`Remove ${removing?.name} from the library?`}
                    description={removing?.times_used
                        ? `It disappears from Compose. The ${removing.times_used === 1 ? 'email' : `${removing.times_used} emails`} it went out with keep their copy in the log.`
                        : 'It disappears from Compose. It has not been sent with any email yet.'}
                    confirmLabel="Remove file"
                    tone="danger"
                    loading={busyKey === 'remove'}
                    onClose={() => setRemoving(null)}
                    onConfirm={() => call('delete', route('admin.library.destroy', removing.id), {}, 'remove', () => setRemoving(null))}
                />
            </PageShell>
        </AppShell>
    );
}
