import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  Webhook, 
  Plus, 
  Send, 
  Trash2, 
  Power, 
  Copy, 
  Check, 
  Eye, 
  EyeOff, 
  CheckCircle2, 
  AlertCircle, 
  X,
  RefreshCw,
  Globe
} from 'lucide-react';

interface WebhookItem {
  id: number;
  tenant_id: number;
  name: string;
  url: string;
  secret: string;
  events: string;
  events_list: string[];
  is_active: number;
  created_at: string;
}

interface DeliveryItem {
  id: number;
  tenant_id: number;
  webhook_id: number;
  webhook_name: string;
  webhook_url: string;
  event: string;
  payload: string;
  response_code: number | null;
  response_body: string | null;
  duration_ms: number | null;
  status: 'success' | 'failed' | 'pending';
  created_at: string;
}

interface WebhooksPageProps {
  webhooks: WebhookItem[];
  deliveries: DeliveryItem[];
  currentTenant: { id: number; name: string; slug: string } | null;
}

const AVAILABLE_EVENTS = [
  { id: 'project.created', label: 'Proyek Dibuat (project.created)' },
  { id: 'project.updated', label: 'Proyek Diperbarui (project.updated)' },
  { id: 'project.deleted', label: 'Proyek Dihapus (project.deleted)' },
  { id: 'auth.login', label: 'Pengguna Masuk (auth.login)' },
  { id: 'auth.register', label: 'Pengguna Baru Terdaftar (auth.register)' },
  { id: 'api_token.created', label: 'API Token Dibuat (api_token.created)' },
];

