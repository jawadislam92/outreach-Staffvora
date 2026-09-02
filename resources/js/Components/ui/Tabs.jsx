import { useRef } from 'react';

const cx = (...a) => a.filter(Boolean).join(' ');

const SIZES = {
    sm: 'px-2.5 py-1 text-xs',
    md: 'px-3 py-1.5 text-sm',
};

/** Segmented control / ARIA tablist. tabs: [{key, label, icon?, count?}] */
export default function Tabs({ tabs = [], value, onChange, size = 'md', className, ...rest }) {
    const btnRefs = useRef([]);

    const move = (from, dir) => {
        if (!tabs.length) return;
        const next = (from + dir + tabs.length) % tabs.length;
        btnRefs.current[next]?.focus();
        onChange?.(tabs[next].key);
    };

    const handleKeyDown = (e, index) => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            move(index, 1);
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            move(index, -1);
        }
    };

    return (
        <div role="tablist" className={cx('inline-flex items-center gap-1 rounded-lg bg-surface-2 p-1 max-w-full overflow-x-auto', className)} {...rest}>
            {tabs.map((tab, index) => {
                const active = tab.key === value;
                return (
                    <button key={tab.key} type="button" role="tab" aria-selected={active} tabIndex={active ? 0 : -1}
                        ref={(el) => (btnRefs.current[index] = el)}
                        onClick={() => onChange?.(tab.key)}
                        onKeyDown={(e) => handleKeyDown(e, index)}
                        className={cx('inline-flex items-center gap-1.5 rounded-md font-medium transition cursor-pointer whitespace-nowrap',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                            SIZES[size] || SIZES.md,
                            active ? 'bg-surface text-body shadow-sm' : 'text-muted hover:text-body')}>
                        {tab.icon ? <span className="inline-flex shrink-0 items-center" aria-hidden="true">{tab.icon}</span> : null}
                        <span>{tab.label}</span>
                        {tab.count != null && (
                            <span className={cx('rounded-full px-1.5 text-[11px] tabular-nums', active ? 'bg-accent/10 text-accent' : 'bg-surface text-subtle')}>{tab.count}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

export const Segmented = Tabs;
