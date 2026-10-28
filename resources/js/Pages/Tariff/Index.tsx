import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  BadgePercent,
  Plus,
  Search,
  DollarSign,
  Trash2,
  Settings2,
  X,
  CreditCard,
  Layers
} from 'lucide-react';

interface PriceBook {
  id: number;
  code: string;
  name: string;
  description: string | null;
  payer_contact_id: number | null;
  payer_name?: string | null;
  currency: string;
  is_active: boolean | number;
  is_default: boolean | number;
  valid_from: string | null;
  valid_until: string | null;
}

interface TariffRate {
  id: number;
  price_book_id: number;
  item_id: number;
  item_name?: string;
  item_code?: string;
  item_type?: string;
  room_type: string | null;
  base_rate: number;
  min_qty: number;
  coverage_type: string;
  coverage_amount: number | null;
  patient_copay: number;
}

interface SplitRule {
  id: number;
  item_id: number | null;
  item_name?: string | null;
  price_book_id: number | null;
  price_book_name?: string | null;
  name: string;
  recipient_type: string;
  default_beneficiary_contact_id: number | null;
  beneficiary_name?: string | null;
  split_type: string;
  split_value: number;
  target_account_code: string | null;
}

interface FeeAllocation {
  id: number;
  invoice_id: number;
  invoice_number?: string;
  item_id: number;
  item_name?: string;
  beneficiary_contact_id: number | null;
  beneficiary_name?: string | null;
  component_name: string;
  amount: number;
  status: string;
  settlement_reference: string | null;
  created_at: string;
}

interface ItemOption {
  id: number;
  code: string;
  name: string;
  type: string;
  selling_price: number;
}

interface ContactOption {
  id: number;
  code: string;
  name: string;
  type: string;
}

interface TariffIndexProps {
  priceBooks: PriceBook[];
  selectedBookId: number | null;
  rates: TariffRate[];
  splitRules: SplitRule[];
  allocations: FeeAllocation[];
  items: ItemOption[];
  contacts: ContactOption[];
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Index({
  priceBooks = [],
  selectedBookId,
  rates = [],
  splitRules = [],
  allocations = [],
  items = [],
  contacts = [],
}: TariffIndexProps) {
  const [activeTab, setActiveTab] = useState<'rates' | 'splits' | 'settlement'>('rates');
  const [currentBookId, setCurrentBookId] = useState<number | null>(selectedBookId);
  const [searchQuery, setSearchQuery] = useState('');

  // Modals
  const [showPriceBookModal, setShowPriceBookModal] = useState(false);
  const [showRateModal, setShowRateModal] = useState(false);
  const [showSplitRuleModal, setShowSplitRuleModal] = useState(false);
  const [showSettleModal, setShowSettleModal] = useState(false);

  // Settle Selection
  const [selectedBeneficiaryId, setSelectedBeneficiaryId] = useState<number | ''>('');

  const activeBook = priceBooks.find((b) => b.id === currentBookId) || priceBooks[0];

  const filteredRates = rates.filter((r) => {
    return (
      (r.item_name || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
      (r.item_code || '').toLowerCase().includes(searchQuery.toLowerCase())
    );
  });

  const filteredAllocations = allocations.filter((a) => {
    if (selectedBeneficiaryId !== '' && a.beneficiary_contact_id !== Number(selectedBeneficiaryId)) {
      return false;
    }
    return true;
  });

  const accruedAllocations = filteredAllocations.filter((a) => a.status === 'accrued');
  const totalAccruedAmount = accruedAllocations.reduce((sum, a) => sum + Number(a.amount), 0);

  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val);
  };

