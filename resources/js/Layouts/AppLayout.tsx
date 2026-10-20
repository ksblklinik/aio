import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { 
  LayoutDashboard, 
  Layers, 
  Users, 
  CreditCard, 
  Settings, 
  ChevronDown, 
  Bell, 
  Terminal, 
  Zap,
  Menu,
  X
} from 'lucide-react';

interface AppLayoutProps {
  children: React.ReactNode;
  title?: string;
  tenant?: {
    id: string;
    name: string;
    plan: string;
  };
}

export default function AppLayout({ children, title, tenant }: AppLayoutProps) {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const activeTenant = tenant || {
    id: 'tenant-demo',
    name: 'Acme SaaS Corp',
    plan: 'Enterprise Tier',
  };

  const navItems = [
    { label: 'Dashboard', icon: LayoutDashboard, href: '/dashboard', active: true },
    { label: 'Proyek Multi-Tenant', icon: Layers, href: '#', active: false },
    { label: 'Pengguna & Tim', icon: Users, href: '#', active: false },
    { label: 'Billing & Kuota', icon: CreditCard, href: '#', active: false },
    { label: 'Konfigurasi Sistem', icon: Settings, href: '#', active: false },
  ];

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row antialiased">
      {/* Sidebar for Desktop */}
      <aside className="hidden md:flex flex-col w-64 border-r border-slate-800/80 bg-slate-900/50 backdrop-blur-xl p-4 justify-between">
        <div className="space-y-6">
          {/* Logo & Brand */}
          <div className="flex items-center gap-3 px-2">
            <div className="h-10 w-10 rounded-xl bg-gradient-to-tr from-emerald-500 via-teal-400 to-cyan-500 flex items-center justify-center shadow-lg shadow-emerald-500/20">
              <Zap className="h-5 w-5 text-slate-950 font-black" />
            </div>
            <div>
              <div className="font-extrabold text-lg tracking-tight bg-gradient-to-r from-white via-slate-200 to-slate-400 bg-clip-text text-transparent">
                aio
              </div>
              <div className="text-[10px] uppercase font-mono tracking-widest text-emerald-400 font-semibold">
                SaaS Framework
              </div>
            </div>
          </div>

          {/* Tenant Switcher Card */}
          <div className="p-2.5 rounded-xl bg-slate-800/40 border border-slate-700/50 hover:border-slate-600/80 transition-colors cursor-pointer group">
            <div className="flex items-center justify-between">
              <div className="truncate">
                <p className="text-xs text-slate-400 font-medium">Tenant Aktif</p>
                <p className="text-sm font-semibold text-slate-200 truncate group-hover:text-emerald-400 transition-colors">
                  {activeTenant.name}
                </p>
              </div>
              <ChevronDown className="h-4 w-4 text-slate-400 group-hover:text-white transition-transform" />
            </div>
            <div className="mt-2 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
              <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              {activeTenant.plan}
            </div>
          </div>

          {/* Navigation Links */}
          <nav className="space-y-1">
            {navItems.map((item, idx) => {
              const Icon = item.icon;
              return (
                <Link
                  key={idx}
                  href={item.href}
                  className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-200 ${
                    item.active
                      ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 shadow-sm'
                      : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/50'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        {/* Bottom System Health & Console */}
        <div className="p-3 rounded-xl bg-slate-950/70 border border-slate-800/80 space-y-2">
          <div className="flex items-center justify-between text-xs text-slate-400">
            <span className="flex items-center gap-1.5">
              <Terminal className="h-3.5 w-3.5 text-emerald-400" />
              Runtime PHP 8.3
            </span>
            <span className="text-[10px] font-mono text-emerald-400 bg-emerald-950/80 px-1.5 py-0.5 rounded">
              FrankenPHP Ready
            </span>
          </div>
          <div className="text-[11px] text-slate-400">
            Protokol State: <strong className="text-slate-300">Inertia.js v2</strong>
          </div>
        </div>
      </aside>

      {/* Mobile Header */}
      <div className="md:hidden flex items-center justify-between p-4 border-b border-slate-800 bg-slate-900/80 backdrop-blur-md">
        <div className="flex items-center gap-2">
          <div className="h-8 w-8 rounded-lg bg-emerald-500 flex items-center justify-center font-bold text-slate-950">
            <Zap className="h-4 w-4" />
          </div>
          <span className="font-bold text-slate-100">aio</span>
        </div>
        <button
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
          className="p-2 text-slate-400 hover:text-slate-100"
        >
          {mobileMenuOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
        </button>
      </div>

      {/* Main Container */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top Navbar */}
        <header className="h-16 border-b border-slate-800/80 bg-slate-900/40 backdrop-blur-lg flex items-center justify-between px-6">
          <div className="flex items-center gap-3">
            <h1 className="text-base font-semibold text-slate-100">
              {title || 'Dashboard'}
            </h1>
          </div>

          <div className="flex items-center gap-4">
            <button className="relative p-2 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800/60 transition-colors">
              <Bell className="h-4 w-4" />
              <span className="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-emerald-500 ring-2 ring-slate-900"></span>
            </button>

            <div className="h-5 w-px bg-slate-800"></div>

            <div className="flex items-center gap-2.5">
              <div className="h-8 w-8 rounded-full bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-xs font-bold text-white shadow">
                JD
              </div>
              <div className="hidden sm:block text-left">
                <p className="text-xs font-medium text-slate-200">John Developer</p>
                <p className="text-[10px] text-slate-400 font-mono">admin@tenant.local</p>
              </div>
            </div>
          </div>
        </header>

        {/* Content Area */}
        <main className="flex-1 p-6 overflow-y-auto">
          {children}
        </main>
      </div>
    </div>
  );
}
