import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, BarChart3, CalendarClock, ClipboardList, FileText, LayoutDashboard, ListChecks,
    LogOut, Mail, Menu, Paperclip, PenSquare, Settings as SettingsIcon, ShieldBan, ShieldCheck,
    UserCircle, UsersRound, X,
} from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import ThemeSwitcher from '@/Components/ThemeSwitcher';
import Dropdown from '@/Components/Dropdown';
import useFlash from '@/Utils/useFlash';

const cx = (...a) => a.filter(Boolean).join(' ');

// Ziggy's route() throws on unknown names; every item is gated by has() so the
// shell keeps working while features come and go.
const has = (name) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};
const isActive = (patterns) => patterns.some((p) => route().current(p));

function SideLink({ item, onNavigate }) {
    const active = isActive(item.active);
    const Icon = item.icon;
    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            prefetch
            cacheFor="20s"
            aria-current={active ? 'page' : undefined}
            className={cx(
                'group relative flex items-center gap-3 rounded-xl px-3 py-2 text-[13.5px] font-medium transition',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                active ? 'bg-brand-gradient text-white shadow-md shadow-black/25' : 'text-side-muted hover:bg-side-2 hover:text-side-text'
            )}
        >
            {Icon && <Icon className={cx('h-[17px] w-[17px] shrink-0', active ? 'text-white' : 'text-side-muted group-hover:text-side-text')} />}
            <span className="truncate">{item.label}</span>
            {item.badge > 0 && (
                <span className={cx('ml-auto rounded-full px-1.5 py-0.5 text-[10.5px] font-bold tabular-nums', active ? 'bg-white/20 text-white' : 'bg-side-2 text-side-text')}>
                    {item.badge}
                </span>
            )}
            {item.alert > 0 && <span className="ml-auto h-2 w-2 rounded-full bg-warning" title="Needs attention" />}
        </Link>
    );
}

function SideSection({ label, items, onNavigate }) {
    if (items.length === 0) return null;
    return (
        <div>
            {label && <p className="mb-1.5 mt-5 px-3 text-[10.5px] font-bold uppercase tracking-[0.14em] text-side-muted/70">{label}</p>}
            <div className="space-y-0.5">
                {items.map((item) => <SideLink key={item.route} item={item} onNavigate={onNavigate} />)}
            </div>
        </div>
    );
}

function SidebarBody({ user, app, groups, onNavigate }) {
    return (
        <div className="flex h-full flex-col">
            <Link href={route('dashboard')} onClick={onNavigate} className="flex items-center gap-3 px-4 pb-2 pt-5 focus-visible:outline-none">
                <ApplicationLogo className="h-10 w-10 shrink-0 drop-shadow-lg" />
                <div className="min-w-0">
                    <div className="truncate text-[17px] font-bold tracking-tight text-white">{app.name || 'Outreach'}</div>
                    <div className="truncate text-[11px] text-side-muted">send-only outreach</div>
                </div>
            </Link>

            <nav className="flex-1 overflow-y-auto scroll-side px-3 pb-4 pt-2">
                {groups.map((g) => <SideSection key={g.label || 'main'} label={g.label} items={g.items} onNavigate={onNavigate} />)}
            </nav>

            <div className="border-t border-side-line p-3">
                <div className="mb-2 flex justify-center"><ThemeSwitcher dark /></div>
                <Dropdown>
                    <Dropdown.Trigger>
                        <button type="button" className="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition hover:bg-side-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-gradient text-xs font-bold text-white">
                                {(user?.name || '?').slice(0, 1).toUpperCase()}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold text-side-text">{user?.name}</span>
                                <span className="block truncate text-[11px] text-side-muted">{user?.role_label}</span>
                            </span>
                        </button>
                    </Dropdown.Trigger>
                    <Dropdown.Content align="left" width="56" contentClasses="py-1 bg-surface border border-line">
                        <div className="border-b border-line px-3 py-2.5">
                            <div className="truncate text-sm font-semibold text-body">{user?.name}</div>
                            <div className="truncate text-xs text-subtle">{user?.email}</div>
                        </div>
                        <div className="py-1">
                            <Link href={route('profile.edit')} className="flex w-full items-center gap-2 px-3 py-2 text-sm text-muted transition hover:bg-surface-2 hover:text-body">
                                <UserCircle className="h-4 w-4 shrink-0" /> Profile & password
                            </Link>
                        </div>
                        <div className="border-t border-line py-1">
                            <Link href={route('logout')} method="post" as="button" className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body">
                                <LogOut className="h-4 w-4 shrink-0" /> Log out
                            </Link>
                        </div>
                    </Dropdown.Content>
                </Dropdown>
            </div>
        </div>
    );
}

/**
 * AppShell — fixed dark sidebar (drawer below lg), light content area.
 * Pages own their padding via PageShell.
 */
