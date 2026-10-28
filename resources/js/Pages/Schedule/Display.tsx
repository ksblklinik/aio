import { useEffect, useState, useRef, useCallback } from 'react';
import {
  Volume2,
  VolumeX,
  Tv,
  Users,
  CheckCircle2,
  Sparkles,
  Maximize2,
  Radio
} from 'lucide-react';
import { useSse } from '../../hooks/useSse';

interface CalledTicket {
  id: number;
  ticket_number: string;
  prefix: string;
  service_name: string;
  room_name?: string | null;
  space_name?: string | null;
  status: string;
  called_at?: string | null;
  tts_announcement?: string;
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

interface DisplayProps {
  displayState: DisplayState;
  currentTenant?: {
    id: number;
    name: string;
  } | null;
}

export default function Display({ displayState: initialData, currentTenant }: DisplayProps) {
  const [data, setData] = useState<DisplayState>(initialData);
  const [soundEnabled, setSoundEnabled] = useState(false);
  const [currentTime, setCurrentTime] = useState(new Date().toLocaleTimeString('id-ID'));
  const lastAnnouncedTicketRef = useRef<string | null>(initialData.latest_called?.ticket_number || null);

  // Update clock every second
  useEffect(() => {
    const timer = setInterval(() => {
      setCurrentTime(new Date().toLocaleTimeString('id-ID'));
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  const refreshDisplayData = useCallback(async () => {
    try {
      const res = await fetch('/api/schedule/queue/display');
      const json = await res.json();
      if (json.status === 'success' && json.data) {
        setData(json.data);
      }
    } catch {
      // silent
    }
  }, []);

  const speakTicket = useCallback((text: string) => {
    if (soundEnabled && 'speechSynthesis' in window) {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'id-ID';
      utterance.rate = 0.9;
      window.speechSynthesis.speak(utterance);
    }
  }, [soundEnabled]);

  // Real-time Push via Universal Server-Sent Events (SSE) Bus
  const { isConnected } = useSse({
    channel: 'queue',
    tenantId: currentTenant?.id,
    events: ['ticket_called', 'ticket_issued'],
    fallbackPollIntervalMs: 8000,
    onEvent: (evt) => {
      if (evt.event === 'ticket_called' && evt.data) {
        const payload = evt.data;
        const ticketNum = payload.ticket_number;
        const announcement = payload.tts_announcement || `Nomor antrean ${ticketNum}`;

        if (ticketNum && ticketNum !== lastAnnouncedTicketRef.current) {
          lastAnnouncedTicketRef.current = ticketNum;
          speakTicket(announcement);
        }

        refreshDisplayData();
      } else if (evt.event === 'ticket_issued') {
        refreshDisplayData();
      }
    },
  });

  // Polling penyelarasan cadangan berfrekuensi rendah (setiap 15 detik) untuk integritas data menyeluruh
  useEffect(() => {
    const interval = setInterval(() => {
      refreshDisplayData();
    }, 15000);
    return () => clearInterval(interval);
  }, [refreshDisplayData]);

  const toggleSound = () => {
    setSoundEnabled((prev) => !prev);
    if (!soundEnabled && 'speechSynthesis' in window) {
      const u = new SpeechSynthesisUtterance('Audio panggilan antrean aktif.');
      u.lang = 'id-ID';
      window.speechSynthesis.speak(u);
    }
  };

  const toggleFullScreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(() => {});
    } else {
      document.exitFullscreen().catch(() => {});
    }
  };

  const latest = data.latest_called;

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans select-none overflow-hidden">
      {/* Top Header Bar */}
      <header className="px-8 py-5 border-b border-slate-800 bg-slate-900/80 backdrop-blur-md flex items-center justify-between">
        <div className="flex items-center gap-4">
          <div className="p-3 rounded-2xl bg-indigo-500/20 text-indigo-400 border border-indigo-500/30">
            <Tv className="w-8 h-8" />
          </div>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-black tracking-tight text-white uppercase">
                {currentTenant?.name || 'Sistem Antrean Terpadu'}
              </h1>
              {isConnected ? (
                <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                  <Radio className="w-3 h-3" />
                  REAL-TIME (SSE)
                </span>
              ) : (
                <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-amber-400" />
                  POLLING MODE
                </span>
              )}
            </div>
            <p className="text-xs text-slate-400 font-medium">Layar Informasi Panggilan Antrean Ruang Tunggu</p>
          </div>
        </div>

        <div className="flex items-center gap-6">
          <div className="flex items-center gap-2">
            <button
              onClick={toggleSound}
              className={`p-2.5 rounded-xl border text-sm font-medium flex items-center gap-2 transition ${
                soundEnabled
                  ? 'bg-emerald-500/20 border-emerald-500/40 text-emerald-300'
                  : 'bg-slate-800 border-slate-700 text-slate-400 hover:text-white'
              }`}
            >
              {soundEnabled ? <Volume2 className="w-5 h-5" /> : <VolumeX className="w-5 h-5" />}
              <span className="hidden sm:inline">{soundEnabled ? 'Suara Aktif' : 'Aktifkan Suara'}</span>
            </button>

            <button
              onClick={toggleFullScreen}
              className="p-2.5 rounded-xl border border-slate-700 bg-slate-800 text-slate-400 hover:text-white transition"
              title="Layar Penuh"
            >
              <Maximize2 className="w-5 h-5" />
            </button>
          </div>

          <div className="text-right">
            <div className="text-2xl font-mono font-black text-amber-400">{currentTime}</div>
            <div className="text-xs text-slate-400 font-medium">{data.date}</div>
          </div>
        </div>
      </header>

      {/* Main Display Grid */}
      <main className="flex-1 p-8 grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
        {/* Left: Giant Calling Card */}
        <div className="lg:col-span-7 flex flex-col justify-center">
          <div className="p-10 rounded-3xl bg-gradient-to-br from-slate-900 to-indigo-950/60 border border-indigo-500/30 shadow-2xl relative overflow-hidden flex flex-col items-center justify-center text-center">
            <div className="absolute top-4 right-4 flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 text-xs font-semibold animate-pulse">
              <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
              PANGGILAN AKTIF
            </div>

            <p className="text-sm font-bold tracking-widest text-indigo-400 uppercase mb-2">
              NOMOR ANTREAN SAAT INI
            </p>

            <div className="text-8xl sm:text-9xl font-black font-mono tracking-tight text-white my-6 drop-shadow-[0_10px_25px_rgba(99,102,241,0.4)]">
              {latest ? latest.ticket_number : '---'}
            </div>

            <div className="w-full max-w-md pt-6 border-t border-slate-800/80 space-y-2">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                SILAKAN MENUJU KE:
              </p>
              <div className="text-3xl font-extrabold text-amber-400">
                {latest ? (
                  <>
                    {latest.room_name || latest.service_name || 'Loket Pelayanan'}
                    {latest.space_name ? ` • ${latest.space_name}` : ''}
                  </>
                ) : (
                  'Menunggu panggilan berikutnya'
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Right: Counter Status Grid */}
        <div className="lg:col-span-5 flex flex-col justify-between space-y-6">
          {/* Services Counters */}
          <div className="space-y-4">
            <h2 className="text-sm font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
              <Users className="w-4 h-4 text-indigo-400" />
              STATUS ANTREAN PER KELOMPOK
            </h2>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {data.services_summary.map((srv) => (
                <div
                  key={srv.prefix}
                  className="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-md flex items-center justify-between"
                >
                  <div>
                    <span className="inline-block px-2 py-0.5 rounded-md text-xs font-mono font-bold bg-indigo-500/20 text-indigo-300 mb-1">
                      PREFIX {srv.prefix}
                    </span>
                    <h3 className="font-bold text-white text-base truncate max-w-[150px]">
                      {srv.service_name}
                    </h3>
                    <p className="text-xs text-slate-400 mt-1">
                      Sisa antre: <span className="text-amber-400 font-bold">{srv.waiting_count}</span>
                    </p>
                  </div>

                  <div className="text-right">
                    <span className="text-xs text-slate-400 font-medium block">Nomor</span>
                    <span className="text-2xl font-black font-mono text-emerald-400">
                      {srv.current_number || '-'}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Recently Called Stream */}
          <div className="p-6 rounded-2xl bg-slate-900/60 border border-slate-800/80">
            <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">
              RIWAYAT PANGGILAN TERAKHIR
            </h3>
            <div className="space-y-2">
              {data.called_tickets.slice(0, 4).map((ticket) => (
                <div
                  key={ticket.id}
                  className="px-4 py-2.5 rounded-xl bg-slate-800/60 border border-slate-700/50 flex items-center justify-between"
                >
                  <span className="font-mono font-bold text-white text-base">
                    {ticket.ticket_number}
                  </span>
                  <span className="text-xs font-semibold text-slate-300">
                    {ticket.room_name || ticket.service_name}
                  </span>
                  <span className="text-xs text-emerald-400 font-medium flex items-center gap-1">
                    <CheckCircle2 className="w-3.5 h-3.5" /> {ticket.status}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </main>

      {/* Bottom Running Text Footer */}
      <footer className="px-8 py-3 bg-slate-900 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
        <div className="flex items-center gap-2">
          <Sparkles className="w-4 h-4 text-amber-400" />
          <span>Mohon perhatikan nomor tiket antrean dan menuju ke loket atau ruangan saat nomor Anda dipanggil.</span>
        </div>
        <div className="font-mono">
          Powered by Universal Antigravity Platform
        </div>
      </footer>
    </div>
  );
}
