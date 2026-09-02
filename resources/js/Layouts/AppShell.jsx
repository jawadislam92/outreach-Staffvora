import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, BarChart3, ChevronDown, ClipboardList, FileText, LayoutDashboard, ListChecks, LogOut,
    Mail, Menu, Paperclip, PenSquare, Settings as SettingsIcon, ShieldBan, ShieldCheck, UserCircle, Users, X,
} from 'lucide-react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import ThemeSwitcher from '@/Components/ThemeSwitcher';
import useFlash from '@/Utils/useFlash';

const cx = (...a) => a.filter(Boolean).join(' ');

// Ziggy's route() throws on unknown names; every nav item is gated by has()
// so the shell keeps working while later phases add their routes.
const has = (name) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};
const isActive = (patterns) => patterns.some((p) => route().current(p));

function NavLink({ item }) {
    const active = isActive(item.active);
    return (
        <Link href={route(item.route)} prefetch cacheFor="20s" aria-current={active ? 'page' : undefined}
            className={cx(
                'relative inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium transition',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                active
                    ? "text-body after:absolute after:inset-x-3 after:-bottom-[13px] after:h-0.5 after:rounded-full after:bg-accent after:content-['']"
                    : 'text-muted hover:bg-surface-2 hover:text-body'
            )}>
            {item.label}
        </Link>
    );
}

function MenuItem({ icon: Icon, children, ...props }) {
    return (
        <Link {...props} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body focus-visible:bg-surface-2 focus-visible:text-body focus-visible:outline-none">
            {Icon && <Icon className="h-4 w-4 shrink-0" />}
            <span className="truncate">{children}</span>
        </Link>
    );
}

