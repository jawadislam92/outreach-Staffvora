import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Sun, Moon, MoonStar } from 'lucide-react';

const THEMES = [
    { key: 'light', label: 'Light', icon: Sun },
    { key: 'dark', label: 'Dark', icon: Moon },
    { key: 'black', label: 'Black', icon: MoonStar },
];

/** Light / Dark / Black. Applies instantly and persists in a cookie the server reads on first paint. */
export default function ThemeSwitcher() {
    const [active, setActive] = useState(usePage().props?.theme || 'light');

    const pick = (theme) => {
        setActive(theme);
        document.documentElement.dataset.theme = theme;
        document.cookie = `outreach_theme=${theme};path=/;max-age=31536000;samesite=lax`;
    };

    return (
        <div className="inline-flex items-center gap-0.5 rounded-lg border border-line bg-surface p-0.5">
            {THEMES.map(({ key, label, icon: Icon }) => (
                <button key={key} type="button" onClick={() => pick(key)} title={`${label} theme`} aria-pressed={active === key}
                    className={`flex h-7 w-7 items-center justify-center rounded-md transition ${active === key ? 'bg-accent text-white' : 'text-muted hover:bg-surface-2 hover:text-body'}`}>
                    <Icon className="h-4 w-4" />
                </button>
            ))}
        </div>
    );
}
