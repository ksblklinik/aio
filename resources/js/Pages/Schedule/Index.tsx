import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  CalendarClock,
  Users,
  Tv,
  Plus,
  Play,
  RotateCw,
  CheckCircle2,
  XCircle,
  ArrowRightLeft,
  Calendar,
  Clock,
  Ticket,
  Search,
  X,
  ExternalLink,
  ChevronRight
} from 'lucide-react';

interface ResourceSchedule {
  id: number;
  staff_contact_id: number | null;
  staff_name?: string | null;
  room_id: number | null;
  room_name?: string | null;
  day_of_week: number;
  start_time: string;
  end_time: string;
  quota: number;
  is_active: boolean;
}

interface Appointment {
  id: number;
  appointment_number: string;
  client_contact_id: number | null;
  client_name: string;
  client_phone: string | null;
  staff_contact_id: number | null;
  staff_name?: string | null;
  room_id: number | null;
  room_name?: string | null;
  appointment_date: string;
  start_time: string;
  end_time: string | null;
  status: 'booked' | 'confirmed' | 'checked_in' | 'in_progress' | 'completed' | 'cancelled' | 'no_show';
  notes: string | null;
}

interface CalledTicket {
  id: number;
  ticket_number: string;
  prefix: string;
  service_name: string;
  room_name?: string | null;
  space_name?: string | null;
  client_name?: string;
  status: string;
  called_at?: string | null;
  call_count: number;
}

interface ServiceSummary {
  prefix: string;
  service_name: string;
  waiting_count: number;
  completed_count: number;
  current_number?: string | null;
}

interface DisplayState {
  date: string;
  latest_called?: CalledTicket | null;
  called_tickets: CalledTicket[];
  services_summary: ServiceSummary[];
}

interface QueueMetrics {
  total_tickets: number;
  total_waiting: number;
  total_called: number;
  total_serving: number;
  total_completed: number;
  total_skipped: number;
}

interface FacilityRoom {
  id: number;
  code: string;
  name: string;
  type: string;
}

interface FacilitySpace {
  id: number;
  room_id: number;
  code: string;
  name: string;
}