  return (
    <AppLayout title="Universal Tariffs & Split Fee Engine">
      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 rounded-xl bg-brand-primary/10 text-brand-primary">
                <BadgePercent className="w-6 h-6" />
              </span>
              <h1 className="text-2xl font-bold tracking-tight text-text-main">
                Manajemen Tarif & Bagi Hasil
              </h1>
            </div>
            <p className="text-sm text-text-muted mt-1">
              Buku tarif multi-penjamin (Umum, BPJS, Asuransi), tarif berjenjang per kelas fasilitas, dan kalkulasi split fee jasa mitra.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <button
              onClick={() => setShowPriceBookModal(true)}
              className="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-main hover:bg-surface-subtle transition shadow-sm"
            >
              <Plus className="w-4 h-4 text-brand-primary" />
              Buku Tarif Baru
            </button>
            {activeTab === 'rates' && (
              <button
                onClick={() => setShowRateModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition shadow-sm"
              >
                <Plus className="w-4 h-4" />
                Tambah Tarif Item
              </button>
            )}
            {activeTab === 'splits' && (
              <button
                onClick={() => setShowSplitRuleModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition shadow-sm"
              >
                <Plus className="w-4 h-4" />
                Tambah Aturan Split
              </button>
            )}
            {activeTab === 'settlement' && accruedAllocations.length > 0 && (
              <button
                onClick={() => setShowSettleModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition shadow-sm"
              >
                <CreditCard className="w-4 h-4" />
                Pencairan / Settle Fee
              </button>
            )}
          </div>
        </div>

        {/* Navigation Tabs */}
        <div className="border-b border-border-subtle flex gap-6 text-sm font-medium">
          <button
            onClick={() => setActiveTab('rates')}
            className={`pb-3 flex items-center gap-2 transition border-b-2 ${
              activeTab === 'rates'
                ? 'border-brand-primary text-brand-primary font-semibold'
                : 'border-transparent text-text-muted hover:text-text-main'
            }`}
          >
            <Layers className="w-4 h-4" />
            Matriks Buku Tarif ({rates.length})
          </button>
          <button
            onClick={() => setActiveTab('splits')}
            className={`pb-3 flex items-center gap-2 transition border-b-2 ${
              activeTab === 'splits'
                ? 'border-brand-primary text-brand-primary font-semibold'
                : 'border-transparent text-text-muted hover:text-text-main'
            }`}
          >
            <Settings2 className="w-4 h-4" />
            Aturan Bagi Hasil / Split Rules ({splitRules.length})
          </button>
          <button
            onClick={() => setActiveTab('settlement')}
            className={`pb-3 flex items-center gap-2 transition border-b-2 ${
              activeTab === 'settlement'
                ? 'border-brand-primary text-brand-primary font-semibold'
                : 'border-transparent text-text-muted hover:text-text-main'
            }`}
          >
            <DollarSign className="w-4 h-4" />
            Pencairan & Alokasi Fee ({allocations.length})
          </button>
        </div>

        {/* TAB 1: RATES MATRIX */}
        {activeTab === 'rates' && (
          <div className="space-y-6">
            {/* Price Books Bar */}
            <div className="flex items-center gap-3 overflow-x-auto pb-2">
              <span className="text-xs font-semibold uppercase tracking-wider text-text-muted shrink-0">Buku Tarif:</span>
              {priceBooks.map((book) => {
                const isSelected = activeBook && activeBook.id === book.id;
                return (
                  <a
                    key={book.id}
                    href={`/tariff?price_book_id=${book.id}`}
                    onClick={(e) => {
                      e.preventDefault();
                      setCurrentBookId(book.id);
                      window.history.pushState({}, '', `/tariff?price_book_id=${book.id}`);
                    }}
                    className={`px-3.5 py-1.5 rounded-lg text-sm font-medium transition flex items-center gap-2 shrink-0 border ${
                      isSelected
                        ? 'bg-brand-primary text-white border-brand-primary shadow-sm'
                        : 'bg-surface text-text-main border-border-subtle hover:bg-surface-subtle'
                    }`}
                  >
                    <span>{book.name}</span>
                    {Boolean(book.is_default) && (
                      <span className={`text-[10px] px-1.5 py-0.5 rounded ${isSelected ? 'bg-white/20 text-white' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'}`}>
                        Default
                      </span>
                    )}
                  </a>
                );
              })}
            </div>

            {/* Rates Table Card */}
            <div className="bg-surface rounded-xl border border-border-subtle shadow-sm overflow-hidden">
              <div className="p-4 border-b border-border-subtle flex flex-col sm:flex-row items-center justify-between gap-3">
                <div className="flex items-center gap-2 w-full sm:w-auto">
                  <div className="relative w-full sm:w-72">
                    <Search className="w-4 h-4 text-text-muted absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      placeholder="Cari item atau kode..."
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      className="w-full pl-9 pr-3 py-1.5 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:outline-none focus:ring-1 focus:ring-brand-primary"
                    />
                  </div>
                </div>

                <div className="text-xs text-text-muted">
                  Buku Aktif: <strong className="text-text-main">{activeBook?.name || 'Belum dipilih'}</strong> ({activeBook?.code})
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="bg-surface-subtle text-text-muted text-xs uppercase border-b border-border-subtle">
                    <tr>
                      <th className="px-4 py-3">Kode / Item</th>
                      <th className="px-4 py-3">Kelas / Ruang</th>
                      <th className="px-4 py-3">Tarif Dasar</th>
                      <th className="px-4 py-3">Tanggungan Penjamin</th>
                      <th className="px-4 py-3">Co-Pay Pasien</th>
                      <th className="px-4 py-3 text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {filteredRates.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-text-muted">
                          Belum ada tarif khusus pada buku tarif ini. Sistem akan otomatis menggunakan harga standar item.
                        </td>
                      </tr>
                    ) : (
                      filteredRates.map((rate) => (
                        <tr key={rate.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="px-4 py-3">
                            <div className="font-medium text-text-main">{rate.item_name}</div>
                            <div className="text-xs text-text-muted font-mono">{rate.item_code}</div>
                          </td>
                          <td className="px-4 py-3">
                            {rate.room_type ? (
                              <span className="px-2 py-0.5 rounded text-xs uppercase font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                                {rate.room_type}
                              </span>
                            ) : (
                              <span className="text-text-muted text-xs italic">Semua Ruangan</span>
                            )}
                          </td>
                          <td className="px-4 py-3 font-semibold text-text-main">
                            {formatCurrency(rate.base_rate)}
                          </td>
                          <td className="px-4 py-3">
                            {rate.coverage_type === 'percentage' ? (
                              <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                {rate.coverage_amount}% ({formatCurrency(rate.base_rate * ((rate.coverage_amount || 0) / 100))})
                              </span>
                            ) : rate.coverage_type === 'cap_limit' ? (
                              <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                Max {formatCurrency(rate.coverage_amount || 0)}
                              </span>
                            ) : (
                              <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                {formatCurrency(rate.coverage_amount || rate.base_rate)}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 font-medium text-amber-600 dark:text-amber-400">
                            {formatCurrency(rate.patient_copay)}
                          </td>
                          <td className="px-4 py-3 text-right">
                            <form
                              method="POST"
                              action={`/tariff/rates/${rate.id}/delete`}
                              onSubmit={(e) => {
                                if (!confirm('Hapus tarif item ini?')) e.preventDefault();
                              }}
                              className="inline"
                            >
                              <input type="hidden" name="price_book_id" value={activeBook?.id || ''} />
                              <button
                                type="submit"
                                className="p-1 rounded hover:bg-rose-500/10 text-rose-500 transition"
                                title="Hapus Tarif"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </form>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: SPLIT FEE RULES */}
        {activeTab === 'splits' && (
          <div className="space-y-6">
            <div className="bg-surface rounded-xl border border-border-subtle shadow-sm overflow-hidden">
              <div className="p-4 border-b border-border-subtle">
                <h3 className="font-semibold text-text-main">Daftar Komposisi Tarif / Aturan Bagi Hasil</h3>
                <p className="text-xs text-text-muted mt-0.5">
                  Aturan pemecahan biaya untuk dokter, mekanik, sarana fasilitas klinik/kantor, dan komisi manajemen.
                </p>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="bg-surface-subtle text-text-muted text-xs uppercase border-b border-border-subtle">
                    <tr>
                      <th className="px-4 py-3">Nama Komponen</th>
                      <th className="px-4 py-3">Item / Layanan</th>
                      <th className="px-4 py-3">Tipe Penerima</th>
                      <th className="px-4 py-3">Skema Bagi Hasil</th>
                      <th className="px-4 py-3">Penerima Default</th>
                      <th className="px-4 py-3">Akun Beban/COA</th>
                      <th className="px-4 py-3 text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {splitRules.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-text-muted">
                          Belum ada aturan bagi hasil yang dibuat. Klik tombol &quot;Tambah Aturan Split&quot; di atas.
                        </td>
                      </tr>
                    ) : (
                      splitRules.map((rule) => (
                        <tr key={rule.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="px-4 py-3 font-semibold text-text-main">
                            {rule.name}
                          </td>
                          <td className="px-4 py-3 text-text-muted">
                            {rule.item_name || <span className="italic text-xs">Semua Layanan</span>}
                          </td>
                          <td className="px-4 py-3">
                            <span className="px-2 py-0.5 rounded text-xs font-medium uppercase bg-purple-500/10 text-purple-600 dark:text-purple-400">
                              {rule.recipient_type}
                            </span>
                          </td>
                          <td className="px-4 py-3 font-medium text-emerald-600 dark:text-emerald-400">
                            {rule.split_type === 'percentage'
                              ? `${rule.split_value}%`
                              : formatCurrency(rule.split_value)}
                          </td>
                          <td className="px-4 py-3 text-text-muted text-xs">
                            {rule.beneficiary_name || <span className="italic">Sesuai Pelaksana Transaksi</span>}
                          </td>
                          <td className="px-4 py-3 text-text-muted font-mono text-xs">
                            {rule.target_account_code || '-'}
                          </td>
                          <td className="px-4 py-3 text-right">
                            <form
                              method="POST"
                              action={`/tariff/split-rules/${rule.id}/delete`}
                              onSubmit={(e) => {
                                if (!confirm('Hapus aturan bagi hasil ini?')) e.preventDefault();
                              }}
                              className="inline"
                            >
                              <button
                                type="submit"
                                className="p-1 rounded hover:bg-rose-500/10 text-rose-500 transition"
                                title="Hapus Aturan"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </form>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 3: SETTLEMENT & ALLOCATIONS */}
        {activeTab === 'settlement' && (
          <div className="space-y-6">
            {/* Quick Filter & Stats */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm">
                <span className="text-xs text-text-muted font-medium">Total Akrual Fee Belum Dicairkan</span>
                <p className="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">
                  {formatCurrency(totalAccruedAmount)}
                </p>
                <span className="text-xs text-text-muted">{accruedAllocations.length} transaksi menunggu pencairan</span>
              </div>

              <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm col-span-2 flex flex-col justify-between">
                <span className="text-xs text-text-muted font-medium">Filter Penerima / Dokter / Mitra</span>
                <div className="flex items-center gap-3 mt-2">
                  <select
                    value={selectedBeneficiaryId}
                    onChange={(e) => setSelectedBeneficiaryId(e.target.value ? Number(e.target.value) : '')}
                    className="w-full max-w-sm px-3 py-1.5 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  >
                    <option value="">Semua Penerima</option>
                    {contacts.filter((c) => c.type === 'employee' || c.type === 'vendor').map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.code})
                      </option>
                    ))}
                  </select>
                  {selectedBeneficiaryId !== '' && accruedAllocations.length > 0 && (
                    <button
                      onClick={() => {
                        setShowSettleModal(true);
                      }}
                      className="inline-flex items-center gap-2 px-3.5 py-1.5 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition"
                    >
                      <CreditCard className="w-4 h-4" />
                      Cairkan ({accruedAllocations.length})
                    </button>
                  )}
                </div>
              </div>
            </div>

            {/* Allocations Table */}
            <div className="bg-surface rounded-xl border border-border-subtle shadow-sm overflow-hidden">
              <div className="p-4 border-b border-border-subtle">
                <h3 className="font-semibold text-text-main">Riwayat Alokasi Fee per Invoice</h3>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="bg-surface-subtle text-text-muted text-xs uppercase border-b border-border-subtle">
                    <tr>
                      <th className="px-4 py-3">No. Invoice</th>
                      <th className="px-4 py-3">Item Layanan</th>
                      <th className="px-4 py-3">Komponen</th>
                      <th className="px-4 py-3">Penerima / Dokter</th>
                      <th className="px-4 py-3">Nominal Fee</th>
                      <th className="px-4 py-3">Status</th>
                      <th className="px-4 py-3">Referensi Settle</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {filteredAllocations.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-text-muted">
                          Belum ada catatan alokasi fee. Alokasi dibuat otomatis saat invoice diterbitkan.
                        </td>
                      </tr>
                    ) : (
                      filteredAllocations.map((alloc) => (
                        <tr key={alloc.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="px-4 py-3 font-mono font-medium text-text-main">
                            {alloc.invoice_number || `INV #${alloc.invoice_id}`}
                          </td>
                          <td className="px-4 py-3 text-text-main">{alloc.item_name}</td>
                          <td className="px-4 py-3 text-text-muted">{alloc.component_name}</td>
                          <td className="px-4 py-3 font-medium text-text-main">
                            {alloc.beneficiary_name || <span className="text-text-muted italic">Non-Personal</span>}
                          </td>
                          <td className="px-4 py-3 font-semibold text-emerald-600 dark:text-emerald-400">
                            {formatCurrency(alloc.amount)}
                          </td>
                          <td className="px-4 py-3">
                            {alloc.status === 'paid' ? (
                              <span className="px-2 py-0.5 rounded text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                Sudah Dicairkan
                              </span>
                            ) : (
                              <span className="px-2 py-0.5 rounded text-xs font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                Akrual (Menunggu)
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-xs font-mono text-text-muted">
                            {alloc.settlement_reference || '-'}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* MODAL: Tambah Buku Tarif */}
        {showPriceBookModal && (
          <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface rounded-2xl max-w-md w-full border border-border-subtle shadow-xl overflow-hidden">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between">
                <h3 className="font-bold text-text-main">Tambah Buku Tarif Baru</h3>
                <button onClick={() => setShowPriceBookModal(false)} className="text-text-muted hover:text-text-main">
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form method="POST" action="/tariff/price-books" className="p-4 space-y-4">
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Kode Buku Tarif *</label>
                  <input
                    type="text"
                    name="code"
                    required
                    placeholder="misal: BPJS, ASURANSI_A, GROSIR"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Nama Buku Tarif *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="misal: Tarif BPJS Kesehatan FKTP"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Penjamin / Kontak Rekanan (Opsional)</label>
                  <select
                    name="payer_contact_id"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  >
                    <option value="">Umum (Tanpa Penjamin Khusus)</option>
                    {contacts.filter((c) => c.type === 'vendor' || c.type === 'customer').map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.code})
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex items-center gap-2 pt-1">
                  <input type="checkbox" name="is_default" value="1" id="is_default" className="rounded border-border-subtle" />
                  <label htmlFor="is_default" className="text-xs text-text-main font-medium">
                    Jadikan buku tarif default tenant
                  </label>
                </div>
                <div className="pt-2 flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowPriceBookModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-muted hover:bg-surface-subtle transition"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition"
                  >
                    Simpan Buku Tarif
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: Tambah Tarif Item */}
        {showRateModal && activeBook && (
          <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface rounded-2xl max-w-md w-full border border-border-subtle shadow-xl overflow-hidden">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between">
                <h3 className="font-bold text-text-main">Tambah Tarif: {activeBook.name}</h3>
                <button onClick={() => setShowRateModal(false)} className="text-text-muted hover:text-text-main">
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form method="POST" action="/tariff/rates" className="p-4 space-y-4">
                <input type="hidden" name="price_book_id" value={activeBook.id} />
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Pilih Item / Layanan *</label>
                  <select
                    name="item_id"
                    required
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  >
                    <option value="">Pilih Item...</option>
                    {items.map((i) => (
                      <option key={i.id} value={i.id}>
                        {i.name} ({i.code}) - Std: {formatCurrency(i.selling_price)}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Kelas Fasilitas / Ruang (Opsional)</label>
                  <input
                    type="text"
                    name="room_type"
                    placeholder="misal: vip, standard, icu"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Tarif Dasar (Rp) *</label>
                  <input
                    type="number"
                    name="base_rate"
                    required
                    step="100"
                    placeholder="misal: 150000"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-text-muted mb-1">Tipe Penjaminan</label>
                    <select
                      name="coverage_type"
                      className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                    >
                      <option value="fixed">Nominal Tetap (Rp)</option>
                      <option value="percentage">Persentase (%)</option>
                      <option value="cap_limit">Plafon Max (Cap)</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-text-muted mb-1">Nilai Ditanggung</label>
                    <input
                      type="number"
                      name="coverage_amount"
                      step="any"
                      placeholder="misal: 100000 atau 80"
                      className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Co-Pay Pasien / Klien (Rp)</label>
                  <input
                    type="number"
                    name="patient_copay"
                    defaultValue="0"
                    step="100"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div className="pt-2 flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowRateModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-muted hover:bg-surface-subtle transition"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition"
                  >
                    Simpan Tarif
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: Tambah Aturan Split */}
        {showSplitRuleModal && (
          <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface rounded-2xl max-w-md w-full border border-border-subtle shadow-xl overflow-hidden">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between">
                <h3 className="font-bold text-text-main">Tambah Aturan Bagi Hasil / Split Fee</h3>
                <button onClick={() => setShowSplitRuleModal(false)} className="text-text-muted hover:text-text-main">
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form method="POST" action="/tariff/split-rules" className="p-4 space-y-4">
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Nama Komponen *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="misal: Jasa Medis Dokter, Jasa Sarana Klinik"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-text-muted mb-1">Tipe Penerima *</label>
                    <select
                      name="recipient_type"
                      required
                      className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                    >
                      <option value="performer">Pelaksana Langsung (Dokter/Teknisi)</option>
                      <option value="facility">Sarana / Fasilitas</option>
                      <option value="organization">Manajemen / Organisasi</option>
                      <option value="account">Akun Spesifik</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-text-muted mb-1">Skema Hitung *</label>
                    <select
                      name="split_type"
                      required
                      className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                    >
                      <option value="percentage">Persentase (%)</option>
                      <option value="fixed_amount">Nominal Flat (Rp)</option>
                    </select>
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Nilai Bagi Hasil *</label>
                  <input
                    type="number"
                    name="split_value"
                    required
                    step="any"
                    placeholder="misal: 60 (untuk 60%) atau 50000"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Item Spesifik (Kosongkan jika umum)</label>
                  <select
                    name="item_id"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  >
                    <option value="">Berlaku untuk Semua Layanan</option>
                    {items.map((i) => (
                      <option key={i.id} value={i.id}>
                        {i.name} ({i.code})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Kode Akun COA Finance (Opsional)</label>
                  <input
                    type="text"
                    name="target_account_code"
                    placeholder="misal: 2104 - Hutang Jasa Dokter"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>
                <div className="pt-2 flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowSplitRuleModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-muted hover:bg-surface-subtle transition"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition"
                  >
                    Simpan Aturan
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: Settle Fee Disbursement */}
        {showSettleModal && (
          <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface rounded-2xl max-w-md w-full border border-border-subtle shadow-xl overflow-hidden">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between">
                <h3 className="font-bold text-text-main">Pencairan / Disbursement Fee</h3>
                <button onClick={() => setShowSettleModal(false)} className="text-text-muted hover:text-text-main">
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form method="POST" action="/tariff/settle" className="p-4 space-y-4">
                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Penerima Fee (Dokter/Mitra) *</label>
                  <select
                    name="beneficiary_contact_id"
                    required
                    value={selectedBeneficiaryId}
                    onChange={(e) => setSelectedBeneficiaryId(e.target.value ? Number(e.target.value) : '')}
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  >
                    <option value="">Pilih Penerima...</option>
                    {contacts.filter((c) => c.type === 'employee' || c.type === 'vendor').map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.code})
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">Transaksi Alokasi yang Dicairkan</label>
                  <div className="max-h-40 overflow-y-auto space-y-1.5 p-2 rounded-lg border border-border-subtle bg-surface-subtle text-xs">
                    {accruedAllocations.length === 0 ? (
                      <p className="text-text-muted">Tidak ada tagihan akrual untuk penerima ini.</p>
                    ) : (
                      accruedAllocations.map((a) => (
                        <label key={a.id} className="flex items-center gap-2 cursor-pointer hover:bg-surface p-1 rounded">
                          <input
                            type="checkbox"
                            name="allocation_ids[]"
                            value={a.id}
                            defaultChecked
                            className="rounded border-border-subtle"
                          />
                          <span className="font-mono text-text-muted">{a.invoice_number || `#${a.invoice_id}`}</span>
                          <span className="text-text-main truncate flex-1">{a.component_name}</span>
                          <span className="font-semibold text-emerald-600 dark:text-emerald-400">{formatCurrency(a.amount)}</span>
                        </label>
                      ))
                    )}
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-medium text-text-muted mb-1">No. Referensi Pembayaran (Opsional)</label>
                  <input
                    type="text"
                    name="settlement_reference"
                    placeholder="misal: PAY-DR-202610-001"
                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-subtle bg-surface text-text-main focus:ring-1 focus:ring-brand-primary"
                  />
                </div>

                <div className="pt-2 flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowSettleModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-muted hover:bg-surface-subtle transition"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={accruedAllocations.length === 0}
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition disabled:opacity-50"
                  >
                    Konfirmasi Pencairan
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
