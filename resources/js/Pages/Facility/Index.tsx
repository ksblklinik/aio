import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  Building2,
  DoorOpen,
  Warehouse,
  Bed,
  Plus,
  Search,
  Filter,
  CheckCircle2,
  Clock,
  Wrench,
  AlertCircle,
  X
} from 'lucide-react';

interface Location {
  id: number;
  code: string;
  name: string;
  type: string;
  address: string | null;
  city: string | null;
  phone: string | null;
  is_active: boolean;
  rooms_count?: number;
}

interface Room {
  id: number;
  location_id: number;
  location_name?: string;
  location_code?: string;
  code: string;
  name: string;
  floor: string;
  type: string;
  capacity: number;
  notes: string | null;
  is_active: boolean;
  spaces_count?: number;
}

interface Space {
  id: number;
  room_id: number;
  room_name?: string;
  room_code?: string;
  room_floor?: string;
  location_id?: number;
  location_name?: string;
  code: string;
  name: string;
  type: string;
  status: 'available' | 'occupied' | 'maintenance' | 'reserved';
  is_active: boolean;
}

interface FacilitySummary {
  total_locations: number;
  total_rooms: number;
  total_storage_rooms: number;
  total_spaces: number;
  available_spaces: number;
  occupied_spaces: number;
  maintenance_spaces: number;
  reserved_spaces: number;
}

