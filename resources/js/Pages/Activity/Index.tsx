import { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  History, 
  Search, 
  User, 
  Clock, 
  Globe, 
  FileText, 
  X, 
  Building2,
  ChevronRight
} from 'lucide-react';

interface AuditLog {
  id: number;
  tenant_id: number | null;
  tenant_name?: string | null;
  user_id: number | null;
  user_name: string | null;
  action: string;
  description: string;
  entity_type: string | null;
  entity_id: string | null;
  properties: Record<string, any>;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  badge: {
    color: string;
    label: string;
  };
}

interface ActivityIndexProps {
  logs: AuditLog[];
  totalLogs: number;
  activeScope: 'tenant' | 'global';
  currentTenant: {
    id: number;
    name: string;
    slug: string;
  } | null;
  isSuperadmin: boolean;
}

export default function ActivityIndex({ 
  logs, 
  totalLogs, 
  activeScope, 
  currentTenant, 
  isSuperadmin 
}: ActivityIndexProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [activeDetailLog, setActiveDetailLog] = useState<AuditLog | null>(null);

  const categories = [
    { id: 'all', label: 'Semua Aktivitas' },
    { id: 'auth', label: 'Autentikasi' },
    { id: 'module', label: 'Modul Sistem' },
    { id: 'project', label: 'Proyek' },
    { id: 'task', label: 'Tugas' },
  ];

  const filteredLogs = useMemo(() => {
    return logs.filter((log) => {
      const matchesSearch = 
        log.description.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (log.user_name && log.user_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
        log.action.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (log.entity_type && log.entity_type.toLowerCase().includes(searchQuery.toLowerCase()));

      const matchesCategory = 
        selectedCategory === 'all' || 
        log.action.startsWith(selectedCategory);

      return matchesSearch && matchesCategory;
    });
  }, [logs, searchQuery, selectedCategory]);

  const handleScopeChange = (newScope: 'tenant' | 'global') => {
    router.get('/activity', { scope: newScope }, { preserveState: true });
  };

  const getBadgeStyle = (color: string) => {
    switch (color) {
      case 'indigo':
        return 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20';
      case 'purple':
        return 'bg-purple-500/10 text-purple-400 border-purple-500/20';
      case 'emerald':
        return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
      case 'cyan':
        return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';
      case 'amber':
        return 'bg-amber-500/10 text-amber-400 border-amber-500/20';
      default:
        return 'bg-slate-500/10 text-slate-400 border-slate-500/20';
    }
  };

  return (
    <AppLayout title="Riwayat Aktivitas & Jejak Audit">
      <Head title="Riwayat Aktivitas & Jejak Audit" />

      <div className="space-y-6 max-w-7xl mx-auto pb-12">
        {/* Header Section */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-surface p-6 rounded-2xl border border-border-subtle shadow-sm">
          <div>
            <div className="flex items-center gap-2.5">
              <div className="h-10 w-10 rounded-xl bg-brand-primary/10 flex items-center justify-center text-brand-primary">
                <History className="h-5 w-5" />
              </div>
              <div>
                <h1 className="text-xl font-bold text-text-main tracking-tight">
                  Riwayat Aktivitas & Jejak Audit
                </h1>
                <p className="text-xs text-text-muted mt-0.5">
                  Audit trail transparan untuk akuntabilitas operasional, tata kelola data, dan keamanan workspace.
                </p>
              </div>
            </div>
          </div>

          {/* Scope Selector (Only visible if user is platform superadmin) */}
          {isSuperadmin && (
            <div className="flex items-center bg-surface-subtle p-1 rounded-xl border border-border-subtle shrink-0">
              <button
                type="button"
                onClick={() => handleScopeChange('tenant')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  activeScope === 'tenant'
                    ? 'bg-brand-primary text-white shadow-sm'
                    : 'text-text-muted hover:text-text-main'
                }`}
              >
                Workspace ({currentTenant?.name || 'Aktif'})
              </button>
              <button
                type="button"
                onClick={() => handleScopeChange('global')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  activeScope === 'global'
                    ? 'bg-brand-primary text-white shadow-sm'
                    : 'text-text-muted hover:text-text-main'
                }`}
              >
                Seluruh Platform (Superadmin)
              </button>
            </div>
          )}
        </div>

        {/* Filter & Search Bar */}
        <div className="flex flex-col md:flex-row items-center justify-between gap-3 bg-surface p-4 rounded-xl border border-border-subtle">
          {/* Category Tabs */}
          <div className="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 scrollbar-none">
            {categories.map((cat) => (
              <button
                key={cat.id}
                type="button"
                onClick={() => setSelectedCategory(cat.id)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors ${
                  selectedCategory === cat.id
                    ? 'bg-brand-primary text-white shadow-2xs font-semibold'
                    : 'bg-surface-subtle text-text-muted hover:text-text-main border border-border-subtle'
                }`}
              >
                {cat.label}
              </button>
            ))}
          </div>

          {/* Search Box */}
          <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-text-muted" />
            <input
              type="text"
              placeholder="Cari aktivitas, pelaku, atau rincian..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-3 py-1.5 text-xs bg-surface-subtle text-text-main border border-border-subtle rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary/20 focus:border-brand-primary"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute right-2.5 top-2 text-text-muted hover:text-text-main"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            )}
          </div>
        </div>

        {/* Timeline Feed Container */}
        <div className="bg-surface rounded-2xl border border-border-subtle shadow-sm p-6">
          <div className="flex items-center justify-between pb-4 mb-6 border-b border-border-subtle">
            <div className="text-xs font-bold text-text-muted uppercase tracking-wider">
              {filteredLogs.length} Catatan Ditemukan (Total {totalLogs})
            </div>
            {currentTenant && activeScope === 'tenant' && (
              <div className="flex items-center gap-1.5 text-xs text-text-muted">
                <Building2 className="w-3.5 h-3.5" />
                <span>Workspace: <strong className="text-text-main">{currentTenant.name}</strong></span>
              </div>
            )}
          </div>

          {filteredLogs.length === 0 ? (
            <div className="text-center py-16">
              <div className="h-12 w-12 rounded-full bg-surface-subtle border border-border-subtle flex items-center justify-center mx-auto text-text-muted mb-3">
                <History className="h-6 w-6" />
              </div>
              <h3 className="text-sm font-semibold text-text-main">Belum Ada Aktivitas Terdeteksi</h3>
              <p className="text-xs text-text-muted max-w-sm mx-auto mt-1">
                Aktivitas seperti login, pengelolaan modul, pembuatan proyek, dan tugas akan tercatat secara otomatis di sini.
              </p>
            </div>
          ) : (
            <div className="relative pl-6 space-y-6 before:absolute before:left-2.5 before:top-3 before:bottom-3 before:w-0.5 before:bg-border-subtle">
              {filteredLogs.map((log) => (
                <div key={log.id} className="relative group">
                  {/* Timeline Dot Indicator */}
                  <div className="absolute -left-[19px] top-1.5 h-3.5 w-3.5 rounded-full border-2 border-surface bg-brand-primary shadow-2xs group-hover:scale-125 transition-transform" />

                  <div className="bg-surface-subtle hover:bg-surface-subtle/80 border border-border-subtle rounded-xl p-4 transition-all">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div className="flex items-center gap-2 flex-wrap">
                        {/* Category Badge */}
                        <span className={`px-2 py-0.5 rounded-md text-[11px] font-semibold border ${getBadgeStyle(log.badge?.color || 'slate')}`}>
                          {log.badge?.label || 'Sistem'}
                        </span>

                        <span className="text-xs font-mono text-text-muted">
                          {log.action}
                        </span>

                        {activeScope === 'global' && log.tenant_name && (
                          <span className="px-2 py-0.5 rounded-md text-[10px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
                            {log.tenant_name}
                          </span>
                        )}
                      </div>

                      {/* Timestamp */}
                      <div className="flex items-center gap-1.5 text-xs text-text-muted shrink-0">
                        <Clock className="w-3.5 h-3.5" />
                        <span>{log.created_at}</span>
                      </div>
                    </div>

                    {/* Description */}
                    <div className="mt-2 text-sm font-medium text-text-main">
                      {log.description}
                    </div>

                    {/* Metadata Footer */}
                    <div className="mt-3 pt-3 border-t border-border-subtle/60 flex flex-wrap items-center justify-between gap-2 text-xs text-text-muted">
                      <div className="flex items-center gap-4">
                        <div className="flex items-center gap-1.5">
                          <User className="w-3.5 h-3.5 text-text-muted" />
                          <span className="font-semibold text-text-main">
                            {log.user_name || 'System / Automasi'}
                          </span>
                        </div>

                        {log.ip_address && (
                          <div className="flex items-center gap-1.5 hidden sm:flex">
                            <Globe className="w-3.5 h-3.5" />
                            <span>{log.ip_address}</span>
                          </div>
                        )}
                      </div>

                      {/* Detail Button */}
                      {(log.properties && Object.keys(log.properties).length > 0) || log.user_agent ? (
                        <button
                          type="button"
                          onClick={() => setActiveDetailLog(log)}
                          className="inline-flex items-center gap-1 text-xs font-semibold text-brand-primary hover:text-brand-primary-hover transition-colors"
                        >
                          <span>Rincian Payload</span>
                          <ChevronRight className="w-3.5 h-3.5" />
                        </button>
                      ) : null}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Detail Modal / Inspector */}
      {activeDetailLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="bg-surface border border-border-subtle rounded-2xl max-w-lg w-full p-6 shadow-2xl relative">
            <button
              type="button"
              onClick={() => setActiveDetailLog(null)}
              className="absolute top-4 right-4 p-1.5 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
            >
              <X className="w-4 h-4" />
            </button>

            <div className="flex items-center gap-2.5 pb-4 border-b border-border-subtle">
              <div className="p-2 rounded-lg bg-brand-primary/10 text-brand-primary">
                <FileText className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-bold text-text-main">Rincian Catatan Audit</h3>
                <p className="text-xs text-text-muted">ID Log #{activeDetailLog.id} • {activeDetailLog.action}</p>
              </div>
            </div>

            <div className="mt-4 space-y-4 text-xs">
              <div className="grid grid-cols-2 gap-3 bg-surface-subtle p-3 rounded-xl border border-border-subtle">
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold">Pelaku:</span>
                  <span className="font-semibold text-text-main">{activeDetailLog.user_name || 'System'}</span>
                </div>
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold">Waktu:</span>
                  <span className="font-semibold text-text-main">{activeDetailLog.created_at}</span>
                </div>
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold">IP Address:</span>
                  <span className="font-mono text-text-main">{activeDetailLog.ip_address || '-'}</span>
                </div>
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold">Entitas:</span>
                  <span className="font-mono text-text-main">{activeDetailLog.entity_type || '-'} #{activeDetailLog.entity_id || '-'}</span>
                </div>
              </div>

              {activeDetailLog.user_agent && (
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold mb-1">User Agent Browser:</span>
                  <div className="p-2.5 rounded-lg bg-surface-subtle border border-border-subtle text-text-muted font-mono text-[11px] break-all">
                    {activeDetailLog.user_agent}
                  </div>
                </div>
              )}

              {activeDetailLog.properties && Object.keys(activeDetailLog.properties).length > 0 && (
                <div>
                  <span className="text-text-muted block text-[10px] uppercase font-bold mb-1">Payload Properties (JSON):</span>
                  <pre className="p-3 rounded-lg bg-[#0d131f] text-emerald-400 font-mono text-[11px] overflow-auto max-h-48 border border-slate-800">
                    {JSON.stringify(activeDetailLog.properties, null, 2)}
                  </pre>
                </div>
              )}
            </div>

            <div className="mt-6 flex justify-end">
              <button
                type="button"
                onClick={() => setActiveDetailLog(null)}
                className="px-4 py-2 bg-surface-subtle hover:bg-border-subtle text-text-main font-semibold text-xs rounded-lg transition-colors"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
