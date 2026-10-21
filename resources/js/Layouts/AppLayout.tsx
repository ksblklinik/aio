import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { 
  LayoutDashboard, 
  Layers, 
  ChevronDown, 
  Zap,
  Menu,
  X,
  LogOut,
  Building2,
  Check,
  AlertCircle,
  CheckCircle2,
  Blocks,
  Package,
  ShieldCheck,
  LifeBuoy,
  History,
  Key,
  Bell,
  CheckCheck
} from 'lucide-react';
import DevPersonaBar from '../Components/DevPersonaBar';
import ThemeToggle from '../Components/ThemeToggle';

interface AuthProp {
  user: { id: number; name: string; email: string; is_superadmin?: boolean } | null;
  tenant: { id: number; name: string; slug: string; plan: string } | null;
  role: string | null;
  is_superadmin?: boolean;
  tenants: Array<{ id: number; name: string; slug: string; role: string }>;
}

interface ActiveModuleNav {
  id: string;
  label: string;
  icon: string;
  href: string;
  category: string;
}

interface AppLayoutProps {
  children: React.ReactNode;
  title?: string;
  tenant?: {
    id: string | number;
    name: string;
    plan: string;
  };
}

const iconRegistry: Record<string, React.ElementType> = {
  LayoutDashboard,
  Layers,
  Blocks,
  Package,
  ShieldCheck,
  LifeBuoy,
  History,
};

