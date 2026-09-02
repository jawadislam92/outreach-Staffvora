import Modal from '@/Components/Modal';
import Button from './Button';

/** Small confirm/cancel dialog on top of the Breeze Modal. */
export default function ConfirmDialog({ open, title, description, confirmLabel = 'Confirm', tone = 'primary', loading = false, onConfirm, onClose, children }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="p-6 bg-surface text-body rounded-lg">
                {title && <h2 className="text-lg font-semibold">{title}</h2>}
                {description && <p className="mt-2 text-sm text-muted">{description}</p>}
                {children}
                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose} disabled={loading}>Cancel</Button>
                    <Button variant={tone} onClick={onConfirm} loading={loading}>{confirmLabel}</Button>
                </div>
            </div>
        </Modal>
    );
}
