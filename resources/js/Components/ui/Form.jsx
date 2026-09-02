import { Children, cloneElement, isValidElement, useId } from 'react';

const cx = (...a) => a.filter(Boolean).join(' ');

const controlBase =
    'w-full rounded-lg bg-surface-2 border border-line text-body placeholder:text-subtle px-3 py-2 text-sm transition focus:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/40 disabled:opacity-50 disabled:cursor-not-allowed';

/** Labelled wrapper for a control; wires aria-describedby / aria-invalid onto a single child. */
export function Field({ label, hint, error, required, htmlFor, children, className, ...rest }) {
    const reactId = useId();
    const errorId = `${reactId}-error`;
    const hintId = `${reactId}-hint`;
    const hasError = error != null && error !== false && error !== '';
    const showHint = !hasError && hint != null;
    const describedBy = [hasError ? errorId : null, showHint ? hintId : null].filter(Boolean).join(' ') || undefined;

    let control = children;
    const onlyChild = Children.count(children) === 1 ? Children.only(children) : null;
    if (isValidElement(onlyChild)) {
        control = cloneElement(onlyChild, {
            'aria-invalid': hasError ? true : onlyChild.props['aria-invalid'],
            'aria-describedby': [onlyChild.props['aria-describedby'], describedBy].filter(Boolean).join(' ') || undefined,
        });
    }

    return (
        <div className={cx('w-full', className)} {...rest}>
            {label != null && (
                <label htmlFor={htmlFor} className="block text-sm font-medium text-body mb-1.5">
                    {label}
                    {required && <span className="text-danger ml-0.5" aria-hidden="true">*</span>}
                </label>
            )}
            {control}
            {hasError ? (
                <p id={errorId} role="alert" className="text-xs text-danger mt-1">{error}</p>
            ) : (
                showHint && <p id={hintId} className="text-xs text-subtle mt-1">{hint}</p>
            )}
        </div>
    );
}

export function Input({ type = 'text', className, ...rest }) {
    return <input type={type} className={cx(controlBase, className)} {...rest} />;
}

export function Select({ children, className, ...rest }) {
    return <select className={cx(controlBase, 'pr-8 cursor-pointer', className)} {...rest}>{children}</select>;
}

export function Textarea({ rows = 4, className, ...rest }) {
    return <textarea rows={rows} className={cx(controlBase, 'min-h-[6rem] resize-y', className)} {...rest} />;
}

export function Checkbox({ label, id, className, ...rest }) {
    const autoId = useId();
    const fieldId = id || autoId;
    return (
        <label htmlFor={fieldId} className={cx('inline-flex items-center gap-2 cursor-pointer text-sm text-body select-none', rest.disabled && 'opacity-50 cursor-not-allowed', className)}>
            <input id={fieldId} type="checkbox"
                className="h-4 w-4 rounded border-line text-accent bg-surface-2 transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed"
                {...rest} />
            {label != null && <span>{label}</span>}
        </label>
    );
}

export function Toggle({ checked = false, onChange, label, disabled, id, className, ...rest }) {
    const autoId = useId();
    const fieldId = id || autoId;
    const labelId = `${fieldId}-label`;
    return (
        <label htmlFor={fieldId} className={cx('inline-flex items-center gap-2.5 text-sm text-body select-none', disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer', className)}>
            <button id={fieldId} type="button" role="switch" aria-checked={checked} aria-labelledby={label != null ? labelId : undefined} disabled={disabled}
                onClick={() => onChange && onChange(!checked)}
                className={cx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-line transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed', checked ? 'bg-accent' : 'bg-surface-2')}
                {...rest}>
                <span className={cx('inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition', checked ? 'translate-x-5' : 'translate-x-0.5')} />
            </button>
            {label != null && <span id={labelId}>{label}</span>}
        </label>
    );
}

export default Field;
