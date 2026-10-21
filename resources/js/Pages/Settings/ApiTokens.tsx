import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  Key, 
  Plus, 
  Trash2, 
  Copy, 
  Check, 
  AlertTriangle, 
  Clock, 
  Terminal, 
  X
} from 'lucide-react';

interface ApiToken {
  id: number;
  name: string;
  abilities: string;
  abilities_list: string[];
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
  user_name: string;
  is_expired: boolean;
}

interface ApiTokensProps {
  tokens: ApiToken[];
  currentTenant: {
    id: number;
    name: string;
    slug: string;
  } | null;
  newToken: string | null;
}

export default function ApiTokens({ tokens, currentTenant, newToken }: ApiTokensProps) {
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [revealedToken, setRevealedToken] = useState<string | null>(newToken);
  const [copied, setCopied] = useState(false);

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    abilities: ['*'],
    expires_in_days: '90',
  });

  const handleCreateToken = (e: React.FormEvent) => {
    e.preventDefault();
    post('/settings/api-tokens', {
      onSuccess: () => {
        setCreateModalOpen(false);
        reset();
      },
    });
  };

  const handleRevokeToken = (id: number, name: string) => {
    if (confirm(`Apakah Anda yakin ingin mencabut token "${name}"? Klien eksternal yang menggunakan token ini tidak akan dapat mengakses API lagi.`)) {
      router.post(`/settings/api-tokens/${id}/delete`);
    }
  };

  const handleCopy = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const toggleAbility = (ability: string) => {
    if (ability === '*') {
      setData('abilities', ['*']);
      return;
    }

    let updated = data.abilities.filter((a) => a !== '*');
    if (updated.includes(ability)) {
      updated = updated.filter((a) => a !== ability);
    } else {
      updated.push(ability);
    }

    if (updated.length === 0) {
      updated = ['*'];
    }

    setData('abilities', updated);
  };

  return (
    <AppLayout title="API Tokens & Integrasi">
      <Head title="API Tokens & Integrasi" />

      <div className="space-y-6 max-w-7xl mx-auto pb-12">
        {/* Header Section */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-surface p-6 rounded-2xl border border-border-subtle shadow-sm">
          <div>
            <div className="flex items-center gap-2.5">
              <div className="h-10 w-10 rounded-xl bg-brand-primary/10 flex items-center justify-center text-brand-primary">
                <Key className="h-5 w-5" />
              </div>
              <div>
                <h1 className="text-xl font-bold text-text-main tracking-tight">
                  Personal Access Tokens (REST API)
                </h1>
                <p className="text-xs text-text-muted mt-0.5">
                  Akses terprogram tanpa browser untuk integrasi webhook, skrip otomasi, dan aplikasi pihak ketiga.
                </p>
              </div>
            </div>
          </div>

          <button
            type="button"
            onClick={() => setCreateModalOpen(true)}
            className="inline-flex items-center gap-2 px-4 py-2 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-xl shadow-sm transition-all shrink-0 cursor-pointer"
          >
            <Plus className="h-4 w-4" />
            <span>Buat Token Baru</span>
          </button>
        </div>

        {/* Revealed Token Banner (After creation) */}
        {revealedToken && (
          <div className="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-6 space-y-3 animate-in fade-in duration-200">
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-center gap-2.5 text-amber-400">
                <AlertTriangle className="h-5 w-5 shrink-0" />
                <h3 className="text-sm font-bold">Simpan Token Anda Sekarang!</h3>
              </div>
              <button
                type="button"
                onClick={() => setRevealedToken(null)}
                className="text-text-muted hover:text-text-main p-1"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <p className="text-xs text-text-muted leading-relaxed">
              Ini adalah satu-satunya kesempatan Anda untuk menyalin token ini. Demi keamanan, kami hanya menyimpan hash SHA-256 dari token dan tidak akan menampilkannya kembali.
            </p>

            <div className="flex items-center gap-2 bg-[#090d16] p-2.5 rounded-xl border border-slate-800">
              <code className="text-xs font-mono text-emerald-400 flex-1 truncate select-all px-2">
                {revealedToken}
              </code>
              <button
                type="button"
                onClick={() => handleCopy(revealedToken)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-lg transition-colors cursor-pointer shrink-0"
              >
                {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                <span>{copied ? 'Tersalin!' : 'Salin Token'}</span>
              </button>
            </div>
          </div>
        )}

        {/* Active Tokens Table */}
        <div className="bg-surface rounded-2xl border border-border-subtle shadow-sm p-6">
          <div className="flex items-center justify-between pb-4 mb-4 border-b border-border-subtle">
            <div className="text-xs font-bold text-text-muted uppercase tracking-wider">
              Token Aktif ({tokens.length})
            </div>
            {currentTenant && (
              <span className="text-xs text-text-muted font-medium">
                Workspace: <strong className="text-text-main">{currentTenant.name}</strong>
              </span>
            )}
          </div>

          {tokens.length === 0 ? (
            <div className="text-center py-12">
              <div className="h-10 w-10 rounded-full bg-surface-subtle border border-border-subtle flex items-center justify-center mx-auto text-text-muted mb-2">
                <Key className="h-5 w-5" />
              </div>
              <h4 className="text-xs font-semibold text-text-main">Belum Ada Token API</h4>
              <p className="text-[11px] text-text-muted max-w-sm mx-auto mt-1">
                Buat token pertama Anda untuk menghubungkan skrip otomasi atau aplikasi pihak ketiga ke workspace ini.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b border-border-subtle text-text-muted font-semibold">
                    <th className="pb-3 px-2">Nama Token</th>
                    <th className="pb-3 px-2">Hak Akses (Abilities)</th>
                    <th className="pb-3 px-2">Pembuat</th>
                    <th className="pb-3 px-2">Terakhir Dipakai</th>
                    <th className="pb-3 px-2">Kedaluwarsa</th>
                    <th className="pb-3 px-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle/60">
                  {tokens.map((t) => (
                    <tr key={t.id} className="hover:bg-surface-subtle/50 transition-colors">
                      <td className="py-3 px-2 font-semibold text-text-main">
                        <div className="flex items-center gap-2">
                          <Key className="h-3.5 w-3.5 text-brand-primary shrink-0" />
                          <span>{t.name}</span>
                        </div>
                      </td>
                      <td className="py-3 px-2">
                        <div className="flex flex-wrap gap-1">
                          {t.abilities_list.map((ab) => (
                            <span 
                              key={ab} 
                              className="px-2 py-0.5 rounded-md text-[10px] font-mono font-medium bg-brand-primary/10 text-brand-primary border border-brand-primary/20"
                            >
                              {ab}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="py-3 px-2 text-text-muted">{t.user_name}</td>
                      <td className="py-3 px-2 text-text-muted">
                        {t.last_used_at ? (
                          <span className="flex items-center gap-1 font-mono text-[11px]">
                            <Clock className="w-3 h-3 text-text-muted" />
                            {t.last_used_at}
                          </span>
                        ) : (
                          <span className="italic text-text-muted/60">Belum pernah</span>
                        )}
                      </td>
                      <td className="py-3 px-2">
                        {t.is_expired ? (
                          <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                            Kedaluwarsa
                          </span>
                        ) : t.expires_at ? (
                          <span className="text-text-muted text-[11px] font-mono">{t.expires_at.split(' ')[0]}</span>
                        ) : (
                          <span className="text-text-muted text-[11px]">Tidak terbatas</span>
                        )}
                      </td>
                      <td className="py-3 px-2 text-right">
                        <button
                          type="button"
                          onClick={() => handleRevokeToken(t.id, t.name)}
                          className="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold text-rose-400 hover:text-rose-300 hover:bg-rose-500/10 rounded-lg transition-colors cursor-pointer"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                          <span>Cabut</span>
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Quick Developer Integration Guide */}
        <div className="bg-surface rounded-2xl border border-border-subtle p-6 space-y-4 shadow-sm">
          <div className="flex items-center gap-2 text-text-main">
            <Terminal className="w-4 h-4 text-brand-primary" />
            <h3 className="text-xs font-bold uppercase tracking-wider">Panduan Integrasi HTTP (cURL)</h3>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
            <div className="space-y-1.5">
              <span className="text-text-muted font-medium block">1. Cek Profil & Verifikasi Token (`GET /api/v1/me`):</span>
              <pre className="p-3 rounded-xl bg-[#090d16] text-slate-300 font-mono text-[11px] border border-slate-800 overflow-x-auto">
{`curl -X GET http://localhost:8000/api/v1/me \\
  -H "Authorization: Bearer aio_pat_YOUR_TOKEN"`}
              </pre>
            </div>

            <div className="space-y-1.5">
              <span className="text-text-muted font-medium block">2. Buat Proyek Otomatis (`POST /api/v1/projects`):</span>
              <pre className="p-3 rounded-xl bg-[#090d16] text-slate-300 font-mono text-[11px] border border-slate-800 overflow-x-auto">
{`curl -X POST http://localhost:8000/api/v1/projects \\
  -H "Authorization: Bearer aio_pat_YOUR_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{"name": "Proyek dari Webhook"}'`}
              </pre>
            </div>
          </div>
        </div>
      </div>

      {/* Modal Buat Token */}
      {createModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="bg-surface border border-border-subtle rounded-2xl max-w-md w-full p-6 shadow-2xl relative">
            <button
              type="button"
              onClick={() => setCreateModalOpen(false)}
              className="absolute top-4 right-4 p-1.5 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
            >
              <X className="w-4 h-4" />
            </button>

            <div className="flex items-center gap-2.5 pb-4 border-b border-border-subtle">
              <div className="p-2 rounded-xl bg-brand-primary/10 text-brand-primary">
                <Key className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-bold text-text-main">Buat API Token Baru</h3>
                <p className="text-xs text-text-muted">Untuk workspace {currentTenant?.name}</p>
              </div>
            </div>

            <form onSubmit={handleCreateToken} className="mt-4 space-y-4 text-xs">
              <div>
                <label className="block font-semibold text-text-main mb-1">
                  Nama Token <span className="text-rose-400">*</span>
                </label>
                <input
                  type="text"
                  placeholder="Contoh: Zapier Webhook, CI/CD Pipeline"
                  value={data.name}
                  onChange={(e) => setData('name', e.target.value)}
                  className="w-full px-3 py-2 bg-surface-subtle border border-border-subtle rounded-lg text-text-main focus:outline-none focus:border-brand-primary"
                  required
                />
                {errors.name && <p className="text-rose-400 text-[11px] mt-1">{errors.name}</p>}
              </div>

              <div>
                <label className="block font-semibold text-text-main mb-1.5">
                  Hak Akses (Abilities)
                </label>
                <div className="space-y-2 bg-surface-subtle p-3 rounded-xl border border-border-subtle">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={data.abilities.includes('*')}
                      onChange={() => toggleAbility('*')}
                      className="rounded border-border-subtle text-brand-primary"
                    />
                    <span className="font-semibold text-text-main">Akses Penuh (*)</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={data.abilities.includes('projects:read') || data.abilities.includes('*')}
                      disabled={data.abilities.includes('*')}
                      onChange={() => toggleAbility('projects:read')}
                      className="rounded border-border-subtle text-brand-primary"
                    />
                    <span className="text-text-muted">Baca Data Proyek & Tugas (`projects:read`)</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={data.abilities.includes('projects:write') || data.abilities.includes('*')}
                      disabled={data.abilities.includes('*')}
                      onChange={() => toggleAbility('projects:write')}
                      className="rounded border-border-subtle text-brand-primary"
                    />
                    <span className="text-text-muted">Buat & Kelola Proyek (`projects:write`)</span>
                  </label>
                </div>
              </div>

              <div>
                <label className="block font-semibold text-text-main mb-1">
                  Masa Berlaku Token
                </label>
                <select
                  value={data.expires_in_days}
                  onChange={(e) => setData('expires_in_days', e.target.value)}
                  className="w-full px-3 py-2 bg-surface-subtle border border-border-subtle rounded-lg text-text-main focus:outline-none focus:border-brand-primary"
                >
                  <option value="30">30 Hari</option>
                  <option value="90">90 Hari (Rekomendasi)</option>
                  <option value="365">1 Tahun</option>
                  <option value="0">Tidak Pernah Kedaluwarsa</option>
                </select>
              </div>

              <div className="pt-2 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setCreateModalOpen(false)}
                  className="px-4 py-2 bg-surface-subtle text-text-muted hover:text-text-main rounded-lg font-semibold"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={processing}
                  className="px-4 py-2 bg-brand-primary hover:bg-brand-primary-hover text-white rounded-lg font-semibold shadow-sm transition-all"
                >
                  {processing ? 'Membuat...' : 'Buat Token'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