/**
 * AppShell — sticky top bar with brand, primary nav, an Admin menu (admins
 * only), theme switch and the account menu. Below `lg` the nav folds into a
 * drawer. The main area is full-bleed; pages own their own padding.
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

    const primary = [
        { label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, active: ['dashboard'] },
        { label: 'Compose', route: 'compose.create', icon: PenSquare, active: ['compose.*'] },
        { label: 'Campaigns', route: 'campaigns.index', icon: ListChecks, active: ['campaigns.*', 'leads.*'] },
        { label: 'Log', route: 'log.index', icon: ClipboardList, active: ['log.*'] },
        { label: 'Reports', route: 'reports.index', icon: BarChart3, active: ['reports.*'] },
        { label: 'Templates', route: 'templates.index', icon: FileText, active: ['templates.*'] },
    ].filter((i) => has(i.route));

    const admin = [
        { label: 'Email accounts', route: 'admin.accounts.index', icon: Mail, active: ['admin.accounts.*'] },
        { label: 'Team', route: 'admin.users.index', icon: Users, active: ['admin.users.*'] },
        { label: 'Attachment library', route: 'admin.library.index', icon: Paperclip, active: ['admin.library.*'] },
        { label: 'Suppressions', route: 'admin.suppressions.index', icon: ShieldBan, active: ['admin.suppressions.*'] },
        { label: 'Settings', route: 'admin.settings.edit', icon: SettingsIcon, active: ['admin.settings.*'] },
        { label: 'Audit trail', route: 'admin.audit.index', icon: ShieldCheck, active: ['admin.audit.*'] },
    ].filter((i) => isAdmin && has(i.route));

    const adminActive = admin.some((i) => isActive(i.active));
    const reconnect = Number(app.accounts_needing_reconnect || 0);

    return (
        <div className="min-h-screen bg-app text-body">
            {title && <Head title={title} />}

            <header className="sticky top-0 z-40 border-b border-line bg-app/85 backdrop-blur supports-[backdrop-filter]:bg-app/75">
                <div className="flex h-16 w-full items-center gap-2 px-4 sm:px-6 lg:px-8">
                    <button type="button" onClick={() => setDrawerOpen(true)} aria-label="Open navigation"
                        className="-ml-1 rounded-lg p-2 text-muted transition hover:bg-surface-2 hover:text-body lg:hidden">
                        <Menu className="h-5 w-5" />
                    </button>

                    <Link href={route('dashboard')} aria-label={app.name} className="flex shrink-0 items-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                        <ApplicationLogo className="h-9 w-9" />
                        <span className="hidden text-[17px] font-bold tracking-tight text-body sm:block">{app.name || 'Outreach'}</span>
                    </Link>

                    <nav className="ml-2 hidden min-w-0 items-center gap-0.5 lg:flex">
                        {primary.map((item) => <NavLink key={item.route} item={item} />)}
                        {admin.length > 0 && (
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button type="button"
                                        className={cx('relative flex items-center gap-1 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                            adminActive ? "text-body after:absolute after:inset-x-3 after:-bottom-[13px] after:h-0.5 after:rounded-full after:bg-accent after:content-['']" : 'text-muted hover:bg-surface-2 hover:text-body')}>
                                        Admin
                                        {reconnect > 0 && <span className="ml-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-warning px-1 text-[10px] font-bold text-white">{reconnect}</span>}
                                        <ChevronDown className="h-3.5 w-3.5" />
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content align="left" width="56" contentClasses="py-1 bg-surface border border-line">
                                    {admin.map((item) => (
                                        <MenuItem key={item.route} href={route(item.route)} icon={item.icon}>{item.label}</MenuItem>
                                    ))}
                                </Dropdown.Content>
                            </Dropdown>
                        )}
                    </nav>

                    <div className="flex-1" />

                    <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                        <div className="hidden sm:block"><ThemeSwitcher /></div>
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button type="button" aria-label="Account menu"
                                    className="flex items-center gap-2 rounded-full border border-line bg-surface py-0.5 pl-0.5 pr-2 transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-accent text-xs font-bold text-white">
                                        {(user?.name || '?').slice(0, 1).toUpperCase()}
                                    </span>
                                    <span className="hidden max-w-[140px] truncate text-sm font-medium text-body sm:block">{user?.name}</span>
                                    <ChevronDown className="hidden h-4 w-4 text-subtle sm:block" />
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content align="right" width="56" contentClasses="py-1 bg-surface border border-line">
                                <div className="border-b border-line px-3 py-2.5">
                                    <div className="truncate text-sm font-semibold text-body">{user?.name}</div>
                                    <div className="truncate text-xs text-subtle">{user?.email}</div>
                                    <div className="mt-1 text-[11px] uppercase tracking-wide text-accent">{user?.role_label}</div>
                                </div>
                                <div className="py-1">
                                    <MenuItem href={route('profile.edit')} icon={UserCircle}>Profile & password</MenuItem>
                                </div>
                                <div className="border-t border-line px-3 py-2 sm:hidden"><ThemeSwitcher /></div>
                                <div className="border-t border-line py-1">
                                    <Link href={route('logout')} method="post" as="button" className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted transition hover:bg-surface-2 hover:text-body">
                                        <LogOut className="h-4 w-4 shrink-0" /> Log out
                                    </Link>
                                </div>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </div>
            </header>

            {isAdmin && reconnect > 0 && has('admin.accounts.index') && (
                <div className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-2 text-center text-sm text-warning">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    <span>
                        {reconnect === 1 ? 'One email account needs' : `${reconnect} email accounts need`} to be reconnected before it can send.
                    </span>
                    <Link href={route('admin.accounts.index')} className="font-semibold underline underline-offset-2">Fix now</Link>
                </div>
            )}

            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div className="absolute inset-0 bg-black/60" onClick={() => setDrawerOpen(false)} aria-hidden="true" />
                    <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-line bg-surface shadow-xl">
                        <div className="flex h-16 shrink-0 items-center justify-between border-b border-line px-4">
                            <span className="flex items-center gap-2.5">
                                <ApplicationLogo className="h-9 w-9" />
                                <span className="text-[16px] font-bold tracking-tight text-body">{app.name || 'Outreach'}</span>
                            </span>
                            <button type="button" onClick={() => setDrawerOpen(false)} aria-label="Close navigation" className="rounded-lg p-1.5 text-muted hover:bg-surface-2 hover:text-body">
                                <X className="h-5 w-5" />
                            </button>
                        </div>
                        <nav className="flex-1 space-y-0.5 overflow-y-auto p-3">
                            {[...primary, ...admin].map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.active);
                                return (
                                    <Link key={item.route} href={route(item.route)} onClick={() => setDrawerOpen(false)}
                                        className={cx('flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition', active ? 'bg-accent text-white' : 'text-muted hover:bg-surface-2 hover:text-body')}>
                                        {Icon && <Icon className="h-[18px] w-[18px] shrink-0" />}
                                        <span className="truncate">{item.label}</span>
                                    </Link>
                                );
                            })}
                        </nav>
                        <div className="shrink-0 border-t border-line p-3"><ThemeSwitcher /></div>
                    </div>
                </div>
            )}

            <main className="min-h-[calc(100vh-4rem)]">
                {header}
                {children}
            </main>
        </div>
    );
}