export default function AppLayout({ children, title, tenant }: AppLayoutProps) {
  const { props } = usePage<{ 
    auth?: AuthProp; 
    active_modules?: ActiveModuleNav[];
    flash?: { success?: string | null; error?: string | null };
    notifications?: {
      unread_count: number;
      recent: Array<{
        id: number;
        title: string;
        message: string;
        type: string;
        action_url: string | null;
        created_at: string;
        is_read: boolean;
      }>;
    };
  }>();

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);
  const [notifMenuOpen, setNotifMenuOpen] = useState(false);

  const unreadNotifs = props.notifications?.unread_count || 0;
  const recentNotifs = props.notifications?.recent || [];

  const activeTenant = tenant || props.auth?.tenant || {
    id: 1,
    name: 'Acme Corp',
    slug: 'acme',
    plan: 'Enterprise Pro',
  };

  const currentUser = props.auth?.user || {
    id: 1,
    name: 'John Doe',
    email: 'john@acme.com',
  };

  const currentRole = props.auth?.role || 'owner';
  const userTenants = props.auth?.tenants || [];
  const isSuperadmin = !!(props.auth?.is_superadmin || currentUser.is_superadmin);
  const isOwnerOrAdmin = currentRole === 'owner' || currentRole === 'admin' || isSuperadmin;

  // Base navigation
  const baseNavItems = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '/dashboard' },
    { label: 'Aktivitas', icon: History, href: '/activity' },
    { 
      label: 'Notifikasi', 
      icon: Bell, 
      href: '/notifications',
      badge: unreadNotifs > 0 ? unreadNotifs : undefined
    },
  ];

  // Dynamic active module items from backend
  const dynamicNavItems = (props.active_modules || [
    { id: 'projects', label: 'Proyek & Tugas', icon: 'Layers', href: '/projects', category: 'Produktivitas' },
  ]).map((m) => ({
    label: m.label,
    icon: iconRegistry[m.icon] || Package,
    href: m.href,
  }));

  const handleSwitchTenant = (tenantId: number) => {
    setWorkspaceMenuOpen(false);
    router.post('/workspaces/switch', { tenant_id: tenantId });
  };

  const handleLogout = () => {
    router.post('/logout');
  };

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

  return (
    <div className="min-h-screen bg-canvas text-text-main flex flex-col md:flex-row antialiased font-sans transition-colors duration-150">
      {/* Mobile Top Header */}
      <div className="md:hidden bg-surface border-b border-border-subtle px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <div className="h-8 w-8 rounded-lg bg-brand-primary flex items-center justify-center text-white shadow-sm">
            <Zap className="h-4 w-4" />
          </div>
          <span className="font-bold text-text-main text-base">aio SaaS</span>
        </div>
        <div className="flex items-center gap-2">
          <ThemeToggle />
          <button 
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="p-1.5 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
            aria-label="Toggle Navigation"
          >
            {mobileMenuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
          </button>
        </div>
      </div>

      {/* Sidebar for Desktop & Mobile Overlay */}
      <aside className={`
        fixed inset-y-0 left-0 z-40 w-64 bg-surface border-r border-border-subtle flex flex-col justify-between p-4 shadow-sm transition-transform duration-200 md:static md:translate-x-0
        ${mobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}
      `}>
        <div className="space-y-6">
          {/* Logo & Brand Header */}
          <div className="flex items-center gap-3 px-2 py-1">
            <div className="h-9 w-9 rounded-lg bg-brand-primary flex items-center justify-center text-white shadow-sm shrink-0">
              <Zap className="h-5 w-5" />
            </div>
            <div>
              <div className="font-bold text-lg text-text-main tracking-tight leading-tight">
                aio SaaS
              </div>
              <div className="text-[11px] font-semibold text-brand-primary uppercase tracking-wider">
                Unified Framework
              </div>
            </div>
          </div>

          {/* Workspace Switcher Selector */}
          <div className="relative">
            <button
              onClick={() => setWorkspaceMenuOpen(!workspaceMenuOpen)}
              className="w-full flex items-center justify-between p-2.5 rounded-lg bg-surface-subtle border border-border-subtle hover:border-border-main transition-colors text-left"
            >
              <div className="flex items-center gap-2.5 truncate">
                <div className="w-7 h-7 rounded bg-surface border border-border-subtle flex items-center justify-center text-text-muted shadow-2xs">
                  <Building2 className="w-4 h-4" />
                </div>
                <div className="truncate">
                  <div className="text-xs font-bold text-text-main truncate">
                    {activeTenant.name}
                  </div>
                  <div className="text-[10px] text-text-muted font-medium">
                    {activeTenant.plan}
                  </div>
                </div>
              </div>
              <ChevronDown className={`w-4 h-4 text-text-muted transition-transform ${workspaceMenuOpen ? 'rotate-180' : ''}`} />
            </button>

            {workspaceMenuOpen && (
              <div className="absolute top-full left-0 mt-1.5 w-full bg-surface border border-border-subtle rounded-lg shadow-xl p-1.5 z-50 animate-in fade-in duration-100">
                <div className="text-[10px] font-bold uppercase px-2 py-1 text-text-muted tracking-wider">
                  Pilih Workspace
                </div>
                {userTenants.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => handleSwitchTenant(t.id)}
                    className={`w-full text-left px-2.5 py-1.5 rounded-md text-xs font-medium flex items-center justify-between transition-colors ${
                      t.id === activeTenant.id 
                        ? 'bg-brand-primary-light text-brand-primary font-semibold' 
                        : 'text-text-main hover:bg-surface-subtle'
                    }`}
                  >
                    <span className="truncate">{t.name}</span>
                    {t.id === activeTenant.id ? (
                      <Check className="w-3.5 h-3.5 text-brand-primary shrink-0" />
                    ) : (
                      <span className="text-[10px] uppercase text-text-muted">{t.role}</span>
                    )}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Navigation Links */}
          <nav className="space-y-4">
            {/* Menu Utama */}
            <div className="space-y-1">
              <div className="px-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-text-muted">
                Menu Utama
              </div>
              {baseNavItems.map((item) => {
                const isActive = currentPath === item.href;
                const Icon = item.icon;
                return (
                  <Link
                    key={item.label}
                    href={item.href}
                    className={`flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-brand-primary text-white shadow-sm'
                        : 'text-text-muted hover:text-text-main hover:bg-surface-subtle'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <Icon className="h-4 w-4 shrink-0" />
                      <span>{item.label}</span>
                    </div>
                    {item.badge !== undefined && (
                      <span className={`px-1.5 py-0.5 text-[10px] rounded-full font-bold leading-none ${
                        isActive ? 'bg-white/20 text-white' : 'bg-rose-500 text-white'
                      }`}>
                        {item.badge}
                      </span>
                    )}
                  </Link>
                );
              })}
            </div>

            {/* Modul Bisnis Dinamis */}
            {dynamicNavItems.length > 0 && (
              <div className="space-y-1">
                <div className="px-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-text-muted">
                  Modul Aktif
                </div>
                {dynamicNavItems.map((item) => {
                  const isActive = currentPath.startsWith(item.href);
                  const Icon = item.icon;
                  return (
                    <Link
                      key={item.label}
                      href={item.href}
                      className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                        isActive
                          ? 'bg-brand-primary text-white shadow-sm'
                          : 'text-text-muted hover:text-text-main hover:bg-surface-subtle'
                      }`}
                    >
                      <Icon className="h-4 w-4 shrink-0" />
                      <span>{item.label}</span>
                    </Link>
                  );
                })}
              </div>
            )}

            {/* Menu Administrasi Modul (Owner/Admin only) */}
            {isOwnerOrAdmin && (
              <div className="space-y-1 pt-2 border-t border-border-subtle">
                <div className="px-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-text-muted">
                  Sistem & Admin
                </div>
                <Link
                  href="/modules"
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                    currentPath.startsWith('/modules')
                      ? 'bg-brand-primary text-white shadow-sm'
                      : 'text-text-muted hover:text-text-main hover:bg-surface-subtle'
                  }`}
                >
                  <Blocks className="h-4 w-4 shrink-0" />
                  <span>Manajer Modul</span>
                </Link>
                <Link
                  href="/settings/api-tokens"
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                    currentPath.startsWith('/settings/api-tokens')
                      ? 'bg-brand-primary text-white shadow-sm'
                      : 'text-text-muted hover:text-text-main hover:bg-surface-subtle'
                  }`}
                >
                  <Key className="h-4 w-4 shrink-0" />
                  <span>API & Integrasi</span>
                </Link>
              </div>
            )}
          </nav>
        </div>

        {/* User Profile Card in Sidebar Footer */}
        <div className="pt-4 border-t border-border-subtle">
          <div className="p-2.5 rounded-lg bg-surface-subtle border border-border-subtle flex items-center justify-between">
            <div className="flex items-center gap-2.5 truncate">
              <div className="w-8 h-8 rounded-full bg-brand-primary flex items-center justify-center font-bold text-xs text-white uppercase shadow-sm shrink-0">
                {currentUser.name.charAt(0)}
              </div>
              <div className="truncate">
                <div className="text-xs font-bold text-text-main truncate">
                  {currentUser.name}
                </div>
                <div className="text-[11px] text-text-muted font-medium capitalize flex items-center gap-1.5">
                  <span>Peran: {currentRole}</span>
                  {isSuperadmin && (
                    <span className="text-[9px] font-bold uppercase tracking-wider bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-1.5 py-0.2 rounded">
                      Super
                    </span>
                  )}
                </div>
              </div>
            </div>
            <button
              onClick={handleLogout}
              title="Keluar"
              className="p-1.5 rounded-lg text-text-muted hover:text-red-500 hover:bg-red-500/10 transition-colors"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top Navbar */}
        <header className="h-16 border-b border-border-subtle bg-surface px-4 md:px-8 flex items-center justify-between sticky top-0 z-20">
          <h1 className="text-base font-bold text-text-main">
            {title || 'Dashboard'}
          </h1>

          <div className="flex items-center gap-3">
            <ThemeToggle />

            {/* Notification Bell Dropdown */}
            <div className="relative">
              <button
                onClick={() => setNotifMenuOpen(!notifMenuOpen)}
                className="relative p-2 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle transition-colors"
                aria-label="Notifikasi"
              >
                <Bell className="w-4 h-4" />
                {unreadNotifs > 0 && (
                  <span className="absolute top-1.5 right-1.5 flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                  </span>
                )}
              </button>

              {notifMenuOpen && (
                <>
                  <div 
                    className="fixed inset-0 z-30" 
                    onClick={() => setNotifMenuOpen(false)} 
                  />
                  <div className="absolute right-0 mt-2 w-80 sm:w-96 rounded-2xl bg-surface border border-border-subtle shadow-xl z-40 py-2 overflow-hidden animate-in fade-in slide-in-from-top-2 duration-150">
                    <div className="px-4 py-2.5 border-b border-border-subtle flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-bold text-text-main">Notifikasi</span>
                        {unreadNotifs > 0 && (
                          <span className="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-brand-primary text-white">
                            {unreadNotifs}
                          </span>
                        )}
                      </div>
                      {unreadNotifs > 0 && (
                        <button
                          onClick={() => {
                            router.post('/notifications/read-all', {}, { preserveScroll: true });
                          }}
                          className="text-[11px] font-medium text-brand-primary hover:underline flex items-center gap-1"
                        >
                          <CheckCheck className="w-3.5 h-3.5" />
                          <span>Tandai dibaca</span>
                        </button>
                      )}
                    </div>

                    <div className="max-h-72 overflow-y-auto divide-y divide-border-subtle">
                      {recentNotifs.length === 0 ? (
                        <div className="py-8 text-center text-xs text-text-muted">
                          Tidak ada notifikasi baru
                        </div>
                      ) : (
                        recentNotifs.map((n) => (
                          <div
                            key={n.id}
                            onClick={() => {
                              if (!n.is_read) {
                                router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true });
                              }
                              if (n.action_url) {
                                setNotifMenuOpen(false);
                                router.visit(n.action_url);
                              }
                            }}
                            className={`p-3 text-left transition-colors cursor-pointer hover:bg-surface-subtle flex items-start gap-3 ${
                              !n.is_read ? 'bg-surface-subtle/50' : 'opacity-75'
                            }`}
                          >
                            <div className="p-1.5 rounded-md bg-surface border border-border-subtle text-text-muted mt-0.5 shrink-0">
                              <Bell className="w-3.5 h-3.5 text-brand-primary" />
                            </div>
                            <div className="min-w-0 flex-1">
                              <div className="flex items-center justify-between gap-1">
                                <span className={`text-xs text-text-main truncate ${!n.is_read ? 'font-bold' : 'font-medium'}`}>
                                  {n.title}
                                </span>
                                {!n.is_read && (
                                  <span className="w-1.5 h-1.5 rounded-full bg-brand-primary shrink-0" />
                                )}
                              </div>
                              <p className="text-[11px] text-text-muted truncate mt-0.5">
                                {n.message}
                              </p>
                              <span className="text-[10px] text-text-muted mt-1 block">
                                {n.created_at}
                              </span>
                            </div>
                          </div>
                        ))
                      )}
                    </div>

                    <div className="p-2 border-t border-border-subtle bg-surface-subtle text-center">
                      <Link
                        href="/notifications"
                        onClick={() => setNotifMenuOpen(false)}
                        className="text-xs font-semibold text-brand-primary hover:underline"
                      >
                        Lihat Semua Notifikasi &rarr;
                      </Link>
                    </div>
                  </div>
                </>
              )}
            </div>
            {isSuperadmin && (
              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                <ShieldCheck className="w-3.5 h-3.5" />
                <span>Platform Superadmin</span>
              </span>
            )}
            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-primary-light border border-brand-primary/20 text-xs font-medium text-brand-primary">
              <span className="w-2 h-2 rounded-full bg-brand-primary" />
              Tenant: <strong className="font-semibold text-text-main">{activeTenant.name}</strong>
            </span>
            <span className="px-2.5 py-1 rounded-md text-[11px] uppercase font-bold bg-surface-subtle text-text-muted border border-border-subtle">
              {currentRole}
            </span>
          </div>
        </header>

        {/* Flash Notifications */}
        {props.flash?.success && (
          <div className="mx-4 md:mx-8 mt-4 p-3.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-medium flex items-center gap-2.5 shadow-2xs">
            <CheckCircle2 className="w-4 h-4 text-emerald-500 shrink-0" />
            <span>{props.flash.success}</span>
          </div>
        )}
        {props.flash?.error && (
          <div className="mx-4 md:mx-8 mt-4 p-3.5 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-medium flex items-center gap-2.5 shadow-2xs">
            <AlertCircle className="w-4 h-4 text-rose-500 shrink-0" />
            <span>{props.flash.error}</span>
          </div>
        )}

        {/* Page Content Container */}
        <main className="p-4 md:p-8 flex-1">
          {children}
        </main>
      </div>

      {/* Dev Fast Testing Persona Switcher */}
      <DevPersonaBar />
    </div>
  );
}
