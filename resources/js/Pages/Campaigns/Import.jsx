import { useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CheckCircle2, Download, FileSpreadsheet, Upload } from 'lucide-react';
import AppShell from '@/Layouts/AppShell';
import PageShell from '@/Components/Layout/PageShell';
import PageHeader from '@/Components/Layout/PageHeader';
import { Badge, Button, Card, Field, Select, Table, TBody, Td, Th, THead, Tr } from '@/Components/ui';
import notify from '@/Utils/notify';

const FIELD_LABELS = {
    email: ['Email address', 'required'],
    first_name: ['First name', 'fills {{first_name}}'],
    last_name: ['Last name', 'fills {{last_name}}'],
    name: ['Full name', 'used when first/last are missing'],
    company: ['Company', 'fills {{company}}'],
    subject: ['Subject (ready draft)', 'optional — imports as a ready-to-send draft'],
    body: ['Body (ready draft)', 'optional — Markdown; pairs with subject'],
};

export default function Import({ campaign, fields, max_rows }) {
    const inputRef = useRef();
    const [step, setStep] = useState(1);
    const [busy, setBusy] = useState(false);
    const [fileName, setFileName] = useState('');
    const [preview, setPreview] = useState(null);
    const [mapping, setMapping] = useState({});
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);

    const upload = async (file) => {
        if (!file) return;
        setBusy(true);
        setError(null);
        setFileName(file.name);
        const fd = new FormData();
        fd.append('file', file);
        try {
            const { data } = await window.axios.post(route('campaigns.import.preview', campaign.id), fd, { headers: { 'Content-Type': 'multipart/form-data' } });
            setPreview(data);
            setMapping(data.mapping);
            setStep(2);
        } catch (err) {
            setError(err?.response?.data?.errors?.file?.[0] || err?.response?.data?.message || 'Could not read that file.');
        } finally {
            setBusy(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    };

    const confirm = async () => {
        if (!mapping.email) {
            notify.error('Choose which column holds the email address.');
            return;
        }
        setBusy(true);
        try {
            const { data } = await window.axios.post(route('campaigns.import.store', campaign.id), { token: preview.token, mapping });
            setResult(data);
            setStep(3);
        } catch (err) {
            const errs = err?.response?.data?.errors;
            notify.error((errs && Object.values(errs).flat()[0]) || err?.response?.data?.message || 'Import failed.');
        } finally {
            setBusy(false);
        }
    };

    const usedKeys = Object.values(mapping).filter(Boolean);

    return (
        <AppShell title={`Import · ${campaign.name}`}>
            <PageShell>
                <PageHeader eyebrow={<Link href={route('campaigns.show', campaign.id)} className="hover:underline">{campaign.name}</Link>} title="Import leads" description="Upload a CSV, confirm which column is which, done. Nothing is emailed by importing." />

                <div className="flex items-center gap-3 text-sm">
                    {[['1', 'Upload'], ['2', 'Map columns'], ['3', 'Result']].map(([n, label], i) => (
                        <div key={n} className="flex items-center gap-3">
                            <span className={`inline-flex h-7 items-center gap-2 rounded-full px-3 font-medium ${step === i + 1 ? 'bg-accent text-white' : step > i + 1 ? 'bg-success-soft text-success' : 'bg-surface-2 text-muted'}`}>{n}. {label}</span>
                            {i < 2 && <span className="h-px w-8 bg-line" />}
                        </div>
                    ))}
                </div>

                {step === 1 && (
                    <Card>
                        <div className="grid gap-6 lg:grid-cols-3">
                            <div className="lg:col-span-2">
                                <input ref={inputRef} type="file" accept=".csv,text/csv,.txt" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                                <button type="button" onClick={() => inputRef.current?.click()} disabled={busy}
                                    onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); upload(e.dataTransfer.files?.[0]); }}
                                    className="flex w-full flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-line bg-surface-2/40 px-6 py-14 text-center transition hover:border-accent hover:bg-accent/5">
                                    <span className="flex h-12 w-12 items-center justify-center rounded-full bg-accent/10 text-accent"><Upload className="h-6 w-6" /></span>
                                    <span className="text-base font-medium text-body">{busy ? 'Reading…' : 'Drop a CSV here or click to choose'}</span>
                                    <span className="text-xs text-subtle">Exported from Excel, Google Sheets, Apollo, HubSpot… up to {max_rows} rows. Excel files (.xlsx) must be saved as CSV first.</span>
                                </button>
                                {error && <p className="mt-3 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{error}</p>}
                            </div>
                            <div className="text-sm text-muted">
                                <div className="flex items-center gap-2 font-medium text-body"><FileSpreadsheet className="h-4 w-4" /> Recognised columns</div>
                                <ul className="mt-2 space-y-1.5">
                                    {fields.map((f) => (
                                        <li key={f}><span className="font-medium text-body">{FIELD_LABELS[f][0]}</span> <span className="text-subtle">— {FIELD_LABELS[f][1]}</span></li>
                                    ))}
                                </ul>
                                <p className="mt-3 text-xs text-subtle">Every other column (website, role, notes…) is kept and given to the AI for personalisation, and is available as a template variable.</p>
                            </div>
                        </div>
                    </Card>
                )}

                {step === 2 && preview && (
                    <>
                        <Card title="Map columns" description={`${fileName} · ${preview.row_count} rows · delimiter “${preview.delimiter}” · ${preview.encoding}${preview.skipped_blank ? ` · ${preview.skipped_blank} blank rows skipped` : ''}`}
                            actions={<><Button variant="secondary" onClick={() => setStep(1)}>Different file</Button><Button loading={busy} onClick={confirm}>Import {preview.row_count} leads</Button></>}>
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                {fields.map((f) => (
                                    <Field key={f} label={FIELD_LABELS[f][0]} htmlFor={`map-${f}`} hint={FIELD_LABELS[f][1]} required={f === 'email'}>
                                        <Select id={`map-${f}`} value={mapping[f] || ''} onChange={(e) => setMapping({ ...mapping, [f]: e.target.value || null })}>
                                            <option value="">— not in file —</option>
                                            {preview.headers.map((h) => <option key={h.key} value={h.key}>{h.label}</option>)}
                                        </Select>
                                    </Field>
                                ))}
                            </div>
                        </Card>
                        <Card padded={false} title="First rows" description="Columns not mapped above are kept as extra data.">
                            <Table>
                                <THead>
                                    <Tr>
                                        {preview.headers.map((h) => (
                                            <Th key={h.key}>{h.label}{usedKeys.includes(h.key) && <Badge tone="accent" className="ml-1.5">{Object.keys(mapping).find((k) => mapping[k] === h.key)}</Badge>}</Th>
                                        ))}
                                    </Tr>
                                </THead>
                                <TBody>
                                    {preview.sample.slice(0, 8).map((row, i) => (
                                        <Tr key={i}>
                                            {preview.headers.map((h) => <Td key={h.key} className="max-w-[240px] truncate text-muted" title={row[h.key]}>{row[h.key] || <span className="text-subtle">—</span>}</Td>)}
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </Card>
                    </>
                )}

                {step === 3 && result && (
                    <Card>
                        <div className="flex items-start gap-4">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-success-soft text-success"><CheckCircle2 className="h-6 w-6" /></span>
                            <div className="min-w-0 flex-1">
                                <h2 className="text-lg font-semibold text-body">{result.created} lead{result.created === 1 ? '' : 's'} imported</h2>
                                <dl className="mt-3 grid gap-x-8 gap-y-1.5 text-sm sm:grid-cols-2">
                                    <div className="flex justify-between gap-4"><dt className="text-muted">With ready drafts from the file</dt><dd className="tabular-nums text-body">{result.with_drafts}</dd></div>
                                    <div className="flex justify-between gap-4"><dt className="text-muted">Contacted before (flagged, not blocked)</dt><dd className="tabular-nums text-body">{result.previously_contacted}</dd></div>
                                    <div className="flex justify-between gap-4"><dt className="text-muted">Duplicates inside the file (skipped)</dt><dd className="tabular-nums text-body">{result.duplicates_in_file}</dd></div>
                                    <div className="flex justify-between gap-4"><dt className="text-muted">Rejected rows</dt><dd className={`tabular-nums ${result.rejected ? 'text-danger' : 'text-body'}`}>{result.rejected}</dd></div>
                                    {result.extra_addresses_ignored > 0 && <div className="flex justify-between gap-4 sm:col-span-2"><dt className="text-muted">Cells with several addresses (first one kept)</dt><dd className="tabular-nums text-body">{result.extra_addresses_ignored}</dd></div>}
                                </dl>
                                <div className="mt-5 flex flex-wrap gap-2">
                                    <Button to={result.campaign_url}>Go to campaign & generate drafts</Button>
                                    <Button variant="secondary" onClick={() => { setStep(1); setResult(null); setPreview(null); }}>Import another file</Button>
                                    {result.rejects_url && <Button variant="ghost" icon={<Download className="h-4 w-4" />} href={result.rejects_url}>Download rejected rows</Button>}
                                </div>
                            </div>
                        </div>
                    </Card>
                )}
            </PageShell>
        </AppShell>
    );
}
