import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  Receipt,
  DollarSign,
  TrendingUp,
  Clock,
  CheckCircle2,
  AlertCircle,
  FileText,
  Printer,
  Plus,
  ShoppingCart
} from 'lucide-react';

interface Invoice {
  id: number;
  invoice_number: string;
  type: string;
  contact_name: string;
  status: string;
  payment_status: string;
  issue_date: string;
  due_date: string | null;
  currency: string;
  grand_total: number;
  amount_paid: number;
  amount_due: number;
}

interface BillingSummary {
  total_billed: number;
  total_received: number;
  total_outstanding: number;
  count_invoices: number;
  count_paid: number;
  count_unpaid: number;
}

interface BillingIndexProps {
  invoices: Invoice[];
  summary: BillingSummary;
  filters: {
    type?: string | null;
    status?: string | null;
    payment_status?: string | null;
    search?: string | null;
  };
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Index({ invoices, summary, filters }: BillingIndexProps) {
  const [searchTerm, setSearchTerm] = useState(filters?.search || '');
  const [selectedFilter, setSelectedFilter] = useState(filters?.status || 'all');

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(val);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3" /> Lunas
          </span>
        );
      case 'partially_paid':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-500 border border-amber-500/20">
            <Clock className="w-3 h-3" /> Sebagian
          </span>
        );
      case 'issued':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-500 border border-blue-500/20">
            <FileText className="w-3 h-3" /> Terbit
          </span>
        );
      case 'draft':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-zinc-500/10 text-zinc-400 border border-zinc-500/20">
            Draft
          </span>
        );
      case 'cancelled':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-500/10 text-rose-500 border border-rose-500/20">
            <AlertCircle className="w-3 h-3" /> Dibatalkan
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-surface text-text-muted">
            {status}
          </span>
        );
    }
  };

  return (
    <AppLayout title="Billing & Invoicing">
      <div className="space-y-6">
        {/* Header Title & Actions */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-text-main flex items-center gap-2">
              <Receipt className="w-6 h-6 text-brand-primary" />
              Billing & Invoicing
            </h1>
            <p className="text-sm text-text-muted mt-1">
              Universal faktur penjualan, tagihan pembelian, kasir cepat, dan pelacakan piutang.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <a
              href="/billing/pos-checkout"
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-sm transition-colors shadow-xs"
            >
              <ShoppingCart className="w-4 h-4" />
              Kasir Cepat (POS)
            </a>
            <a
              href="/billing/create"
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-primary hover:bg-brand-primary/90 text-white font-medium text-sm transition-colors shadow-xs"
            >
              <Plus className="w-4 h-4" />
              Buat Faktur Baru
            </a>
          </div>
        </div>

        {/* 4 Summary Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="p-5 rounded-xl bg-surface border border-border-subtle shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-text-muted uppercase tracking-wider">Total Ditagihkan</span>
              <div className="h-9 w-9 rounded-lg bg-brand-primary-light border border-brand-primary/20 flex items-center justify-center text-brand-primary">
                <Receipt className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-4">
              <span className="text-2xl font-bold tracking-tight text-text-main">
                {formatCurrency(summary?.total_billed || 0)}
              </span>
              <p className="text-xs text-text-muted mt-1">{summary?.count_invoices || 0} Total Dokumen Faktur</p>
            </div>
          </div>

          <div className="p-5 rounded-xl bg-surface border border-border-subtle shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-text-muted uppercase tracking-wider">Total Diterima / Kas Masuk</span>
              <div className="h-9 w-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500">
                <DollarSign className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-4">
              <span className="text-2xl font-bold tracking-tight text-emerald-500">
                {formatCurrency(summary?.total_received || 0)}
              </span>
              <p className="text-xs text-text-muted mt-1">{summary?.count_paid || 0} Faktur Telah Lunas</p>
            </div>
          </div>

          <div className="p-5 rounded-xl bg-surface border border-border-subtle shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-text-muted uppercase tracking-wider">Sisa Piutang / Outstanding</span>
              <div className="h-9 w-9 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-500">
                <TrendingUp className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-4">
              <span className="text-2xl font-bold tracking-tight text-amber-500">
                {formatCurrency(summary?.total_outstanding || 0)}
              </span>
              <p className="text-xs text-text-muted mt-1">{summary?.count_unpaid || 0} Faktur Belum Lunas</p>
            </div>
          </div>

          <div className="p-5 rounded-xl bg-surface border border-border-subtle shadow-xs">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-text-muted uppercase tracking-wider">Tingkat Kolektibilitas</span>
              <div className="h-9 w-9 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-500">
                <CheckCircle2 className="h-4 w-4" />
              </div>
            </div>
            <div className="mt-4">
              <span className="text-2xl font-bold tracking-tight text-text-main">
                {summary?.total_billed > 0
                  ? Math.round((summary.total_received / summary.total_billed) * 100)
                  : 100}
                %
              </span>
              <p className="text-xs text-text-muted mt-1">Realisasi pelunasan tagihan</p>
            </div>
          </div>
        </div>

        {/* Filters and Table Section */}
        <div className="p-6 rounded-xl bg-surface border border-border-subtle shadow-xs">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div className="flex items-center gap-2">
              <input
                type="text"
                placeholder="Cari nomor invoice atau pelanggan..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="px-3 py-2 text-sm rounded-lg bg-background border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary w-64"
              />
            </div>
            <div className="flex items-center gap-2">
              {['all', 'issued', 'partially_paid', 'paid', 'draft'].map((status) => (
                <button
                  key={status}
                  onClick={() => setSelectedFilter(status)}
                  className={`px-3 py-1.5 text-xs font-medium rounded-lg transition-colors ${
                    selectedFilter === status
                      ? 'bg-brand-primary text-white'
                      : 'bg-background hover:bg-border-subtle text-text-muted'
                  }`}
                >
                  {status.toUpperCase()}
                </button>
              ))}
            </div>
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="text-xs uppercase bg-background text-text-muted border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3">No. Faktur</th>
                  <th className="px-4 py-3">Tanggal</th>
                  <th className="px-4 py-3">Kontak / Pelanggan</th>
                  <th className="px-4 py-3">Grand Total</th>
                  <th className="px-4 py-3">Sisa Tagihan</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {invoices && invoices.length > 0 ? (
                  invoices.map((inv) => (
                    <tr key={inv.id} className="hover:bg-background/50 transition-colors">
                      <td className="px-4 py-3 font-semibold text-text-main">{inv.invoice_number}</td>
                      <td className="px-4 py-3 text-text-muted">{inv.issue_date}</td>
                      <td className="px-4 py-3 font-medium text-text-main">{inv.contact_name}</td>
                      <td className="px-4 py-3 font-semibold text-text-main">{formatCurrency(inv.grand_total)}</td>
                      <td className="px-4 py-3 font-medium text-amber-500">
                        {inv.amount_due > 0 ? formatCurrency(inv.amount_due) : '-'}
                      </td>
                      <td className="px-4 py-3">{getStatusBadge(inv.status)}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="inline-flex items-center gap-2">
                          <a
                            href={`/billing/${inv.id}/receipt`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="p-1.5 rounded-lg bg-surface hover:bg-border-subtle text-text-muted hover:text-text-main transition-colors"
                            title="Cetak Struk"
                          >
                            <Printer className="w-4 h-4" />
                          </a>
                          <a
                            href={`/billing/${inv.id}`}
                            className="px-2.5 py-1 text-xs font-medium rounded bg-brand-primary-light text-brand-primary hover:bg-brand-primary/20 transition-colors"
                          >
                            Detail
                          </a>
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-text-muted">
                      Belum ada transaksi faktur yang terdaftar.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
