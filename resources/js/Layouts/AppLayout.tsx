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
  CheckCircle2
} from 'lucide-react';
import DevPersonaBar from '../Components/DevPersonaBar';

interface AuthProp {
  user: { id: number; name: string; email: string } | null;
  tenant: { id: number; name: string; slug: string; plan: string } | null;
  role: string | null;
  tenants: Array<{ id: number; name: string; slug: string; role: string }>;
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

export default function AppLayout({ children, title }: AppLayoutProps) {
  const { props } = usePage<{ 
    auth?: AuthProp; 
    flash?: { success?: string | null; error?: string | null };
    csrf_token?: string;
  }>();

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);

  const activeTenant = props.auth?.tenant || {
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

  const navItems = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '/dashboard' },
    { label: 'Proyek & Tugas', icon: Layers, href: '/projects' },
  ];

  const handleSwitchTenant = (tenantId: number) => {
    setWorkspaceMenuOpen(false);
    router.post('/workspaces/switch', { tenant_id: tenantId });
  };

  const handleLogout = () => {
    router.post('/logout');
  };

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row antialiased font-sans selection:bg-indigo-500 selection:text-white">
      {/* Sidebar for Desktop */}
      <aside className="hidden md:flex flex-col w-64 border-r border-slate-800/80 bg-slate-900/40 backdrop-blur-xl p-4 justify-between z-30">
        <div className="space-y-6">
          {/* Logo & Brand */}
          <div className="flex items-center gap-3 px-2">
            <div className="h-10 w-10 rounded-xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg shadow-indigo-500/25">
              <Zap className="h-5 w-5 text-white font-black" />
            </div>
            <div>
              <div className="font-extrabold text-lg tracking-tight bg-gradient-to-r from-white via-slate-100 to-slate-400 bg-clip-text text-transparent">
                aio SaaS
              </div>
              <div className="text-[10px] uppercase font-mono tracking-widest text-indigo-400 font-semibold">
                Unified Framework
              </div>
            </div>
          </div>

          {/* Workspace Switcher Selector */}
          <div className="relative">
            <button
              onClick={() => setWorkspaceMenuOpen(!workspaceMenuOpen)}
              className="w-full flex items-center justify-between p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 hover:border-slate-700 transition-all text-left group"
            >
              <div className="flex items-center gap-2.5 truncate">
                <div className="w-7 h-7 rounded-lg bg-indigo-950/80 border border-indigo-500/30 flex items-center justify-center text-indigo-300 font-bold text-xs">
                  <Building2 className="w-3.5 h-3.5" />
                </div>
                <div className="truncate">
                  <div className="text-xs font-semibold text-slate-200 group-hover:text-white truncate">
                    {activeTenant.name}
                  </div>
                  <div className="text-[10px] text-slate-400 font-mono">
                    {activeTenant.plan}
                  </div>
                </div>
              </div>
              <ChevronDown className={`w-3.5 h-3.5 text-slate-400 transition-transform ${workspaceMenuOpen ? 'rotate-180' : ''}`} />
            </button>

            {workspaceMenuOpen && (
              <div className="absolute top-full left-0 mt-1.5 w-full bg-slate-900 border border-slate-700 rounded-xl shadow-xl p-1.5 z-40">
                <div className="text-[10px] uppercase font-mono px-2 py-1 text-slate-400 font-semibold">
                  Pilih Workspace
                </div>
                {userTenants.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => handleSwitchTenant(t.id)}
                    className="w-full text-left px-2 py-1.5 rounded-lg text-xs hover:bg-slate-800 flex items-center justify-between transition-colors"
                  >
                    <span className="truncate">{t.name}</span>
                    {t.id === activeTenant.id ? (
                      <Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                    ) : (
                      <span className="text-[10px] uppercase font-mono text-slate-400">{t.role}</span>
                    )}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Navigation Links */}
          <nav className="space-y-1">
            <div className="px-2 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400 font-mono">
              Menu Utama
            </div>
            {navItems.map((item) => {
              const isActive = currentPath.startsWith(item.href);
              const Icon = item.icon;
              return (
                <Link
                  key={item.label}
                  href={item.href}
                  className={`flex items-center gap-3 px-3 py-2 rounded-xl text-xs font-medium transition-all ${
                    isActive
                      ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                      : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/60'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        {/* User Card in Bottom Sidebar */}
        <div className="pt-4 border-t border-slate-800/80">
          <div className="p-2.5 rounded-xl bg-slate-950/60 border border-slate-800/80 flex items-center justify-between">
            <div className="flex items-center gap-2.5 truncate">
              <div className="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-500 to-pink-500 flex items-center justify-center font-bold text-xs text-white uppercase shadow-inner">
                {currentUser.name.charAt(0)}
              </div>
              <div className="truncate">
                <div className="text-xs font-semibold text-slate-200 truncate">
                  {currentUser.name}
                </div>
                <div className="text-[10px] text-slate-400 font-mono uppercase tracking-wider text-emerald-400 font-semibold">
                  Role: {currentRole}
                </div>
              </div>
            </div>
            <button
              onClick={handleLogout}
              title="Keluar"
              className="p-1.5 rounded-lg hover:bg-red-500/20 hover:text-red-300 text-slate-400 transition-colors"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top Navbar */}
        <header className="h-16 border-b border-slate-800/80 bg-slate-900/30 backdrop-blur-xl px-4 md:px-8 flex items-center justify-between sticky top-0 z-20">
          <div className="flex items-center gap-3">
            <button 
              className="md:hidden p-2 rounded-lg text-slate-400 hover:bg-slate-800"
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            >
              {mobileMenuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>
            <h1 className="text-sm font-semibold text-slate-200">
              {title || 'Dashboard'}
            </h1>
          </div>

          <div className="flex items-center gap-3">
            <span className="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700/60 text-[11px] font-mono text-slate-300">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
              Tenant: <strong className="text-white">{activeTenant.name}</strong>
            </span>
            <span className="px-2 py-0.5 rounded-md text-[10px] uppercase font-mono font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
              {currentRole}
            </span>
          </div>
        </header>

        {/* Flash Notifications */}
        {props.flash?.success && (
          <div className="mx-4 md:mx-8 mt-4 p-3 rounded-xl bg-emerald-950/60 border border-emerald-500/40 text-emerald-200 text-xs flex items-center gap-2.5 shadow-lg shadow-emerald-950/50">
            <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
            <span>{props.flash.success}</span>
          </div>
        )}
        {props.flash?.error && (
          <div className="mx-4 md:mx-8 mt-4 p-3 rounded-xl bg-rose-950/60 border border-rose-500/40 text-rose-200 text-xs flex items-center gap-2.5 shadow-lg shadow-rose-950/50">
            <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" />
            <span>{props.flash.error}</span>
          </div>
        )}

        {/* Page Content */}
        <main className="p-4 md:p-8 flex-1">
          {children}
        </main>
      </div>

      {/* Dev Fast Testing Persona Switcher */}
      <DevPersonaBar />
    </div>
  );
}
