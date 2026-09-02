import { useMemo, useState } from 'react';
import { CalendarClock } from 'lucide-react';
import Modal from '@/Components/Modal';
import { Button, Field, Input } from '@/Components/ui';

const pad = (n) => String(n).padStart(2, '0');
const toInputValue = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

function quickOptions() {
    const now = new Date();
    const inHours = (h) => {
        const d = new Date(now.getTime() + h * 3600 * 1000);
        d.setMinutes(0, 0, 0);
        return d;
    };
    const at = (dayOffset, hour) => {
        const d = new Date(now);
        d.setDate(d.getDate() + dayOffset);
        d.setHours(hour, 0, 0, 0);
        return d;
    };
    const nextMonday = () => {
        const d = new Date(now);
        d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7));
        d.setHours(9, 0, 0, 0);
        return d;
    };
    return [
        { label: 'In 1 hour', value: inHours(1) },
        { label: 'Tomorrow 9:00', value: at(1, 9) },
        { label: 'Tomorrow 14:00', value: at(1, 14) },
        { label: 'Monday 9:00', value: nextMonday() },
    ];
}

/**
 * Pick a future send time. Returns the "YYYY-MM-DDTHH:mm" string via onConfirm;
 * the server interprets it in the business timezone.
 */
export default function ScheduleModal({ open, onClose, onConfirm, timezone, processing = false, error }) {
    const quick = useMemo(quickOptions, [open]);
    const [value, setValue] = useState(() => toInputValue(quick[1].value));

    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-gradient text-white"><CalendarClock className="h-5 w-5" /></span>
                    <div>
                        <h2 className="text-lg font-semibold text-body">Schedule this email</h2>
                        <p className="text-sm text-muted">It goes out automatically — caps and warnings still apply at send time.</p>
                    </div>
                </div>

                <div className="mt-5 flex flex-wrap gap-2">
                    {quick.map((q) => (
                        <button key={q.label} type="button" onClick={() => setValue(toInputValue(q.value))}
                            className={`rounded-full border px-3 py-1.5 text-xs font-medium transition ${toInputValue(q.value) === value ? 'border-accent bg-accent/10 text-accent' : 'border-line bg-surface text-muted hover:text-body'}`}>
                            {q.label}
                        </button>
                    ))}
                </div>

                <div className="mt-4">
                    <Field label={`Date & time (${timezone})`} htmlFor="sched-at" error={error}>
                        <Input id="sched-at" type="datetime-local" value={value} min={toInputValue(new Date())} onChange={(e) => setValue(e.target.value)} />
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose} disabled={processing}>Cancel</Button>
                    <Button icon={<CalendarClock className="h-4 w-4" />} loading={processing} onClick={() => onConfirm(value)}>Schedule</Button>
                </div>
            </div>
        </Modal>
    );
}