export default function WebhooksIndex({
  webhooks,
  deliveries,
}: WebhooksPageProps) {
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [selectedDelivery, setSelectedDelivery] = useState<DeliveryItem | null>(null);
  const [revealedSecrets, setRevealedSecrets] = useState<Record<number, boolean>>({});
  const [copiedSecretId, setCopiedSecretId] = useState<number | null>(null);

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    url: '',
    secret: '',
    events: ['*'] as string[],
  });

  const generateRandomSecret = () => {
    const chars = '0123456789abcdef';
    let res = 'whsec_';
    for (let i = 0; i < 32; i++) {
      res += chars[Math.floor(Math.random() * chars.length)];
    }
    setData('secret', res);
  };

  const openCreateModal = () => {
    generateRandomSecret();
    setShowCreateModal(true);
  };

  const handleCreateSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/settings/webhooks', {
      onSuccess: () => {
        setShowCreateModal(false);
        reset();
      },
    });
  };

  const handleToggle = (id: number) => {
    router.post(`/settings/webhooks/${id}/toggle`, {}, { preserveScroll: true });
  };

  const handleDelete = (id: number, name: string) => {
    if (confirm(`Apakah Anda yakin ingin menghapus webhook "${name}"? Seluruh konfigurasi dan riwayat pengiriman akan dihapus.`)) {
      router.post(`/settings/webhooks/${id}/delete`, {}, { preserveScroll: true });
    }
  };

  const handleTestPing = (id: number) => {
    router.post(`/settings/webhooks/${id}/test`, {}, { preserveScroll: true });
  };

  const toggleSecretReveal = (id: number) => {
    setRevealedSecrets(prev => ({ ...prev, [id]: !prev[id] }));
  };

  const copySecret = (id: number, secret: string) => {
    navigator.clipboard.writeText(secret);
    setCopiedSecretId(id);
    setTimeout(() => setCopiedSecretId(null), 2000);
  };

  const toggleEventSelection = (eventId: string) => {
    if (eventId === '*') {
      setData('events', ['*']);
      return;
    }

    let updated = data.events.filter(e => e !== '*');
    if (updated.includes(eventId)) {
      updated = updated.filter(e => e !== eventId);
      if (updated.length === 0) {
        updated = ['*'];
      }
    } else {
      updated.push(eventId);
    }
    setData('events', updated);
  };

  return (
    <AppLayout title="Webhooks & Integrasi Outbound">
      <div className="max-w-6xl mx-auto space-y-8">
        {/* Header Banner */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-surface p-6 rounded-2xl border border-border-subtle shadow-xs">
          <div className="flex items-start gap-3.5">
            <div className="p-3 rounded-xl bg-brand-primary-light text-brand-primary border border-brand-primary/20 shadow-2xs">
              <Webhook className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-text-main">Webhooks & Otomasi</h2>
              <p className="text-xs text-text-muted mt-1 max-w-xl leading-relaxed">
                Kirimkan payload event secara real-time ke sistem pihak ketiga (Zapier, Make, n8n, Slack, server internal). 
                Setiap request ditandatangani secara aman dengan header <code className="px-1.5 py-0.5 rounded bg-surface-subtle border border-border-subtle font-mono text-[11px]">X-Aio-Signature (HMAC-SHA256)</code>.
              </p>
            </div>
          </div>

          <button
            onClick={openCreateModal}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold bg-brand-primary text-white hover:bg-brand-primary-hover shadow-xs transition-colors shrink-0"
          >
            <Plus className="w-4 h-4" />
            <span>Daftarkan Webhook</span>
          </button>
        </div>

        {/* Registered Webhooks List */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-bold text-text-main flex items-center gap-2">
              <span>Webhook Terdaftar</span>
              <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-surface-subtle text-text-muted border border-border-subtle">
                {webhooks.length}
              </span>
            </h3>
          </div>

          {webhooks.length === 0 ? (
            <div className="p-12 text-center rounded-2xl bg-surface border border-border-subtle">
              <div className="w-12 h-12 rounded-2xl bg-surface-subtle text-text-muted flex items-center justify-center mx-auto mb-3">
                <Globe className="w-6 h-6 opacity-60" />
              </div>
              <h4 className="text-sm font-bold text-text-main">Belum Ada Webhook</h4>
              <p className="text-xs text-text-muted mt-1 max-w-sm mx-auto">
                Daftarkan URL HTTP endpoint eksternal untuk mulai menerima data event secara otomatis di latar belakang.
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-4">
              {webhooks.map((wh) => {
                const isRevealed = !!revealedSecrets[wh.id];
                return (
                  <div
                    key={wh.id}
                    className="p-5 rounded-2xl bg-surface border border-border-subtle shadow-xs space-y-4"
                  >
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                      <div>
                        <div className="flex items-center gap-2.5">
                          <h4 className="text-sm font-bold text-text-main">{wh.name}</h4>
                          <span className={`px-2 py-0.5 rounded-md text-[10px] font-bold uppercase border ${
                            wh.is_active 
                              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20' 
                              : 'bg-surface-subtle text-text-muted border-border-subtle'
                          }`}>
                            {wh.is_active ? 'Aktif' : 'Nonaktif'}
                          </span>
                        </div>
                        <div className="flex items-center gap-2 mt-1 font-mono text-xs text-text-muted">
                          <span className="truncate max-w-lg">{wh.url}</span>
                        </div>
                      </div>

                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => handleTestPing(wh.id)}
                          title="Kirim Tes Ping"
                          className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-surface-subtle border border-border-subtle text-text-main hover:bg-surface transition-colors shadow-2xs"
                        >
                          <Send className="w-3.5 h-3.5 text-brand-primary" />
                          <span>Uji Coba Ping</span>
                        </button>
                        <button
                          onClick={() => handleToggle(wh.id)}
                          title={wh.is_active ? 'Nonaktifkan Webhook' : 'Aktifkan Webhook'}
                          className="p-2 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle border border-border-subtle transition-colors"
                        >
                          <Power className={`w-3.5 h-3.5 ${wh.is_active ? 'text-emerald-500' : 'text-text-muted'}`} />
                        </button>
                        <button
                          onClick={() => handleDelete(wh.id, wh.name)}
                          title="Hapus Webhook"
                          className="p-2 rounded-lg text-text-muted hover:text-rose-500 hover:bg-rose-500/10 border border-border-subtle transition-colors"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>

                    {/* Secret & Events Row */}
                    <div className="pt-3 border-t border-border-subtle flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                      <div className="flex items-center gap-2">
                        <span className="text-text-muted font-medium">Signing Secret:</span>
                        <code className="px-2 py-0.5 rounded bg-surface-subtle border border-border-subtle font-mono text-[11px] text-text-main">
                          {isRevealed ? wh.secret : `${wh.secret.substring(0, 10)}••••••••••••••••`}
                        </code>
                        <button
                          onClick={() => toggleSecretReveal(wh.id)}
                          className="p-1 rounded text-text-muted hover:text-text-main"
                          title={isRevealed ? 'Sembunyikan' : 'Tampilkan'}
                        >
                          {isRevealed ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                        </button>
                        <button
                          onClick={() => copySecret(wh.id, wh.secret)}
                          className="p-1 rounded text-text-muted hover:text-brand-primary"
                          title="Salin Secret"
                        >
                          {copiedSecretId === wh.id ? <Check className="w-3.5 h-3.5 text-emerald-500" /> : <Copy className="w-3.5 h-3.5" />}
                        </button>
                      </div>

                      <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="text-text-muted font-medium">Events:</span>
                        {wh.events_list.map((e) => (
                          <span
                            key={e}
                            className="px-2 py-0.5 rounded text-[10px] font-mono font-semibold bg-brand-primary/10 text-brand-primary border border-brand-primary/20"
                          >
                            {e === '*' ? 'Semua Event (*)' : e}
                          </span>
                        ))}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Deliveries Logs Table */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-bold text-text-main flex items-center gap-2">
              <span>Riwayat Pengiriman (Recent Deliveries)</span>
              <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-surface-subtle text-text-muted border border-border-subtle">
                {deliveries.length}
              </span>
            </h3>
          </div>

          {deliveries.length === 0 ? (
            <div className="p-8 text-center rounded-2xl bg-surface border border-border-subtle text-xs text-text-muted">
              Belum ada log pengiriman webhook yang tercatat. Lakukan uji coba ping untuk melihat pengiriman pertama!
            </div>
          ) : (
            <div className="bg-surface rounded-2xl border border-border-subtle shadow-xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-surface-subtle border-b border-border-subtle text-text-muted uppercase text-[10px] font-bold">
                    <tr>
                      <th className="px-4 py-3">Status</th>
                      <th className="px-4 py-3">Event</th>
                      <th className="px-4 py-3">Target Webhook</th>
                      <th className="px-4 py-3">HTTP Code</th>
                      <th className="px-4 py-3">Latensi</th>
                      <th className="px-4 py-3">Waktu</th>
                      <th className="px-4 py-3 text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-subtle">
                    {deliveries.map((d) => (
                      <tr key={d.id} className="hover:bg-surface-subtle/50 transition-colors">
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase border ${
                            d.status === 'success'
                              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                              : 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20'
                          }`}>
                            {d.status === 'success' ? (
                              <CheckCircle2 className="w-3 h-3" />
                            ) : (
                              <AlertCircle className="w-3 h-3" />
                            )}
                            <span>{d.status}</span>
                          </span>
                        </td>
                        <td className="px-4 py-3 font-mono font-bold text-text-main">
                          {d.event}
                        </td>
                        <td className="px-4 py-3">
                          <div className="font-semibold text-text-main">{d.webhook_name}</div>
                          <div className="text-[11px] text-text-muted truncate max-w-xs">{d.webhook_url}</div>
                        </td>
                        <td className="px-4 py-3 font-mono">
                          <span className={d.response_code && d.response_code >= 200 && d.response_code < 300 ? 'text-emerald-600 font-bold' : 'text-rose-600 font-bold'}>
                            {d.response_code || '-'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-text-muted font-mono">
                          {d.duration_ms !== null ? `${d.duration_ms} ms` : '-'}
                        </td>
                        <td className="px-4 py-3 text-text-muted">
                          {d.created_at}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <button
                            onClick={() => setSelectedDelivery(d)}
                            className="px-2.5 py-1 rounded-lg text-xs font-semibold bg-surface-subtle border border-border-subtle hover:bg-surface text-text-main transition-colors"
                          >
                            Inspeksi
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>

        {/* Modal: Daftarkan Webhook Baru */}
        {showCreateModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-150">
            <div className="bg-surface w-full max-w-lg rounded-2xl border border-border-subtle shadow-2xl p-6 space-y-5">
              <div className="flex items-center justify-between pb-3 border-b border-border-subtle">
                <div className="flex items-center gap-2.5">
                  <div className="p-2 rounded-lg bg-brand-primary/10 text-brand-primary">
                    <Webhook className="w-5 h-5" />
                  </div>
                  <h3 className="text-base font-bold text-text-main">Daftarkan Webhook Baru</h3>
                </div>
                <button
                  onClick={() => setShowCreateModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form onSubmit={handleCreateSubmit} className="space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-text-main mb-1">
                    Nama / Label Webhook
                  </label>
                  <input
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Contoh: Zapier CRM Sync"
                    className="w-full px-3 py-2 rounded-xl text-xs bg-surface-subtle border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary"
                    required
                  />
                  {errors.name && <p className="text-xs text-rose-500 mt-1">{errors.name}</p>}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-main mb-1">
                    Endpoint URL (HTTPS)
                  </label>
                  <input
                    type="url"
                    value={data.url}
                    onChange={(e) => setData('url', e.target.value)}
                    placeholder="https://hooks.zapier.com/hooks/catch/..."
                    className="w-full px-3 py-2 rounded-xl text-xs bg-surface-subtle border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-mono"
                    required
                  />
                  {errors.url && <p className="text-xs text-rose-500 mt-1">{errors.url}</p>}
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="text-xs font-semibold text-text-main">
                      Signing Secret (HMAC-SHA256)
                    </label>
                    <button
                      type="button"
                      onClick={generateRandomSecret}
                      className="text-[11px] text-brand-primary hover:underline flex items-center gap-1"
                    >
                      <RefreshCw className="w-3 h-3" />
                      <span>Acak Baru</span>
                    </button>
                  </div>
                  <input
                    type="text"
                    value={data.secret}
                    onChange={(e) => setData('secret', e.target.value)}
                    className="w-full px-3 py-2 rounded-xl text-xs bg-surface-subtle border border-border-subtle text-text-main focus:outline-none focus:border-brand-primary font-mono"
                    required
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-main mb-2">
                    Berlangganan Event
                  </label>
                  <div className="space-y-2 max-h-40 overflow-y-auto p-3 rounded-xl bg-surface-subtle border border-border-subtle text-xs">
                    <label className="flex items-center gap-2 cursor-pointer font-semibold text-brand-primary">
                      <input
                        type="checkbox"
                        checked={data.events.includes('*')}
                        onChange={() => toggleEventSelection('*')}
                        className="rounded border-border-subtle text-brand-primary"
                      />
                      <span>Semua Event (*)</span>
                    </label>

                    {AVAILABLE_EVENTS.map((ev) => (
                      <label key={ev.id} className="flex items-center gap-2 cursor-pointer text-text-main">
                        <input
                          type="checkbox"
                          checked={data.events.includes(ev.id)}
                          onChange={() => toggleEventSelection(ev.id)}
                          disabled={data.events.includes('*')}
                          className="rounded border-border-subtle text-brand-primary"
                        />
                        <span>{ev.label}</span>
                      </label>
                    ))}
                  </div>
                </div>

                <div className="flex items-center justify-end gap-2 pt-3 border-t border-border-subtle">
                  <button
                    type="button"
                    onClick={() => setShowCreateModal(false)}
                    className="px-4 py-2 rounded-xl text-xs font-semibold text-text-muted hover:text-text-main"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={processing}
                    className="px-4 py-2 rounded-xl text-xs font-semibold bg-brand-primary text-white hover:bg-brand-primary-hover shadow-xs disabled:opacity-50"
                  >
                    {processing ? 'Mendaftarkan...' : 'Simpan Webhook'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Inspeksi Payload Delivery */}
        {selectedDelivery && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-150">
            <div className="bg-surface w-full max-w-2xl rounded-2xl border border-border-subtle shadow-2xl p-6 space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-border-subtle">
                <div>
                  <h3 className="text-base font-bold text-text-main">Inspeksi Log Pengiriman</h3>
                  <p className="text-xs text-text-muted mt-0.5">
                    ID #{selectedDelivery.id} • Event: <span className="font-mono font-bold text-brand-primary">{selectedDelivery.event}</span>
                  </p>
                </div>
                <button
                  onClick={() => setSelectedDelivery(null)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="space-y-3 text-xs">
                <div>
                  <span className="font-semibold text-text-main block mb-1">Payload Terkirim (JSON):</span>
                  <pre className="p-3 rounded-xl bg-surface-subtle border border-border-subtle font-mono text-[11px] overflow-x-auto max-h-48 text-text-main">
                    {JSON.stringify(JSON.parse(selectedDelivery.payload), null, 2)}
                  </pre>
                </div>

                {selectedDelivery.response_body && (
                  <div>
                    <span className="font-semibold text-text-main block mb-1">Respons Server:</span>
                    <pre className="p-3 rounded-xl bg-surface-subtle border border-border-subtle font-mono text-[11px] overflow-x-auto max-h-36 text-text-muted">
                      {selectedDelivery.response_body}
                    </pre>
                  </div>
                )}
              </div>

              <div className="flex justify-end pt-3 border-t border-border-subtle">
                <button
                  onClick={() => setSelectedDelivery(null)}
                  className="px-4 py-2 rounded-xl text-xs font-semibold bg-surface-subtle border border-border-subtle text-text-main hover:bg-surface"
                >
                  Tutup
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
