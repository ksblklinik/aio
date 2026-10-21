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
    <div className="p-5 rounded-xl bg-surface border border-border-subtle shadow-xs hover:border-border-main transition-all">
      <div className="flex items-center justify-between">
        <span className="text-xs font-semibold text-text-muted uppercase tracking-wider">{title}</span>
        <div className="h-9 w-9 rounded-lg bg-brand-primary-light border border-brand-primary/20 flex items-center justify-center text-brand-primary">
          <Icon className="h-4 w-4" />
        </div>
      </div>
      <div className="mt-4 flex items-baseline justify-between">
        <span className="text-2xl font-bold tracking-tight text-text-main">{value}</span>
        <span className={`text-xs font-semibold flex items-center gap-1 ${isPositive ? 'text-emerald-500' : 'text-rose-500'}`}>
          <TrendingUp className={`h-3.5 w-3.5 ${!isPositive ? 'rotate-180' : ''}`} />
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
        <div className="p-6 rounded-xl bg-surface border border-border-subtle shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-brand-primary-light text-brand-primary border border-brand-primary/20 mb-2.5">
              <ShieldCheck className="h-4 w-4" />
              <span>Multi-Tenant Context: Scoped Isolation Aktif</span>
            </div>
            <h2 className="text-xl font-bold text-text-main tracking-tight">
              Selamat Datang di Portal {tenant?.name || 'Acme Corp'}
            </h2>
            <p className="text-xs text-text-muted mt-1">
              Data tabel diisolasi secara deterministik dengan ID <code className="text-brand-primary bg-brand-primary-light px-1.5 py-0.5 rounded font-mono font-semibold border border-brand-primary/20">{tenant?.id || 'tenant-demo'}</code>
            </p>
          </div>

          <div className="flex items-center gap-3">
            <Link 
              href="/projects" 
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-xs font-semibold bg-brand-primary hover:bg-brand-primary-hover text-white transition-all shadow-sm active:scale-95"
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
        <div className="rounded-xl bg-surface border border-border-subtle shadow-xs overflow-hidden">
          <div className="p-5 border-b border-border-subtle flex items-center justify-between bg-surface">
            <div>
              <h3 className="text-sm font-bold text-text-main flex items-center gap-2">
                <Layers className="h-4 w-4 text-brand-primary" />
                Daftar Proyek Tenant
              </h3>
              <p className="text-xs text-text-muted mt-0.5">
                Query dieksekusi melalui <code className="text-text-main font-mono bg-surface-subtle px-1 rounded">Core\Database\TenantScope</code>
              </p>
            </div>
            <span className="text-xs text-text-muted font-mono bg-surface-subtle px-3 py-1 rounded-md border border-border-subtle font-medium">
              Total {defaultProjects.length} Proyek
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs text-text-main">
              <thead className="bg-surface-subtle text-[11px] uppercase tracking-wider text-text-muted font-semibold border-b border-border-subtle">
                <tr>
                  <th className="py-3 px-5">Nama Proyek</th>
                  <th className="py-3 px-5">Status</th>
                  <th className="py-3 px-5">Total Data Records</th>
                  <th className="py-3 px-5">Pembaruan Terakhir</th>
                  <th className="py-3 px-5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {defaultProjects.map((p) => (
                  <tr key={p.id} className="hover:bg-surface-subtle transition-colors">
                    <td className="py-3.5 px-5 font-semibold text-text-main flex items-center gap-2">
                      <div className="h-2 w-2 rounded-full bg-brand-primary"></div>
                      {p.name}
                    </td>
                    <td className="py-3.5 px-5">
                      <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold ${
                        p.status === 'Active' 
                          ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' 
                          : p.status === 'Processing'
                          ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'
                          : 'bg-surface-subtle text-text-muted border border-border-subtle'
                      }`}>
                        {p.status === 'Active' && <CheckCircle className="h-3 w-3 text-emerald-500" />}
                        {p.status}
                      </span>
                    </td>
                    <td className="py-3.5 px-5 font-mono text-text-muted">
                      {p.recordsCount.toLocaleString()} baris
                    </td>
                    <td className="py-3.5 px-5 text-text-muted">
                      {p.updatedAt}
                    </td>
                    <td className="py-3.5 px-5 text-right">
                      <button className="text-brand-primary hover:text-brand-primary-hover transition-colors inline-flex items-center gap-1 font-semibold">
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
