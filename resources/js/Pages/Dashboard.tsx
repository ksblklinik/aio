import React from 'react';
import { Link } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';
import { 
  TrendingUp, 
  Users, 
  Layers, 
  DollarSign, 
  Activity, 
  Plus, 
  CheckCircle, 
  Clock, 
  ExternalLink,
  ShieldCheck
} from 'lucide-react';

interface MetricCardProps {
  title: string;
  value: string;
  change: string;
  isPositive: boolean;
  icon: React.ElementType;
}

function MetricCard({ title, value, change, isPositive, icon: Icon }: MetricCardProps) {
  return (
    <div className="p-5 rounded-2xl bg-slate-900/60 border border-slate-800/80 backdrop-blur-sm hover:border-slate-700/80 transition-all duration-200">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-slate-400">{title}</span>
        <div className="h-8 w-8 rounded-lg bg-slate-800/80 border border-slate-700/60 flex items-center justify-center text-slate-300">
          <Icon className="h-4 w-4 text-emerald-400" />
        </div>
      </div>
      <div className="mt-4 flex items-baseline justify-between">
        <span className="text-2xl font-bold tracking-tight text-white">{value}</span>
        <span className={`text-xs font-semibold flex items-center gap-1 ${isPositive ? 'text-emerald-400' : 'text-rose-400'}`}>
          <TrendingUp className={`h-3 w-3 ${!isPositive && 'rotate-180'}`} />
          {change}
        </span>
      </div>
    </div>
  );
}

interface DashboardProps {
  tenant: {
    id: string;
    name: string;
    plan: string;
  };
  metrics?: {
    mrr: string;
    activeUsers: string;
    apiRequests: string;
    avgLatency: string;
  };
  projects?: Array<{
    id: string;
    name: string;
    status: string;
    recordsCount: number;
    updatedAt: string;
  }>;
}

export default function Dashboard({ tenant, metrics, projects }: DashboardProps) {
  const defaultMetrics = metrics || {
    mrr: '$14,250',
    activeUsers: '1,420',
    apiRequests: '8.4M req',
    avgLatency: '1.2 ms',
  };

  const defaultProjects = projects || [
    { id: '1', name: 'Billing Microservice Core', status: 'Active', recordsCount: 14200, updatedAt: '5 menit yang lalu' },
    { id: '2', name: 'AI Voice Automation Agent', status: 'Processing', recordsCount: 4890, updatedAt: '12 menit yang lalu' },
    { id: '3', name: 'Customer Churn Predictor', status: 'Active', recordsCount: 92300, updatedAt: '1 jam yang lalu' },
    { id: '4', name: 'Realtime Telemetry Pipeline', status: 'Paused', recordsCount: 1540, updatedAt: '3 jam yang lalu' },
  ];

  return (
    <AppLayout title="Dashboard SaaS" tenant={tenant}>
      <div className="space-y-6 max-w-7xl mx-auto">
        {/* Welcome & Tenant Banner */}
        <div className="p-6 rounded-2xl bg-gradient-to-r from-emerald-950/40 via-slate-900/60 to-slate-900/60 border border-emerald-500/20 backdrop-blur-md flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 mb-2">
              <ShieldCheck className="h-3.5 w-3.5" />
              <span>Multi-Tenant Context: Scoped Isolation Aktif</span>
            </div>
            <h2 className="text-xl font-bold text-white">
              Selamat Datang di Portal {tenant?.name || 'Acme Corp'}
            </h2>
            <p className="text-xs text-slate-400 mt-1">
              Data tabel diisolasi secara deterministik dengan ID <code className="text-emerald-400 bg-slate-800 px-1 py-0.5 rounded font-mono">{tenant?.id || 'tenant-demo'}</code>
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link 
              href="/projects" 
              className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition-all shadow-lg shadow-emerald-500/20 active:scale-95"
            >
              <Plus className="h-4 w-4" />
              <span>Kelola Proyek & Tugas</span>
            </Link>
          </div>
        </div>

        {/* Metric Cards Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <MetricCard
            title="Monthly Recurring Revenue"
            value={defaultMetrics.mrr}
            change="+18.2%"
            isPositive={true}
            icon={DollarSign}
          />
          <MetricCard
            title="Active SaaS Members"
            value={defaultMetrics.activeUsers}
            change="+9.4%"
            isPositive={true}
            icon={Users}
          />
          <MetricCard
            title="SaaS API Volume (30 Hari)"
            value={defaultMetrics.apiRequests}
            change="+34.1%"
            isPositive={true}
            icon={Activity}
          />
          <MetricCard
            title="Worker Mode Response Time"
            value={defaultMetrics.avgLatency}
            change="-42%"
            isPositive={true}
            icon={Clock}
          />
        </div>

        {/* Data Table: Scoped Tenant Projects */}
        <div className="rounded-2xl bg-slate-900/60 border border-slate-800/80 overflow-hidden backdrop-blur-sm">
          <div className="p-5 border-b border-slate-800/80 flex items-center justify-between">
            <div>
              <h3 className="text-sm font-semibold text-white flex items-center gap-2">
                <Layers className="h-4 w-4 text-emerald-400" />
                Daftar Proyek Tenant
              </h3>
              <p className="text-xs text-slate-400 mt-0.5">
                Query dieksekusi melalui <code className="text-slate-300 font-mono">Core\Database\TenantScope</code>
              </p>
            </div>
            <span className="text-xs text-slate-400 font-mono bg-slate-800/60 px-2.5 py-1 rounded-lg border border-slate-700/50">
              Total {defaultProjects.length} Proyek
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs text-slate-300">
              <thead className="bg-slate-950/50 text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
                <tr>
                  <th className="py-3 px-5">Nama Proyek</th>
                  <th className="py-3 px-5">Status</th>
                  <th className="py-3 px-5">Total Data Records</th>
                  <th className="py-3 px-5">Pembaruan Terakhir</th>
                  <th className="py-3 px-5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {defaultProjects.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-800/30 transition-colors">
                    <td className="py-3.5 px-5 font-medium text-white flex items-center gap-2">
                      <div className="h-2 w-2 rounded-full bg-emerald-400"></div>
                      {p.name}
                    </td>
                    <td className="py-3.5 px-5">
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium ${
                        p.status === 'Active' 
                          ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' 
                          : p.status === 'Processing'
                          ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                          : 'bg-slate-800 text-slate-400 border border-slate-700'
                      }`}>
                        {p.status === 'Active' && <CheckCircle className="h-3 w-3" />}
                        {p.status}
                      </span>
                    </td>
                    <td className="py-3.5 px-5 font-mono text-slate-300">
                      {p.recordsCount.toLocaleString()} baris
                    </td>
                    <td className="py-3.5 px-5 text-slate-400">
                      {p.updatedAt}
                    </td>
                    <td className="py-3.5 px-5 text-right">
                      <button className="text-slate-400 hover:text-emerald-400 transition-colors inline-flex items-center gap-1 font-medium">
                        <span>Detail</span>
                        <ExternalLink className="h-3 w-3" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