export default function AppShell({ title, header, children }) {
    useFlash();
    const page = usePage();
    const user = page.props.auth?.user;
    const app = page.props.app || {};
    const isAdmin = !!user?.is_admin;
    const [drawerOpen, setDrawerOpen] = useState(false);

    useEffect(() => router.on('navigate', () => setDrawerOpen(false)), []);
    useEffect(() => {
        document.body.style.overflow = drawerOpen ? 'hidden' : '';
        return () => {
            document.body.style.overflow = '';
        };
    }, [drawerOpen]);

    const reconnect = Number(app.accounts_needing_reconnect || 0);
    const scheduled = Number(app.scheduled_count || 0);

    const groups = [
        {
            label: null,
            items: [
                { label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, active: ['dashboard'] },
                { label: 'Compose', route: 'compose.create', icon: PenSquare, active: ['compose.*'] },
            ].filter((i) => has(i.route)),
        },
        {
            label: 'Outreach',
            items: [
                { label: 'Leads', route: 'leads.index', icon: UsersRound, active: ['leads.index', 'leads.import.*'] },
                { label: 'Campaigns', route: 'campaigns.index', icon: ListChecks, active: ['campaigns.*'] },
                { label: 'Templates', route: 'templates.index', icon: FileText, active: ['templates.*'] },
            ].filter((i) => has(i.route)),
        },
        {
            label: 'Activity',
            items: [
                { label: 'Send log', route: 'log.index', icon: ClipboardList, active: ['log.*'], badge: scheduled },
                { label: 'Reports', route: 'reports.index', icon: BarChart3, active: ['reports.*'] },
            ].filter((i) => has(i.route)),
        },
        {
            label: isAdmin ? 'Admin' : null,
            items: !isAdmin ? [] : [
                { label: 'Email accounts', route: 'admin.accounts.index', icon: Mail, active: ['admin.accounts.*'], alert: reconnect },
                { label: 'Team', route: 'admin.users.index', icon: UserCircle, active: ['admin.users.*'] },
                { label: 'Attachment library', route: 'admin.library.index', icon: Paperclip, active: ['admin.library.*'] },
                { label: 'Do-not-contact', route: 'admin.suppressions.index', icon: ShieldBan, active: ['admin.suppressions.*'] },
                { label: 'Settings', route: 'admin.settings.edit', icon: SettingsIcon, active: ['admin.settings.*'] },
                { label: 'Audit trail', route: 'admin.audit.index', icon: ShieldCheck, active: ['admin.audit.*'] },
            ].filter((i) => has(i.route)),
        },
    ];

    return (
        <div className="min-h-screen bg-app text-body">
            {title && <Head title={title} />}

            {/* Fixed sidebar (lg and up) */}
            <aside className="fixed inset-y-0 left-0 z-40 hidden w-[17rem] bg-side lg:block">
                <SidebarBody user={user} app={app} groups={groups} />
            </aside>

            {/* Drawer (below lg) */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div className="absolute inset-0 bg-black/60" onClick={() => setDrawerOpen(false)} aria-hidden="true" />
                    <div className="absolute inset-y-0 left-0 w-[17rem] max-w-[85vw] bg-side shadow-2xl">
                        <button type="button" onClick={() => setDrawerOpen(false)} aria-label="Close navigation" className="absolute right-3 top-4 z-10 rounded-lg p-1.5 text-side-muted hover:bg-side-2 hover:text-side-text">
                            <X className="h-5 w-5" />
                        </button>
                        <SidebarBody user={user} app={app} groups={groups} onNavigate={() => setDrawerOpen(false)} />
                    </div>
                </div>
            )}

            <div className="lg:pl-[17rem]">
                {/* Slim top bar only below lg (hamburger + brand) */}
                <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line bg-app/85 px-4 backdrop-blur lg:hidden">
                    <button type="button" onClick={() => setDrawerOpen(true)} aria-label="Open navigation" className="-ml-1 rounded-lg p-2 text-muted transition hover:bg-surface-2 hover:text-body">
                        <Menu className="h-5 w-5" />
                    </button>
                    <ApplicationLogo className="h-8 w-8" />
                    <span className="text-[16px] font-bold tracking-tight text-body">{app.name || 'Outreach'}</span>
                </header>

                {isAdmin && reconnect > 0 && has('admin.accounts.index') && (
                    <div className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-2 text-center text-sm text-warning">
                        <AlertTriangle className="h-4 w-4 shrink-0" />
                        <span>{reconnect === 1 ? 'One email account needs' : `${reconnect} email accounts need`} to be reconnected before it can send.</span>
                        <Link href={route('admin.accounts.index')} className="font-semibold underline underline-offset-2">Fix now</Link>
                    </div>
                )}

                <main className="min-h-screen">
                    {header}
                    {children}
                </main>
            </div>
        </div>
    );
}