interface ScheduleIndexProps {
  schedules: ResourceSchedule[];
  appointments: Appointment[];
  displayState: DisplayState;
  metrics: QueueMetrics;
  rooms: FacilityRoom[];
  spaces: FacilitySpace[];
  currentDate: string;
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Index({
  schedules = [],
  appointments = [],
  displayState,
  metrics,
  rooms = [],
  spaces = [],
  currentDate = new Date().toISOString().split('T')[0],
}: ScheduleIndexProps) {
  const [activeTab, setActiveTab] = useState<'console' | 'appointments' | 'schedules'>('console');
  const [selectedPrefix, setSelectedPrefix] = useState('A');
  const [selectedRoomId, setSelectedRoomId] = useState<number | ''>(rooms[0]?.id || '');
  const [selectedSpaceId, setSelectedSpaceId] = useState<number | ''>(spaces[0]?.id || '');
  const [appointmentSearch, setAppointmentSearch] = useState('');

  // Modals
  const [showIssueModal, setShowIssueModal] = useState(false);
  const [showAppointmentModal, setShowAppointmentModal] = useState(false);
  const [showScheduleModal, setShowScheduleModal] = useState(false);
  const [showTransferModal, setShowTransferModal] = useState(false);
  const [activeTicketForTransfer, setActiveTicketForTransfer] = useState<CalledTicket | null>(null);

  const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

  const filteredAppointments = appointments.filter((apt) => {
    return (
      apt.client_name.toLowerCase().includes(appointmentSearch.toLowerCase()) ||
      apt.appointment_number.toLowerCase().includes(appointmentSearch.toLowerCase())
    );
  });

  const latestCalled = displayState?.latest_called;

  return (
    <AppLayout title="Universal Scheduling & Human Queue Engine">
      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Top Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="p-2 rounded-xl bg-brand-primary/10 text-brand-primary">
                <CalendarClock className="w-6 h-6" />
              </span>
              <h1 className="text-2xl font-bold tracking-tight text-text-main">
                Penjadwalan & Antrean Layanan
              </h1>
            </div>
            <p className="text-sm text-text-muted mt-1">
              Konsol pemanggilan loket, antrean walk-in, reservasi janji temu (appointment), dan display TV ruang tunggu.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <a
              href="/schedule/display"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-main hover:bg-surface-subtle transition shadow-sm"
            >
              <Tv className="w-4 h-4 text-brand-primary" />
              Buka Display TV
              <ExternalLink className="w-3.5 h-3.5 text-text-muted" />
            </a>
            <button
              onClick={() => setShowIssueModal(true)}
              className="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium rounded-lg border border-border-subtle bg-surface text-text-main hover:bg-surface-subtle transition shadow-sm"
            >
              <Ticket className="w-4 h-4 text-emerald-500" />
              Cetak Tiket Kios
            </button>
            <button
              onClick={() => setShowAppointmentModal(true)}
              className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover transition shadow-sm"
            >
              <Plus className="w-4 h-4" />
              Booking Janji Temu
            </button>
          </div>
        </div>

        {/* Quick Stat Cards */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-text-muted font-medium">Total Tiket</span>
            <p className="text-xl font-bold text-text-main mt-0.5">{metrics.total_tickets || 0}</p>
          </div>
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-amber-500 font-medium">Menunggu</span>
            <p className="text-xl font-bold text-amber-600 dark:text-amber-400 mt-0.5">{metrics.total_waiting || 0}</p>
          </div>
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-blue-500 font-medium">Dipanggil</span>
            <p className="text-xl font-bold text-blue-600 dark:text-blue-400 mt-0.5">{metrics.total_called || 0}</p>
          </div>
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-indigo-500 font-medium">Sedang Layan</span>
            <p className="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-0.5">{metrics.total_serving || 0}</p>
          </div>
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-emerald-500 font-medium">Selesai</span>
            <p className="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">{metrics.total_completed || 0}</p>
          </div>
          <div className="p-3.5 rounded-xl bg-surface border border-border-subtle shadow-sm">
            <span className="text-xs text-rose-500 font-medium">Janji Temu</span>
            <p className="text-xl font-bold text-rose-600 dark:text-rose-400 mt-0.5">{appointments.length}</p>
          </div>
        </div>

        {/* Primary Tabs */}
        <div className="border-b border-border-subtle">
          <nav className="flex space-x-6">
            <button
              onClick={() => setActiveTab('console')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'console'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Users className="w-4 h-4" />
              Konsol Pemanggil Antrean
            </button>
            <button
              onClick={() => setActiveTab('appointments')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'appointments'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Calendar className="w-4 h-4" />
              Janji Temu ({appointments.length})
            </button>
            <button
              onClick={() => setActiveTab('schedules')}
              className={`pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition ${
                activeTab === 'schedules'
                  ? 'border-brand-primary text-brand-primary'
                  : 'border-transparent text-text-muted hover:text-text-main'
              }`}
            >
              <Clock className="w-4 h-4" />
              Jadwal Ketersediaan ({schedules.length})
            </button>
          </nav>
        </div>

        {/* Tab 1: Live Calling Console */}
        {activeTab === 'console' && (
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            {/* Left Console Action Controls */}
            <div className="lg:col-span-7 space-y-6">
              {/* Loket & Counter Setup */}
              <div className="p-5 rounded-2xl bg-surface border border-border-subtle shadow-sm space-y-4">
                <h2 className="font-semibold text-text-main text-sm">Pengaturan Loket Pemanggilan</h2>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Grup / Prefix</label>
                    <select
                      value={selectedPrefix}
                      onChange={(e) => setSelectedPrefix(e.target.value)}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-bold"
                    >
                      <option value="A">Prefix A (Poli Umum / CS)</option>
                      <option value="B">Prefix B (Poli Gigi / Kasir)</option>
                      <option value="C">Prefix C (Konsultasi / Tindakan)</option>
                      <option value="F">Prefix F (Farmasi / Apotek)</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Ruangan Pelayanan</label>
                    <select
                      value={selectedRoomId}
                      onChange={(e) => setSelectedRoomId(Number(e.target.value))}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      {rooms.map((r) => (
                        <option key={r.id} value={r.id}>
                          {r.name} ({r.code})
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Meja / Loket Unit</label>
                    <select
                      value={selectedSpaceId}
                      onChange={(e) => setSelectedSpaceId(Number(e.target.value))}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    >
                      <option value="">-- Tanpa Nomor Loket --</option>
                      {spaces.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.name} ({s.code})
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                {/* Big Calling Button Form */}
                <form action="/schedule/queue/call-next" method="POST" className="pt-2">
                  <input type="hidden" name="prefix" value={selectedPrefix} />
                  {selectedRoomId && <input type="hidden" name="room_id" value={selectedRoomId} />}
                  {selectedSpaceId && <input type="hidden" name="space_id" value={selectedSpaceId} />}
                  <button
                    type="submit"
                    className="w-full py-4 rounded-xl bg-gradient-to-r from-brand-primary to-indigo-600 hover:opacity-95 text-white font-bold text-lg shadow-lg flex items-center justify-center gap-3 transition transform active:scale-[0.99]"
                  >
                    <Play className="w-6 h-6 fill-current" />
                    PANGGIL NOMOR BERIKUTNYA (PREFIX {selectedPrefix})
                  </button>
                </form>
              </div>

              {/* Active Ticket Control Panel */}
              {latestCalled && (
                <div className="p-6 rounded-2xl bg-surface border border-brand-primary/30 shadow-md space-y-4">
                  <div className="flex items-center justify-between border-b border-border-subtle pb-3">
                    <div>
                      <span className="text-xs text-text-muted uppercase tracking-wider font-semibold">
                        SEDANG DIPANGGIL SAAT INI
                      </span>
                      <div className="text-4xl font-black font-mono text-brand-primary mt-1">
                        {latestCalled.ticket_number}
                      </div>
                    </div>

                    <div className="text-right">
                      <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-500 border border-blue-500/20 capitalize">
                        Status: {latestCalled.status}
                      </span>
                      <p className="text-xs text-text-muted mt-1">
                        Panggilan ke: <span className="font-bold text-text-main">{latestCalled.call_count}</span>
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center justify-between text-sm text-text-muted">
                    <div>
                      Klien / Pasien: <span className="font-semibold text-text-main">{latestCalled.client_name || 'Umum'}</span>
                    </div>
                    <div>
                      Ruangan: <span className="font-semibold text-text-main">{latestCalled.room_name || 'Loket'}</span>
                    </div>
                  </div>

                  {/* Actions for current ticket */}
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2">
                    <form action={`/schedule/queue/${latestCalled.id}/recall`} method="POST">
                      <button
                        type="submit"
                        className="w-full py-2.5 px-3 rounded-lg border border-border-subtle bg-surface hover:bg-surface-subtle text-xs font-semibold text-text-main flex items-center justify-center gap-1.5 transition shadow-2xs"
                      >
                        <RotateCw className="w-3.5 h-3.5 text-brand-primary" /> Panggil Ulang
                      </button>
                    </form>

                    <form action={`/schedule/queue/${latestCalled.id}/serving`} method="POST">
                      <button
                        type="submit"
                        className="w-full py-2.5 px-3 rounded-lg border border-border-subtle bg-surface hover:bg-surface-subtle text-xs font-semibold text-indigo-600 dark:text-indigo-400 flex items-center justify-center gap-1.5 transition shadow-2xs"
                      >
                        <Clock className="w-3.5 h-3.5" /> Mulai Layan
                      </button>
                    </form>

                    <form action={`/schedule/queue/${latestCalled.id}/complete`} method="POST">
                      <button
                        type="submit"
                        className="w-full py-2.5 px-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-xs font-semibold text-white flex items-center justify-center gap-1.5 transition shadow-sm"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" /> Selesai
                      </button>
                    </form>

                    <form action={`/schedule/queue/${latestCalled.id}/skip`} method="POST">
                      <button
                        type="submit"
                        className="w-full py-2.5 px-3 rounded-lg border border-rose-500/30 text-rose-500 hover:bg-rose-500/10 text-xs font-semibold flex items-center justify-center gap-1.5 transition"
                      >
                        <XCircle className="w-3.5 h-3.5" /> Lewati
                      </button>
                    </form>
                  </div>

                  <div className="pt-2 text-right">
                    <button
                      onClick={() => {
                        setActiveTicketForTransfer(latestCalled);
                        setShowTransferModal(true);
                      }}
                      className="text-xs text-brand-primary hover:underline font-semibold inline-flex items-center gap-1"
                    >
                      <ArrowRightLeft className="w-3.5 h-3.5" /> Transfer Antrean ke Layanan Lain
                    </button>
                  </div>
                </div>
              )}
            </div>

            {/* Right Summary Grid */}
            <div className="lg:col-span-5 space-y-4">
              <div className="p-5 rounded-2xl bg-surface border border-border-subtle shadow-sm space-y-3">
                <h3 className="font-semibold text-text-main text-sm flex items-center gap-2">
                  <Users className="w-4 h-4 text-brand-primary" />
                  Antrean Menunggu per Layanan
                </h3>

                <div className="divide-y divide-border-subtle">
                  {displayState.services_summary.map((srv) => (
                    <div key={srv.prefix} className="py-2.5 flex items-center justify-between text-sm">
                      <div>
                        <span className="font-mono font-bold text-brand-primary mr-2">[{srv.prefix}]</span>
                        <span className="font-medium text-text-main">{srv.service_name}</span>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="text-xs text-text-muted">
                          Sisa: <span className="font-bold text-amber-500">{srv.waiting_count}</span>
                        </span>
                        <span className="font-mono font-bold text-text-main text-xs px-2 py-0.5 rounded bg-surface-subtle">
                          {srv.current_number || '-'}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Tab 2: Appointments */}
        {activeTab === 'appointments' && (
          <div className="space-y-4">
            <div className="flex flex-col sm:flex-row gap-3 items-center justify-between">
              <div className="relative w-full sm:w-80">
                <Search className="absolute left-3 top-2.5 w-4 h-4 text-text-muted" />
                <input
                  type="text"
                  placeholder="Cari nama pasien, no appointment..."
                  value={appointmentSearch}
                  onChange={(e) => setAppointmentSearch(e.target.value)}
                  className="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary transition"
                />
              </div>

              <button
                onClick={() => setShowAppointmentModal(true)}
                className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
              >
                <Plus className="w-3.5 h-3.5" /> Booking Janji Temu Baru
              </button>
            </div>

            <div className="rounded-xl bg-surface border border-border-subtle overflow-hidden shadow-sm">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                  <thead>
                    <tr className="bg-surface-subtle border-b border-border-subtle text-xs font-semibold text-text-muted uppercase tracking-wider">
                      <th className="py-3 px-4">No. Booking</th>
                      <th className="py-3 px-4">Klien / Pasien</th>
                      <th className="py-3 px-4">Petugas / Dokter</th>
                      <th className="py-3 px-4">Jam Pelayanan</th>
                      <th className="py-3 px-4">Status</th>
                      <th className="py-3 px-4 text-center">Aksi Check-In</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {filteredAppointments.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-10 text-center text-text-muted">
                          Belum ada jadwal janji temu untuk tanggal hari ini.
                        </td>
                      </tr>
                    ) : (
                      filteredAppointments.map((apt) => (
                        <tr key={apt.id} className="hover:bg-surface-subtle/50 transition">
                          <td className="py-3 px-4 font-mono font-semibold text-text-main">
                            {apt.appointment_number}
                          </td>
                          <td className="py-3 px-4">
                            <div className="font-semibold text-text-main">{apt.client_name}</div>
                            {apt.client_phone && <div className="text-xs text-text-muted">{apt.client_phone}</div>}
                          </td>
                          <td className="py-3 px-4 text-text-muted">
                            {apt.staff_name || 'Dokter Jaga'}
                          </td>
                          <td className="py-3 px-4 font-mono text-text-main">
                            {apt.start_time}
                          </td>
                          <td className="py-3 px-4">
                            <span
                              className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize ${
                                apt.status === 'checked_in'
                                  ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20'
                                  : apt.status === 'cancelled'
                                  ? 'bg-rose-500/10 text-rose-500 border border-rose-500/20'
                                  : 'bg-blue-500/10 text-blue-500 border border-blue-500/20'
                              }`}
                            >
                              {apt.status}
                            </span>
                          </td>
                          <td className="py-3 px-4 text-center">
                            {apt.status === 'booked' || apt.status === 'confirmed' ? (
                              <form action={`/schedule/appointments/${apt.id}/check-in`} method="POST">
                                <button
                                  type="submit"
                                  className="px-3 py-1 text-xs font-semibold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs inline-flex items-center gap-1"
                                >
                                  Check-in & Antre <ChevronRight className="w-3.5 h-3.5" />
                                </button>
                              </form>
                            ) : (
                              <span className="text-xs text-text-muted italic">Sudah Check-In</span>
                            )}
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

        {/* Tab 3: Resource Schedules */}
        {activeTab === 'schedules' && (
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <div>
                <h3 className="font-bold text-text-main">Master Jadwal Ketersediaan</h3>
                <p className="text-xs text-text-muted">Pengaturan hari kerja, jam buka, dan kuota pelayanan harian.</p>
              </div>
              <button
                onClick={() => setShowScheduleModal(true)}
                className="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
              >
                <Plus className="w-3.5 h-3.5" /> Tambah Jadwal Petugas
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {schedules.map((sch) => (
                <div
                  key={sch.id}
                  className="p-5 rounded-xl bg-surface border border-border-subtle hover:border-brand-primary/40 transition shadow-sm space-y-3"
                >
                  <div className="flex items-start justify-between">
                    <div>
                      <span className="inline-block px-2 py-0.5 rounded text-xs font-bold bg-brand-primary/10 text-brand-primary mb-1">
                        Hari {dayNames[sch.day_of_week] || 'Setiap Hari'}
                      </span>
                      <h4 className="font-semibold text-text-main">{sch.staff_name || 'Petugas Umum'}</h4>
                      <p className="text-xs text-text-muted">{sch.room_name || 'Ruangan Pelayanan'}</p>
                    </div>
                    <Clock className="w-5 h-5 text-text-muted" />
                  </div>

                  <div className="pt-3 border-t border-border-subtle flex items-center justify-between text-xs">
                    <span className="font-mono text-text-main font-semibold">
                      {sch.start_time} - {sch.end_time}
                    </span>
                    <span className="text-text-muted">
                      Kuota: <span className="font-bold text-brand-primary">{sch.quota}</span>
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Modal: Cetak Tiket Kiosk Mandiri */}
        {showIssueModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Ticket className="w-5 h-5 text-emerald-500" />
                  <h3 className="font-bold text-text-main">Penerbitan Tiket Antrean (Kiosk)</h3>
                </div>
                <button
                  onClick={() => setShowIssueModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/schedule/queue/issue" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Pilih Layanan / Kelompok *</label>
                  <select
                    name="prefix"
                    required
                    defaultValue="A"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-bold"
                  >
                    <option value="A">Prefix A - Poli Umum / Layanan Dasar</option>
                    <option value="B">Prefix B - Poli Gigi / Layanan Khusus</option>
                    <option value="C">Prefix C - Konsultasi & Tindakan</option>
                    <option value="F">Prefix F - Instalasi Farmasi / Apotek</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Pasien / Pengunjung</label>
                  <input
                    type="text"
                    name="client_name"
                    defaultValue="Pengunjung Umum"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowIssueModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm"
                  >
                    Cetak Tiket
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Booking Appointment */}
        {showAppointmentModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Calendar className="w-5 h-5 text-brand-primary" />
                  <h3 className="font-bold text-text-main">Booking Janji Temu Baru</h3>
                </div>
                <button
                  onClick={() => setShowAppointmentModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/schedule/appointments" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Klien / Pasien *</label>
                  <input
                    type="text"
                    name="client_name"
                    required
                    placeholder="Contoh: Siti Rahmawati"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">No. Telepon / WhatsApp</label>
                  <input
                    type="text"
                    name="client_phone"
                    placeholder="0812..."
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tanggal *</label>
                    <input
                      type="date"
                      name="appointment_date"
                      required
                      defaultValue={currentDate}
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Jam Mulai *</label>
                    <input
                      type="time"
                      name="start_time"
                      required
                      defaultValue="09:00"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowAppointmentModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Booking
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Tambah Jadwal Petugas */}
        {showScheduleModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Clock className="w-5 h-5 text-brand-primary" />
                  <h3 className="font-bold text-text-main">Tambah Jadwal Ketersediaan</h3>
                </div>
                <button
                  onClick={() => setShowScheduleModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action="/schedule/schedules" method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Hari Kerja *</label>
                  <select
                    name="day_of_week"
                    required
                    defaultValue="1"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    <option value="1">Senin</option>
                    <option value="2">Selasa</option>
                    <option value="3">Rabu</option>
                    <option value="4">Kamis</option>
                    <option value="5">Jumat</option>
                    <option value="6">Sabtu</option>
                    <option value="0">Minggu</option>
                  </select>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Jam Mulai</label>
                    <input
                      type="time"
                      name="start_time"
                      defaultValue="08:00"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Jam Selesai</label>
                    <input
                      type="time"
                      name="end_time"
                      defaultValue="12:00"
                      className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Kuota Pasien / Sesi</label>
                  <input
                    type="number"
                    name="quota"
                    defaultValue="30"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowScheduleModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-lg text-text-muted hover:bg-surface-subtle"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-medium rounded-lg bg-brand-primary text-white hover:bg-brand-primary-hover shadow-sm"
                  >
                    Simpan Jadwal
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Transfer Antrean */}
        {showTransferModal && activeTicketForTransfer && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xl w-full max-w-md overflow-hidden animate-in fade-in duration-200">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div>
                  <h3 className="font-bold text-text-main">Transfer Antrean</h3>
                  <p className="text-xs text-text-muted">Tiket: {activeTicketForTransfer.ticket_number}</p>
                </div>
                <button
                  onClick={() => setShowTransferModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:bg-surface-subtle"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form action={`/schedule/queue/${activeTicketForTransfer.id}/transfer`} method="POST" className="p-5 space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Layanan Tujuan *</label>
                  <select
                    name="target_prefix"
                    required
                    defaultValue="F"
                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-bold"
                  >
                    <option value="F">Prefix F - Farmasi / Tebus Obat</option>
                    <option value="B">Prefix B - Kasir / Pembayaran</option>
                    <option value="C">Prefix C - Laboratorium / Tindakan Lanjutan</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Catatan Pengalihan</label>
                  <input
                    type="text"
                    name="notes"
                    placeholder="Contoh: Resep obat racikan, pemeriksaan darah..."
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
                    Proses Transfer
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
