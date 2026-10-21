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

export default function AppLayout({ children, title, tenant }: AppLayoutProps) {
  const { props } = usePage<{ 
    auth?: AuthProp; 
    flash?: { success?: string | null; error?: string | null };
  }>();

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);

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
    <div className="min-h-screen bg-slate-50 text-gray-900 flex flex-col md:flex-row antialiased font-sans">
      {/* Mobile Top Header */}
      <div className="md:hidden bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <div className="h-8 w-8 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm">
            <Zap className="h-4 w-4" />
          </div>
          <span className="font-bold text-gray-900 text-base">aio SaaS</span>
        </div>
        <button 
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
          className="p-1.5 rounded-lg text-gray-600 hover:bg-gray-100"
          aria-label="Toggle Navigation"
        >
          {mobileMenuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
        </button>
      </div>

      {/* Sidebar for Desktop & Mobile Overlay */}
      <aside className={`
        fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 flex flex-col justify-between p-4 shadow-sm transition-transform duration-200 md:static md:translate-x-0
        ${mobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}
      `}>
        <div className="space-y-6">
          {/* Logo & Brand Header */}
          <div className="flex items-center gap-3 px-2 py-1">
            <div className="h-9 w-9 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm">
              <Zap className="h-5 w-5" />
            </div>
            <div>
              <div className="font-bold text-lg text-gray-900 tracking-tight leading-tight">
                aio SaaS
              </div>
              <div className="text-[11px] font-semibold text-blue-600 uppercase tracking-wider">
                Unified Framework
              </div>
            </div>
          </div>

          {/* Workspace Switcher Selector */}
          <div className="relative">
            <button
              onClick={() => setWorkspaceMenuOpen(!workspaceMenuOpen)}
              className="w-full flex items-center justify-between p-2.5 rounded-lg bg-gray-50 border border-gray-200 hover:bg-gray-100 transition-colors text-left"
            >
              <div className="flex items-center gap-2.5 truncate">
                <div className="w-7 h-7 rounded bg-white border border-gray-300 flex items-center justify-center text-gray-700 shadow-xs">
                  <Building2 className="w-4 h-4" />
                </div>
                <div className="truncate">
                  <div className="text-xs font-bold text-gray-900 truncate">
                    {activeTenant.name}
                  </div>
                  <div className="text-[10px] text-gray-500 font-medium">
                    {activeTenant.plan}
                  </div>
                </div>
              </div>
              <ChevronDown className={`w-4 h-4 text-gray-400 transition-transform ${workspaceMenuOpen ? 'rotate-180' : ''}`} />
            </button>

            {workspaceMenuOpen && (
              <div className="absolute top-full left-0 mt-1.5 w-full bg-white border border-gray-200 rounded-lg shadow-lg p-1.5 z-50">
                <div className="text-[10px] font-bold uppercase px-2 py-1 text-gray-400 tracking-wider">
                  Pilih Workspace
                </div>
                {userTenants.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => handleSwitchTenant(t.id)}
                    className={`w-full text-left px-2.5 py-1.5 rounded-md text-xs font-medium flex items-center justify-between transition-colors ${
                      t.id === activeTenant.id 
                        ? 'bg-blue-50 text-blue-700 font-semibold' 
                        : 'text-gray-700 hover:bg-gray-100'
                    }`}
                  >
                    <span className="truncate">{t.name}</span>
                    {t.id === activeTenant.id ? (
                      <Check className="w-3.5 h-3.5 text-blue-600 shrink-0" />
                    ) : (
                      <span className="text-[10px] uppercase text-gray-400">{t.role}</span>
                    )}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Navigation Links */}
          <nav className="space-y-1">
            <div className="px-2 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-gray-400">
              Menu Utama
            </div>
            {navItems.map((item) => {
              const isActive = currentPath.startsWith(item.href);
              const Icon = item.icon;
              return (
                <Link
                  key={item.label}
                  href={item.href}
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                    isActive
                      ? 'bg-blue-600 text-white shadow-sm'
                      : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        {/* User Profile Card in Sidebar Footer */}
        <div className="pt-4 border-t border-gray-200">
          <div className="p-2.5 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-between">
            <div className="flex items-center gap-2.5 truncate">
              <div className="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center font-bold text-xs text-white uppercase shadow-sm shrink-0">
                {currentUser.name.charAt(0)}
              </div>
              <div className="truncate">
                <div className="text-xs font-bold text-gray-900 truncate">
                  {currentUser.name}
                </div>
                <div className="text-[11px] text-gray-500 font-medium capitalize">
                  Peran: {currentRole}
                </div>
              </div>
            </div>
            <button
              onClick={handleLogout}
              title="Keluar"
              className="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top Navbar */}
        <header className="h-16 border-b border-gray-200 bg-white px-4 md:px-8 flex items-center justify-between sticky top-0 z-20">
          <h1 className="text-base font-bold text-gray-900">
            {title || 'Dashboard'}
          </h1>

          <div className="flex items-center gap-3">
            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-50 border border-blue-200 text-xs font-medium text-blue-700">
              <span className="w-2 h-2 rounded-full bg-blue-600" />
              Tenant: <strong className="font-semibold text-gray-900">{activeTenant.name}</strong>
            </span>
            <span className="px-2.5 py-1 rounded-md text-[11px] uppercase font-bold bg-gray-100 text-gray-700 border border-gray-200">
              {currentRole}
            </span>
          </div>
        </header>

        {/* Flash Notifications */}
        {props.flash?.success && (
          <div className="mx-4 md:mx-8 mt-4 p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-medium flex items-center gap-2.5 shadow-xs">
            <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
            <span>{props.flash.success}</span>
          </div>
        )}
        {props.flash?.error && (
          <div className="mx-4 md:mx-8 mt-4 p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2.5 shadow-xs">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
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
