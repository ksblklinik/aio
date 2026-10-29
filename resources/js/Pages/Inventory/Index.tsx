import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  Package,
  Warehouse,
  Boxes,
  ArrowLeftRight,
  AlertTriangle,
  Calendar,
  Plus,
  Search,
  CheckCircle2,
  Clock,
  X,
  ShieldAlert
} from 'lucide-react';

interface InventoryItem {
  id: number;
  name: string;
  code: string;
  sku: string | null;
  barcode: string | null;
  type: 'goods' | 'service' | 'medicine';
  current_stock: number;
  cost_price: number;
  selling_price: number;
  min_stock_alert: number;
  track_inventory: boolean;
  category_name?: string | null;
  unit_name?: string | null;
  unit_code?: string | null;
  is_active: boolean;
}

interface InventoryUnit {
  id: number;
  code: string;
  name: string;
}

interface ItemCategory {
  id: number;
  name: string;
  code: string;
}

interface NearExpiryItem {
  batch_id: number;
  batch_number: string;
  expiry_date: string;
  batch_status: string;
  batch_quantity: number;
  item_id: number;
  item_name: string;
  item_code: string;
  room_id: number;
  room_name: string;
  room_code: string;
  location_name: string;
  unit_code: string;
  days_to_expiry: number;
}

interface StockTransfer {
  id: number;
  transfer_number: string;
  source_room_id: number;
  source_room_name: string;
  source_room_code: string;
  source_location_name: string;
  destination_room_id: number;
  destination_room_name: string;
  destination_room_code: string;
  destination_location_name: string;
  status: string;
  transfer_date: string;
  notes: string | null;
  items_count?: number;
  total_quantity?: number;
  items?: Array<{
    item_id: number;
    item_name: string;
    item_code: string;
    quantity: number;
    batch_number?: string | null;
    expiry_date?: string | null;
  }>;
}

interface StorageRoom {
  id: number;
  name: string;
  code: string;
  floor: string;
  type: string;
  location_name?: string;
}

