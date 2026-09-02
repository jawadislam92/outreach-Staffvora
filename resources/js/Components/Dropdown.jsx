import { Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { createContext, useContext, useState } from 'react';

const DropDownContext = createContext();

const Dropdown = ({ children }) => {
    const [open, setOpen] = useState(false);
    const toggleOpen = () => setOpen((previous) => !previous);

    return (
        <DropDownContext.Provider value={{ open, setOpen, toggleOpen }}>
            <div className="relative">{children}</div>
        </DropDownContext.Provider>
    );
};

const Trigger = ({ children }) => {
    const { open, setOpen, toggleOpen } = useContext(DropDownContext);
    return (
        <>
            <div onClick={toggleOpen}>{children}</div>
            {open && <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />}
        </>
    );
};

const WIDTHS = { 48: 'w-48', 56: 'w-56', 64: 'w-64', 72: 'w-72' };

/**
 * direction="up" opens the menu above the trigger — required when the trigger
 * sits at the bottom of the viewport (the sidebar profile menu), where a
 * downward menu is clipped off-screen.
 */
const Content = ({ align = 'right', direction = 'down', width = '48', contentClasses = 'py-1 bg-surface', children }) => {
    const { open, setOpen } = useContext(DropDownContext);

    const up = direction === 'up';
    let alignmentClasses = up ? 'origin-bottom' : 'origin-top';
    if (align === 'left') alignmentClasses = `${up ? 'origin-bottom-left' : 'origin-top-left'} start-0`;
    else if (align === 'right') alignmentClasses = `${up ? 'origin-bottom-right' : 'origin-top-right'} end-0`;
    const placement = up ? 'bottom-full mb-2' : 'mt-2';

    return (
        <Transition show={open} enter="transition ease-out duration-200" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100" leave="transition ease-in duration-75" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95">
            <div className={`absolute z-50 ${placement} rounded-xl shadow-lg ${alignmentClasses} ${WIDTHS[width] || ''}`} onClick={() => setOpen(false)}>
                <div className={`overflow-hidden rounded-xl ring-1 ring-line ${contentClasses}`}>{children}</div>
            </div>
        </Transition>
    );
};

const DropdownLink = ({ className = '', children, ...props }) => (
    <Link {...props} className={'block w-full px-4 py-2 text-start text-sm leading-5 text-muted transition hover:bg-surface-2 hover:text-body focus:bg-surface-2 focus:outline-none ' + className}>
        {children}
    </Link>
);

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