interface FacilityIndexProps {
  locations: Location[];
  rooms: Room[];
  spaces: Space[];
  summary: FacilitySummary;
  selectedLocationId?: number | null;
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Index({
  locations = [],
  rooms = [],
  spaces = [],
  summary = {
    total_locations: 0,
    total_rooms: 0,
    total_storage_rooms: 0,
    total_spaces: 0,
    available_spaces: 0,
    occupied_spaces: 0,
    maintenance_spaces: 0,
    reserved_spaces: 0,
  },
  selectedLocationId = null,
}: FacilityIndexProps) {
  const [activeTab, setActiveTab] = useState<'hierarchy' | 'locations' | 'spaces'>('hierarchy');
  const [searchTerm, setSearchTerm] = useState('');
  const [activeLocationFilter, setActiveLocationFilter] = useState<number | 'all'>(selectedLocationId || 'all');
  const [activeTypeFilter, setActiveTypeFilter] = useState<string>('all');

  // Modal States
  const [showLocationModal, setShowLocationModal] = useState(false);
  const [showRoomModal, setShowRoomModal] = useState(false);
  const [showSpaceModal, setShowSpaceModal] = useState(false);
  const [selectedRoomForSpace, setSelectedRoomForSpace] = useState<number | null>(null);

  // Filtered rooms
  const filteredRooms = rooms.filter((room) => {
    const matchesLoc = activeLocationFilter === 'all' || room.location_id === activeLocationFilter;
    const matchesType = activeTypeFilter === 'all' || room.type === activeTypeFilter;
    const matchesSearch =
      room.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      room.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (room.location_name && room.location_name.toLowerCase().includes(searchTerm.toLowerCase()));
    return matchesLoc && matchesType && matchesSearch;
  });

  // Filtered spaces
  const filteredSpaces = spaces.filter((space) => {
    const matchesSearch =
      space.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      space.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (space.room_name && space.room_name.toLowerCase().includes(searchTerm.toLowerCase()));
    return matchesSearch;
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'available':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
            <CheckCircle2 className="w-3 h-3" /> Tersedia
          </span>
        );
      case 'occupied':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-500/10 text-rose-500 border border-rose-500/20">
            <Clock className="w-3 h-3" /> Terisi
          </span>
        );
      case 'maintenance':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-500 border border-amber-500/20">
            <Wrench className="w-3 h-3" /> Perbaikan
          </span>
        );
      case 'reserved':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-500/10 text-blue-500 border border-blue-500/20">
            <AlertCircle className="w-3 h-3" /> Dipesan
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-text-muted border border-border-subtle">
            {status}
          </span>
        );
    }
  };

  const getRoomTypeBadge = (type: string) => {
    switch (type) {
      case 'service':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-sky-500/10 text-sky-500">Pelayanan/Poli</span>;
      case 'storage':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-amber-500/10 text-amber-500">Gudang/Depo</span>;
      case 'inpatient':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-emerald-500/10 text-emerald-500">Rawat Inap</span>;
      case 'procedure':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-purple-500/10 text-purple-500">Tindakan/UGD</span>;
      case 'office':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-indigo-500/10 text-indigo-500">Kantor/Admin</span>;
      case 'meeting':
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-teal-500/10 text-teal-500">Aula/Meeting</span>;
      default:
        return <span className="px-2 py-0.5 rounded text-xs font-medium bg-surface-subtle text-text-muted">{type}</span>;
    }
  };

  const handleUpdateSpaceStatus = async (spaceId: number, status: string) => {
    try {
      const response = await fetch(`/api/facility/spaces/${spaceId}/status`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status }),
      });
      if (response.ok) {
        window.location.reload();
      }
    } catch {
      // Fallback
      window.location.reload();
    }
  };

  return (
    <AppLayout title="Fasilitas & Ruangan">
      <div className="space-y-6">
        {/* Header Section */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-text-main flex items-center gap-2">
              <Building2 className="w-7 h-7 text-brand-primary" />
              Fasilitas, Ruangan & Lokasi
            </h1>
            <p className="text-sm text-text-muted mt-1">
              Universal registry gedung, ruangan operasional, gudang inventori, dan unit tempat tidur/meja.
            </p>
          </div>
          <div className="flex items-center gap-2.5">
            <button
              onClick={() => setShowLocationModal(true)}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-surface-subtle text-text-main border border-border-subtle hover:bg-surface active:scale-[0.98] transition-all duration-150 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              Gedung Baru
            </button>
            <button
              onClick={() => setShowRoomModal(true)}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-brand-primary text-white hover:bg-brand-primary-hover active:scale-[0.98] transition-all duration-150 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              Ruangan Baru
            </button>
          </div>
        </div>

        {/* KPI Summary Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-3">
            <div className="p-3 rounded-lg bg-blue-500/10 text-blue-500">
              <Building2 className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Total Lokasi/Gedung</p>
              <h3 className="text-xl font-bold text-text-main">{summary.total_locations}</h3>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-3">
            <div className="p-3 rounded-lg bg-sky-500/10 text-sky-500">
              <DoorOpen className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Total Ruangan</p>
              <h3 className="text-xl font-bold text-text-main">{summary.total_rooms}</h3>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-3">
            <div className="p-3 rounded-lg bg-amber-500/10 text-amber-500">
              <Warehouse className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Gudang Inventori</p>
              <h3 className="text-xl font-bold text-text-main">{summary.total_storage_rooms}</h3>
            </div>
          </div>

          <div className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex items-center gap-3">
            <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-500">
              <Bed className="w-6 h-6" />
            </div>
            <div>
              <p className="text-xs font-medium text-text-muted">Unit Bed / Meja</p>
              <h3 className="text-xl font-bold text-text-main">
                {summary.available_spaces} <span className="text-xs font-normal text-text-muted">/ {summary.total_spaces} siap</span>
              </h3>
            </div>
          </div>
        </div>

        {/* Navigation Tabs & Search */}
        <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 border-b border-border-subtle pb-3">
          <div className="flex items-center gap-2">
            <button
              onClick={() => setActiveTab('hierarchy')}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                activeTab === 'hierarchy'
                  ? 'bg-brand-primary text-white shadow-sm'
                  : 'bg-surface-subtle text-text-muted hover:text-text-main'
              }`}
            >
              Hierarki & Denah Ruang
            </button>
            <button
              onClick={() => setActiveTab('locations')}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                activeTab === 'locations'
                  ? 'bg-brand-primary text-white shadow-sm'
                  : 'bg-surface-subtle text-text-muted hover:text-text-main'
              }`}
            >
              Master Gedung ({locations.length})
            </button>
            <button
              onClick={() => setActiveTab('spaces')}
              className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                activeTab === 'spaces'
                  ? 'bg-brand-primary text-white shadow-sm'
                  : 'bg-surface-subtle text-text-muted hover:text-text-main'
              }`}
            >
              Status Unit/Bed ({spaces.length})
            </button>
          </div>

          {/* Search bar */}
          <div className="relative min-w-[240px]">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
              type="text"
              placeholder="Cari gedung, ruangan, slot..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-9 pr-3 py-1.5 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
            />
          </div>
        </div>

        {/* View Tab 1: Hierarchy View */}
        {activeTab === 'hierarchy' && (
          <div className="space-y-4">
            {/* Location & Type Filter Buttons */}
            <div className="flex flex-wrap items-center gap-2 text-xs">
              <span className="text-text-muted font-medium flex items-center gap-1">
                <Filter className="w-3.5 h-3.5" /> Filter Lokasi:
              </span>
              <button
                onClick={() => setActiveLocationFilter('all')}
                className={`px-2.5 py-1 rounded-md border text-xs font-medium transition-colors ${
                  activeLocationFilter === 'all'
                    ? 'border-brand-primary bg-brand-primary/10 text-brand-primary'
                    : 'border-border-subtle bg-surface text-text-muted hover:text-text-main'
                }`}
              >
                Semua Lokasi
              </button>
              {locations.map((loc) => (
                <button
                  key={loc.id}
                  onClick={() => setActiveLocationFilter(loc.id)}
                  className={`px-2.5 py-1 rounded-md border text-xs font-medium transition-colors ${
                    activeLocationFilter === loc.id
                      ? 'border-brand-primary bg-brand-primary/10 text-brand-primary'
                      : 'border-border-subtle bg-surface text-text-muted hover:text-text-main'
                  }`}
                >
                  {loc.name}
                </button>
              ))}

              <span className="text-text-muted font-medium ml-2">Tipe:</span>
              {['all', 'service', 'storage', 'inpatient', 'procedure', 'office'].map((t) => (
                <button
                  key={t}
                  onClick={() => setActiveTypeFilter(t)}
                  className={`px-2.5 py-1 rounded-md border text-xs font-medium transition-colors ${
                    activeTypeFilter === t
                      ? 'border-brand-primary bg-brand-primary/10 text-brand-primary'
                      : 'border-border-subtle bg-surface text-text-muted hover:text-text-main'
                  }`}
                >
                  {t === 'all' ? 'Semua Tipe' : t}
                </button>
              ))}
            </div>

            {/* Room Cards Grid */}
            {filteredRooms.length === 0 ? (
              <div className="p-8 text-center bg-surface border border-border-subtle rounded-xl">
                <DoorOpen className="w-10 h-10 text-text-muted mx-auto mb-2" />
                <p className="text-text-main font-medium">Belum ada ruangan yang cocok dengan filter.</p>
                <p className="text-xs text-text-muted mt-1">Tambahkan ruangan baru atau sesuaikan filter lokasi Anda.</p>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {filteredRooms.map((room) => {
                  const roomSpaces = spaces.filter((s) => s.room_id === room.id);
                  const availableCount = roomSpaces.filter((s) => s.status === 'available').length;
                  const occupiedCount = roomSpaces.filter((s) => s.status === 'occupied').length;

                  return (
                    <div
                      key={room.id}
                      className="p-4 rounded-xl bg-surface border border-border-subtle shadow-sm flex flex-col justify-between hover:border-brand-primary/40 transition-colors"
                    >
                      <div>
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <span className="text-xs font-mono text-text-muted">{room.code}</span>
                            <h4 className="text-base font-semibold text-text-main mt-0.5">{room.name}</h4>
                            <p className="text-xs text-text-muted flex items-center gap-1 mt-0.5">
                              <Building2 className="w-3 h-3" />
                              {room.location_name || 'Lokasi Terpusat'} • Lantai {room.floor}
                            </p>
                          </div>
                          <div>{getRoomTypeBadge(room.type)}</div>
                        </div>

                        {room.notes && (
                          <p className="text-xs text-text-muted mt-2 italic bg-surface-subtle p-2 rounded-lg">
                            {room.notes}
                          </p>
                        )}

                        {/* Spaces within Room */}
                        <div className="mt-4 pt-3 border-t border-border-subtle">
                          <div className="flex items-center justify-between text-xs text-text-muted mb-2">
                            <span>Unit Slot / Bed: ({roomSpaces.length} unit)</span>
                            <span className="text-emerald-500 font-medium">
                              {availableCount} siap {occupiedCount > 0 && <span className="text-rose-500">• {occupiedCount} terisi</span>}
                            </span>
                          </div>

                          <div className="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto">
                            {roomSpaces.length === 0 ? (
                              <span className="text-xs text-text-muted italic">Belum ada unit terdaftar.</span>
                            ) : (
                              roomSpaces.map((s) => (
                                <div
                                  key={s.id}
                                  className="group relative flex items-center gap-1 px-2 py-1 rounded bg-surface-subtle border border-border-subtle text-xs"
                                >
                                  <span className="font-medium text-text-main">{s.name}</span>
                                  <span className="text-[10px] text-text-muted">({s.type})</span>
                                  {getStatusBadge(s.status)}
                                </div>
                              ))
                            )}
                          </div>
                        </div>
                      </div>

                      <div className="mt-4 pt-3 border-t border-border-subtle flex items-center justify-between">
                        <span className="text-xs text-text-muted">
                          Kapasitas Max: <strong className="text-text-main">{room.capacity}</strong>
                        </span>
                        <button
                          onClick={() => {
                            setSelectedRoomForSpace(room.id);
                            setShowSpaceModal(true);
                          }}
                          className="inline-flex items-center gap-1 text-xs font-medium text-brand-primary hover:underline"
                        >
                          <Plus className="w-3.5 h-3.5" /> Tambah Slot/Bed
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}

        {/* View Tab 2: Locations List */}
        {activeTab === 'locations' && (
          <div className="rounded-xl bg-surface border border-border-subtle shadow-sm overflow-hidden">
            <div className="p-4 border-b border-border-subtle flex items-center justify-between">
              <h3 className="text-base font-semibold text-text-main">Daftar Gedung & Posko Operasional</h3>
              <span className="text-xs text-text-muted">{locations.length} Lokasi Terdaftar</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-surface-subtle text-text-muted text-xs border-b border-border-subtle">
                  <tr>
                    <th className="px-4 py-3 font-medium">Kode Gedung</th>
                    <th className="px-4 py-3 font-medium">Nama Lokasi</th>
                    <th className="px-4 py-3 font-medium">Tipe</th>
                    <th className="px-4 py-3 font-medium">Kota / Alamat</th>
                    <th className="px-4 py-3 font-medium">Telepon</th>
                    <th className="px-4 py-3 font-medium text-center">Jumlah Ruangan</th>
                    <th className="px-4 py-3 font-medium text-center">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {locations.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-8 text-center text-text-muted">
                        Belum ada gedung atau lokasi fisik terdaftar.
                      </td>
                    </tr>
                  ) : (
                    locations.map((loc) => (
                      <tr key={loc.id} className="hover:bg-surface-subtle/50 transition-colors">
                        <td className="px-4 py-3 font-mono text-xs text-brand-primary font-medium">{loc.code}</td>
                        <td className="px-4 py-3 font-semibold text-text-main">{loc.name}</td>
                        <td className="px-4 py-3 capitalize text-xs text-text-muted">{loc.type}</td>
                        <td className="px-4 py-3 text-xs text-text-muted">
                          {loc.city ? `${loc.city} • ` : ''}
                          {loc.address || '-'}
                        </td>
                        <td className="px-4 py-3 text-xs text-text-muted">{loc.phone || '-'}</td>
                        <td className="px-4 py-3 text-center font-medium text-text-main">
                          <span className="inline-flex items-center px-2 py-0.5 rounded bg-surface-subtle border border-border-subtle text-xs">
                            {loc.rooms_count ?? 0} ruangan
                          </span>
                        </td>
                        <td className="px-4 py-3 text-center">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500">
                            Aktif
                          </span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* View Tab 3: Spaces / Slots List */}
        {activeTab === 'spaces' && (
          <div className="rounded-xl bg-surface border border-border-subtle shadow-sm overflow-hidden">
            <div className="p-4 border-b border-border-subtle flex items-center justify-between">
              <h3 className="text-base font-semibold text-text-main">Master Slot, Meja & Tempat Tidur</h3>
              <span className="text-xs text-text-muted">{filteredSpaces.length} Unit Terdaftar</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-surface-subtle text-text-muted text-xs border-b border-border-subtle">
                  <tr>
                    <th className="px-4 py-3 font-medium">Kode Unit</th>
                    <th className="px-4 py-3 font-medium">Nama Slot</th>
                    <th className="px-4 py-3 font-medium">Ruangan & Gedung</th>
                    <th className="px-4 py-3 font-medium">Tipe</th>
                    <th className="px-4 py-3 font-medium">Status Ketersediaan</th>
                    <th className="px-4 py-3 font-medium text-center">Ubah Status Cepat</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {filteredSpaces.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-text-muted">
                        Belum ada unit slot atau bed terdaftar.
                      </td>
                    </tr>
                  ) : (
                    filteredSpaces.map((s) => (
                      <tr key={s.id} className="hover:bg-surface-subtle/50 transition-colors">
                        <td className="px-4 py-3 font-mono text-xs text-brand-primary font-medium">{s.code}</td>
                        <td className="px-4 py-3 font-semibold text-text-main">{s.name}</td>
                        <td className="px-4 py-3 text-xs text-text-muted">
                          {s.room_name} (Lt. {s.room_floor}) • {s.location_name}
                        </td>
                        <td className="px-4 py-3 capitalize text-xs text-text-muted">{s.type}</td>
                        <td className="px-4 py-3">{getStatusBadge(s.status)}</td>
                        <td className="px-4 py-3 text-center">
                          <select
                            value={s.status}
                            onChange={(e) => handleUpdateSpaceStatus(s.id, e.target.value)}
                            className="text-xs px-2 py-1 rounded bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                          >
                            <option value="available">Tersedia</option>
                            <option value="occupied">Terisi</option>
                            <option value="maintenance">Perbaikan</option>
                            <option value="reserved">Dipesan</option>
                          </select>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Modal: Tambah Lokasi */}
        {showLocationModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl bg-surface border border-border-subtle shadow-2xl overflow-hidden animate-in fade-in zoom-in-95">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main flex items-center gap-2">
                  <Building2 className="w-5 h-5 text-brand-primary" />
                  Tambah Gedung / Posko Baru
                </h3>
                <button
                  onClick={() => setShowLocationModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/facility/locations" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Gedung / Kantor *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="Contoh: Gedung Rawat Jalan, Cabang Bandung"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Lokasi</label>
                    <select
                      name="type"
                      defaultValue="branch"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="headquarters">Pusat (Headquarters)</option>
                      <option value="branch">Cabang (Branch)</option>
                      <option value="warehouse">Gudang Sentral</option>
                      <option value="site">Site Operasional</option>
                      <option value="outpost">Posko Wilayah</option>
                      <option value="other">Lain-lain</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kota</label>
                    <input
                      type="text"
                      name="city"
                      placeholder="Jakarta Selatan"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Alamat Lengkap</label>
                  <textarea
                    name="address"
                    rows={2}
                    placeholder="Jl. Gatot Subroto No. 42..."
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nomor Telepon / Kontak</label>
                  <input
                    type="text"
                    name="phone"
                    placeholder="021-12345678"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowLocationModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Gedung
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Tambah Ruangan */}
        {showRoomModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl bg-surface border border-border-subtle shadow-2xl overflow-hidden animate-in fade-in zoom-in-95">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main flex items-center gap-2">
                  <DoorOpen className="w-5 h-5 text-brand-primary" />
                  Tambah Ruangan Baru
                </h3>
                <button
                  onClick={() => setShowRoomModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/facility/rooms" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Gedung / Lokasi *</label>
                  <select
                    name="location_id"
                    required
                    defaultValue={locations[0]?.id}
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    {locations.map((loc) => (
                      <option key={loc.id} value={loc.id}>
                        {loc.name} ({loc.code})
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Ruangan *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="Contoh: Poli Umum 1, Gudang Obat Sentral"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Ruangan</label>
                    <select
                      name="type"
                      defaultValue="service"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="service">Pelayanan / Poli / Loket</option>
                      <option value="storage">Gudang Inventori / Depo</option>
                      <option value="inpatient">Kamar Rawat Inap</option>
                      <option value="procedure">Ruang Tindakan / UGD</option>
                      <option value="office">Kantor / Administrasi</option>
                      <option value="meeting">Ruang Rapat / Aula</option>
                      <option value="other">Lain-lain</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Lantai</label>
                    <input
                      type="text"
                      name="floor"
                      defaultValue="1"
                      placeholder="1, 2, Basement"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Kapasitas Maksimal</label>
                  <input
                    type="number"
                    name="capacity"
                    defaultValue={1}
                    min={1}
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Catatan Tambahan</label>
                  <textarea
                    name="notes"
                    rows={2}
                    placeholder="Fasilitas AC, Wastafel, dekat pintu darurat..."
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowRoomModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Ruangan
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Tambah Space / Slot */}
        {showSpaceModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl bg-surface border border-border-subtle shadow-2xl overflow-hidden animate-in fade-in zoom-in-95">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main flex items-center gap-2">
                  <Bed className="w-5 h-5 text-brand-primary" />
                  Tambah Unit Bed / Meja / Slot
                </h3>
                <button
                  onClick={() => setShowSpaceModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/facility/spaces" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Ruangan Induk *</label>
                  <select
                    name="room_id"
                    required
                    defaultValue={selectedRoomForSpace || rooms[0]?.id}
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    {rooms.map((rm) => (
                      <option key={rm.id} value={rm.id}>
                        {rm.name} ({rm.location_name || 'Lokasi'})
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Slot / Bed *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="Contoh: Bed 01, Meja Loket Pendaftaran 2"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Unit</label>
                    <select
                      name="type"
                      defaultValue="bed"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="bed">Tempat Tidur (Bed)</option>
                      <option value="desk">Meja (Desk / Loket)</option>
                      <option value="chair">Kursi (Dental / Treatment)</option>
                      <option value="dock">Loading Dock / Rak</option>
                      <option value="booth">Booth / Stand</option>
                      <option value="other">Lain-lain</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Status Awal</label>
                    <select
                      name="status"
                      defaultValue="available"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="available">Tersedia</option>
                      <option value="occupied">Terisi</option>
                      <option value="maintenance">Perbaikan</option>
                      <option value="reserved">Dipesan</option>
                    </select>
                  </div>
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowSpaceModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Unit
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