interface InventoryIndexProps {
  items: InventoryItem[];
  units: InventoryUnit[];
  categories: ItemCategory[];
  lowStockItems: InventoryItem[];
  nearExpiryItems: NearExpiryItem[];
  transfers: StockTransfer[];
  storageRooms: StorageRoom[];
  filters: {
    q?: string;
    type?: string | null;
  };
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Index({
  items = [],
  units = [],
  categories = [],
  lowStockItems = [],
  nearExpiryItems = [],
  transfers = [],
  storageRooms = [],
  filters = { q: '', type: null },
}: InventoryIndexProps) {
  const [activeTab, setActiveTab] = useState<'catalog' | 'warehouses' | 'transfers' | 'expiry'>('catalog');
  const [searchTerm, setSearchTerm] = useState(filters?.q || '');
  const [typeFilter, setTypeFilter] = useState<string>(filters?.type || 'all');
  const [selectedWarehouseFilter, setSelectedWarehouseFilter] = useState<number | 'all'>('all');

  // Modal States
  const [showItemModal, setShowItemModal] = useState(false);
  const [showAdjustModal, setShowAdjustModal] = useState(false);
  const [showBatchModal, setShowBatchModal] = useState(false);
  const [showTransferModal, setShowTransferModal] = useState(false);
  const [activeItemForAction, setActiveItemForAction] = useState<InventoryItem | null>(null);

  // Filtered items
  const filteredItems = items.filter((item) => {
    const matchesType = typeFilter === 'all' || item.type === typeFilter;
    const matchesSearch =
      item.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      item.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (item.sku && item.sku.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (item.barcode && item.barcode.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (item.category_name && item.category_name.toLowerCase().includes(searchTerm.toLowerCase()));
    return matchesType && matchesSearch;
  });

  const formatNumber = (num: number) => {
    return new Intl.NumberFormat('id-ID').format(num);
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(amount);
  };

  return (
    <AppLayout title="Universal Inventory & Stock Engine">
      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Top Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 rounded-xl bg-brand-primary/10 text-brand-primary">
                <Boxes className="w-6 h-6" />
              </span>
              <h1 className="text-2xl font-bold tracking-tight text-text-main">
                Manajemen Inventaris & Gudang
              </h1>
            </div>
            <p className="text-sm text-text-muted mt-1">
              Katalog universal, stok multi-gudang, pelacakan batch & kadaluarsa (FEFO), dan transfer internal.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <button
              onClick={() => setShowTransferModal(true)}
              className="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-main hover:bg-surface-subtle active:scale-[0.98] transition-all duration-150 shadow-sm"
            >
              <ArrowLeftRight className="w-4 h-4 text-brand-primary" />
              Transfer Stok
            </button>
            <button
              onClick={() => setShowItemModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover active:scale-[0.98] transition-all duration-150 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              Item Baru
            </button>
          </div>
        </div>

        {/* Metric Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-4">
            <div className="p-3 rounded-lg bg-blue-500/10 text-blue-500">
              <Package className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Total Katalog</p>
              <p className="text-xl font-bold text-text-main">{items.length}</p>
              <p className="text-xs text-text-muted mt-0.5">Barang & Layanan Terdaftar</p>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-4">
            <div className="p-3 rounded-lg bg-amber-500/10 text-amber-500">
              <AlertTriangle className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Peringatan Menipis</p>
              <p className="text-xl font-bold text-amber-600 dark:text-amber-400">{lowStockItems.length}</p>
              <p className="text-xs text-text-muted mt-0.5">Di bawah batas aman</p>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-4">
            <div className="p-3 rounded-lg bg-rose-500/10 text-rose-500">
              <Clock className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Mendekati Kedaluwarsa</p>
              <p className="text-xl font-bold text-rose-600 dark:text-rose-400">{nearExpiryItems.length}</p>
              <p className="text-xs text-text-muted mt-0.5">Batch &lt; 60 hari kedaluwarsa</p>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-4">
            <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-500">
              <Warehouse className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Ruangan / Gudang</p>
              <p className="text-xl font-bold text-text-main">{storageRooms.length}</p>
              <p className="text-xs text-text-muted mt-0.5">Lokasi Penyimpanan Aktif</p>
            </div>
          </div>
        </div>

        {/* Navigation Tabs */}
        <div className="border-b border-border-subtle">
          <nav className="flex space-x-6">
            <button
              onClick={() => setActiveTab('catalog')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'catalog'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Package className="w-4 h-4" />
              Katalog & Stok
            </button>
            <button
              onClick={() => setActiveTab('warehouses')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'warehouses'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Warehouse className="w-4 h-4" />
              Multi-Gudang ({storageRooms.length})
            </button>
            <button
              onClick={() => setActiveTab('transfers')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'transfers'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <ArrowLeftRight className="w-4 h-4" />
              Transfer Antar-Gudang ({transfers.length})
            </button>
            <button
              onClick={() => setActiveTab('expiry')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'expiry'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Calendar className="w-4 h-4" />
              Peringatan FEFO
              {nearExpiryItems.length > 0 && (
                <span className="px-1.5 py-0.5 text-xs font-bold rounded-full bg-rose-500 text-white">
                  {nearExpiryItems.length}
                </span>
              )}
            </button>
          </nav>
        </div>

        {/* Tab 1: Catalog & Stock */}
        {activeTab === 'catalog' && (
          <div className="space-y-4">
            {/* Search & Filters */}
            <div className="flex flex-col sm:flex-row gap-3 items-center justify-between">
              <div className="relative w-full sm:w-80">
                <Search className="absolute left-3 top-2.5 w-4 h-4 text-text-muted" />
                <input
                  type="text"
                  placeholder="Cari kode, nama, barcode, sku..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary transition"
                />
              </div>

              <div className="flex items-center gap-2 w-full sm:w-auto">
                <select
                  value={typeFilter}
                  onChange={(e) => setTypeFilter(e.target.value)}
                  className="px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary transition"
                >
                  <option value="all">Semua Tipe</option>
                  <option value="goods">Barang Fisik (Goods)</option>
                  <option value="medicine">Obat / Farmasi</option>
                  <option value="service">Jasa / Layanan</option>
                </select>
              </div>
            </div>

            {/* Catalog Table */}
            <div className="rounded-xl bg-surface border border-border-subtle overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                  <thead>
                    <tr className="bg-surface-subtle border-b border-border-subtle text-xs font-semibold text-text-muted uppercase tracking-wider">
                      <th className="py-3 px-4">Item & Kode</th>
                      <th className="py-3 px-4">Tipe & Kategori</th>
                      <th className="py-3 px-4 text-right">Stok Fisik</th>
                      <th className="py-3 px-4 text-right">Harga Beli</th>
                      <th className="py-3 px-4 text-right">Harga Jual</th>
                      <th className="py-3 px-4 text-center">Aksi Cepat</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {filteredItems.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-10 text-center text-text-muted">
                          Belum ada item yang sesuai dengan kriteria pencarian.
                        </td>
                      </tr>
                    ) : (
                      filteredItems.map((item) => (
                        <tr key={item.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="py-3 px-4">
                            <div className="font-semibold text-text-main">{item.name}</div>
                            <div className="text-xs text-text-muted flex items-center gap-2 mt-0.5">
                              <span>Kode: <span className="font-mono">{item.code}</span></span>
                              {item.barcode && <span>• Barcode: <span className="font-mono">{item.barcode}</span></span>}
                            </div>
                          </td>
                          <td className="py-3 px-4">
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-surface-subtle border border-border-subtle text-text-main">
                              {item.type}
                            </span>
                            <div className="text-xs text-text-muted mt-1">
                              {item.category_name || 'Tanpa Kategori'}
                            </div>
                          </td>
                          <td className="py-3 px-4 text-right">
                            {item.track_inventory ? (
                              <div>
                                <span
                                  className={`font-semibold ${
                                    item.current_stock <= item.min_stock_alert
                                      ? 'text-rose-500 font-bold'
                                      : 'text-text-main'
                                  }`}
                                >
                                  {formatNumber(item.current_stock)}
                                </span>
                                <span className="text-xs text-text-muted ml-1">
                                  {item.unit_code || 'pcs'}
                                </span>
                                {item.current_stock <= item.min_stock_alert && (
                                  <div className="text-2xs text-rose-500 font-medium mt-0.5 flex items-center justify-end gap-1">
                                    <AlertTriangle className="w-3 h-3" /> Min {item.min_stock_alert}
                                  </div>
                                )}
                              </div>
                            ) : (
                              <span className="text-xs text-text-muted italic">Non-Stok</span>
                            )}
                          </td>
                          <td className="py-3 px-4 text-right text-text-muted font-mono">
                            {formatCurrency(item.cost_price)}
                          </td>
                          <td className="py-3 px-4 text-right font-medium text-text-main font-mono">
                            {formatCurrency(item.selling_price)}
                          </td>
                          <td className="py-3 px-4 text-center">
                            <div className="flex items-center justify-center gap-1.5">
                              {item.track_inventory && (
                                <>
                                  <button
                                    onClick={() => {
                                      setActiveItemForAction(item);
                                      setShowAdjustModal(true);
                                    }}
                                    className="px-2.5 py-1 text-xs font-medium rounded border border-border-subtle bg-surface hover:bg-surface-subtle text-text-main transition shadow-2xs"
                                  >
                                    Sesuaikan
                                  </button>
                                  <button
                                    onClick={() => {
                                      setActiveItemForAction(item);
                                      setShowBatchModal(true);
                                    }}
                                    className="px-2.5 py-1 text-xs font-medium rounded border border-border-subtle bg-surface hover:bg-surface-subtle text-brand-primary transition shadow-2xs"
                                  >
                                    + Batch
                                  </button>
                                </>
                              )}
                            </div>
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

        {/* Tab 2: Multi-Warehouse & Locations */}
        {activeTab === 'warehouses' && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {storageRooms.map((room) => (
                <div
                  key={room.id}
                  className="p-5 rounded-xl bg-surface border border-border-subtle hover:border-brand-primary/40 transition shadow-sm space-y-3"
                >
                  <div className="flex items-start justify-between">
                    <div>
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-brand-primary/10 text-brand-primary mb-1.5 font-mono">
                        {room.code}
                      </span>
                      <h3 className="font-semibold text-text-main">{room.name}</h3>
                      <p className="text-xs text-text-muted mt-0.5">
                        {room.location_name || 'Gudang Utama'} • Lantai {room.floor || '1'}
                      </p>
                    </div>
                    <Warehouse className="w-5 h-5 text-text-muted" />
                  </div>

                  <div className="pt-3 border-t border-border-subtle flex items-center justify-between text-xs text-text-muted">
                    <span>Tipe Ruang: <span className="font-medium text-text-main">{room.type}</span></span>
                    <button
                      onClick={() => {
                        setSelectedWarehouseFilter(room.id);
                        setShowTransferModal(true);
                      }}
                      className="text-brand-primary hover:underline font-medium"
                    >
                      Kirim Transfer →
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Tab 3: Stock Transfers */}
        {activeTab === 'transfers' && (
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <div>
                <h3 className="font-bold text-text-main">Riwayat Mutasi Antar Gudang</h3>
                <p className="text-xs text-text-muted">Semua pergerakan barang antar unit, klinik, dan instalasi farmasi.</p>
              </div>
              <button
                onClick={() => setShowTransferModal(true)}
                className="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition"
              >
                <Plus className="w-3.5 h-3.5" /> Buat Transfer
              </button>
            </div>

            <div className="rounded-xl bg-surface border border-border-subtle overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                  <thead>
                    <tr className="bg-surface-subtle border-b border-border-subtle text-xs font-semibold text-text-muted uppercase tracking-wider">
                      <th className="py-3 px-4">No. Transfer</th>
                      <th className="py-3 px-4">Gudang Asal</th>
                      <th className="py-3 px-4">Gudang Tujuan</th>
                      <th className="py-3 px-4">Tanggal</th>
                      <th className="py-3 px-4">Status</th>
                      <th className="py-3 px-4">Keterangan</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {transfers.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-10 text-center text-text-muted">
                          Belum ada riwayat transaksi transfer stok antar-gudang.
                        </td>
                      </tr>
                    ) : (
                      transfers.map((trf) => (
                        <tr key={trf.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="py-3 px-4 font-mono font-semibold text-text-main">
                            {trf.transfer_number}
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-medium text-text-main">{trf.source_room_name}</div>
                            <div className="text-xs text-text-muted">{trf.source_location_name}</div>
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-medium text-text-main">{trf.destination_room_name}</div>
                            <div className="text-xs text-text-muted">{trf.destination_location_name}</div>
                          </td>
                          <td className="py-3 px-4 text-text-muted text-xs">
                            {trf.transfer_date}
                          </td>
                          <td className="py-3 px-4">
                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                              <CheckCircle2 className="w-3 h-3" /> {trf.status}
                            </span>
                          </td>
                          <td className="py-3 px-4 text-xs text-text-muted">
                            {trf.notes || '-'}
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

        {/* Tab 4: Near Expiry (FEFO Engine) */}
        {activeTab === 'expiry' && (
          <div className="space-y-4">
            <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-3">
              <ShieldAlert className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
              <div className="text-sm">
                <p className="font-semibold text-amber-700 dark:text-amber-300">
                  Engine FEFO (First-Expired, First-Out) Aktif
                </p>
                <p className="text-amber-600/90 dark:text-amber-400 text-xs mt-0.5">
                  Sistem otomatis memprioritaskan pemotongan batch dengan tanggal kedaluwarsa terdekat ketika obat atau barang dijual/diresepkan melalui kasir atau invoice.
                </p>
              </div>
            </div>

            <div className="rounded-xl bg-surface border border-border-subtle overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                  <thead>
                    <tr className="bg-surface-subtle border-b border-border-subtle text-xs font-semibold text-text-muted uppercase tracking-wider">
                      <th className="py-3 px-4">Item & Kode</th>
                      <th className="py-3 px-4">Batch Number</th>
                      <th className="py-3 px-4">Gudang / Ruangan</th>
                      <th className="py-3 px-4">Tgl Kedaluwarsa</th>
                      <th className="py-3 px-4 text-center">Sisa Hari</th>
                      <th className="py-3 px-4 text-right">Sisa Stok</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {nearExpiryItems.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-10 text-center text-text-muted">
                          Semua batch dalam status aman. Tidak ada barang mendekati batas kedaluwarsa 60 hari.
                        </td>
                      </tr>
                    ) : (
                      nearExpiryItems.map((batch) => (
                        <tr key={batch.batch_id} className="hover:bg-surface-subtle/50 transition">
                          <td className="py-3 px-4">
                            <div className="font-semibold text-text-main">{batch.item_name}</div>
                            <div className="text-xs text-text-muted font-mono">{batch.item_code}</div>
                          </td>
                          <td className="py-3 px-4 font-mono font-medium text-brand-primary">
                            {batch.batch_number}
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-medium text-text-main">{batch.room_name}</div>
                            <div className="text-xs text-text-muted">{batch.location_name}</div>
                          </td>
                          <td className="py-3 px-4 font-mono text-text-main">
                            {batch.expiry_date}
                          </td>
                          <td className="py-3 px-4 text-center">
                            <span
                              className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ${
                                batch.days_to_expiry <= 30
                                  ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20'
                                  : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'
                              }`}
                            >
                              {batch.days_to_expiry} hari lagi
                            </span>
                          </td>
                          <td className="py-3 px-4 text-right font-bold text-text-main">
                            {formatNumber(batch.batch_quantity)} {batch.unit_code}
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

        {/* Modal: Tambah Item Baru */}
        {showItemModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-lg overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Package className="w-5 h-5 text-brand-primary" />
                  <h3 className="font-bold text-text-main">Tambah Item Baru</h3>
                </div>
                <button
                  onClick={() => setShowItemModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/inventory" method="POST" className="p-5 space-y-4 max-h-[80vh] overflow-y-auto">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Item *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="Contoh: Paracetamol 500mg, Kain Kasa 10cm"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kode Item (Opsional)</label>
                    <input
                      type="text"
                      name="code"
                      placeholder="Auto jika kosong"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Barcode / SKU</label>
                    <input
                      type="text"
                      name="barcode"
                      placeholder="899..."
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Item</label>
                    <select
                      name="type"
                      defaultValue="goods"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="goods">Barang Fisik (Goods)</option>
                      <option value="medicine">Obat / Farmasi</option>
                      <option value="service">Jasa / Layanan</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Satuan Dasar</label>
                    <select
                      name="unit_id"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="">-- Pilih Satuan --</option>
                      {units.map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name} ({u.code})
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Kategori Item</label>
                  <select
                    name="category_id"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    <option value="">-- Tanpa Kategori --</option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Harga Beli / Pokok</label>
                    <input
                      type="number"
                      name="cost_price"
                      defaultValue="0"
                      step="any"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Harga Jual</label>
                    <input
                      type="number"
                      name="selling_price"
                      defaultValue="0"
                      step="any"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Stok Awal</label>
                    <input
                      type="number"
                      name="initial_stock"
                      defaultValue="0"
                      step="any"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Batas Minimal Peringatan</label>
                    <input
                      type="number"
                      name="min_stock_alert"
                      defaultValue="5"
                      step="any"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowItemModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Item
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Penyesuaian Stok / Mutasi Fisik */}
        {showAdjustModal && activeItemForAction && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div>
                  <h3 className="font-bold text-text-main">Penyesuaian Stok Fisik</h3>
                  <p className="text-xs text-text-muted">{activeItemForAction.name} ({activeItemForAction.code})</p>
                </div>
                <button
                  onClick={() => setShowAdjustModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action={`/inventory/${activeItemForAction.id}/adjust`} method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Gudang / Ruangan Penyimpanan</label>
                  <select
                    name="room_id"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    <option value="">-- Stok Global (Tanpa Ruangan) --</option>
                    {storageRooms.map((rm) => (
                      <option key={rm.id} value={rm.id}>
                        {rm.name} ({rm.location_name || 'Gudang'})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Mutasi *</label>
                    <select
                      name="type"
                      defaultValue="adjustment"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="adjustment">Penyesuaian (Adjustment)</option>
                      <option value="in">Masuk (In / Pembelian)</option>
                      <option value="out">Keluar (Out / Pemakaian)</option>
                      <option value="opname">Stock Opname Fisik</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Jumlah Kuantitas *</label>
                    <input
                      type="number"
                      name="quantity"
                      required
                      step="any"
                      placeholder="0.0"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Catatan / Alasan Mutasi</label>
                  <textarea
                    name="notes"
                    rows={2}
                    placeholder="Contoh: Hasil stock opname akhir bulan, barang rusak..."
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowAdjustModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Mutasi
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Tambah Batch & Expiry */}
        {showBatchModal && activeItemForAction && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div>
                  <h3 className="font-bold text-text-main">Registrasi Batch & Kadaluarsa</h3>
                  <p className="text-xs text-text-muted">{activeItemForAction.name}</p>
                </div>
                <button
                  onClick={() => setShowBatchModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action={`/inventory/${activeItemForAction.id}/batches`} method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nomor Batch (Lot Number) *</label>
                  <input
                    type="text"
                    name="batch_number"
                    required
                    placeholder="Contoh: BATCH-2026-X1"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-mono"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tanggal Kedaluwarsa *</label>
                    <input
                      type="date"
                      name="expiry_date"
                      required
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kuantitas Awal</label>
                    <input
                      type="number"
                      name="quantity"
                      defaultValue="0"
                      step="any"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Catatan Batch</label>
                  <input
                    type="text"
                    name="notes"
                    placeholder="Contoh: Pengadaan Kimia Farma PO-091"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowBatchModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Batch
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Transfer Stok Antar Gudang */}
        {showTransferModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-lg overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <ArrowLeftRight className="w-5 h-5 text-brand-primary" />
                  <h3 className="font-bold text-text-main">Transfer Stok Antar-Gudang</h3>
                </div>
                <button
                  onClick={() => setShowTransferModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/inventory/transfers" method="POST" className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Gudang Asal *</label>
                    <select
                      name="source_room_id"
                      required
                      defaultValue={selectedWarehouseFilter !== 'all' ? selectedWarehouseFilter : storageRooms[0]?.id}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      {storageRooms.map((rm) => (
                        <option key={rm.id} value={rm.id}>
                          {rm.name} ({rm.code})
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Gudang Tujuan *</label>
                    <select
                      name="destination_room_id"
                      required
                      defaultValue={storageRooms[1]?.id || storageRooms[0]?.id}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      {storageRooms.map((rm) => (
                        <option key={rm.id} value={rm.id}>
                          {rm.name} ({rm.code})
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Pilih Barang yang Ditransfer *</label>
                  <select
                    name="item_id"
                    required
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    <option value="">-- Pilih Barang --</option>
                    {items.filter((i) => i.track_inventory).map((it) => (
                      <option key={it.id} value={it.id}>
                        {it.name} ({it.code}) - Total: {it.current_stock}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kuantitas Transfer *</label>
                    <input
                      type="number"
                      name="quantity"
                      required
                      min="0.01"
                      step="any"
                      placeholder="1.0"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Nomor Batch (Opsional)</label>
                    <input
                      type="number"
                      name="batch_id"
                      placeholder="ID Batch jika spesifik"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Keterangan / Dokumen Permintaan</label>
                  <textarea
                    name="notes"
                    rows={2}
                    placeholder="Contoh: Permintaan stok darurat IGD, Resep rawat inap..."
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowTransferModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Eksekusi Transfer
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
