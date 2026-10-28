import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  ShieldCheck,
  Search,
  Plus,
  QrCode,
  X,
  CheckCircle2,
  AlertTriangle,
  Ban,
  Clock,
  Copy,
  ExternalLink,
  History,
  FileText
} from 'lucide-react';

interface DocumentSeal {
  id: number;
  tenant_id: number;
  verification_token: string;
  verification_url: string;
  document_type: string;
  document_number: string;
  title: string;
  issuer_name: string;
  signer_name: string;
  signer_role: string | null;
  signer_credential: string | null;
  subject_name: string;
  subject_identifier: string | null;
  subject_masked_name: string | null;
  canonical_hash: string;
  signature: string;
  status: string;
  revoked_at: string | null;
  revocation_reason: string | null;
  valid_from: string | null;
  valid_until: string | null;
  payload_data: Record<string, any>;
  created_at: string;
}

interface VerificationLog {
  id: number;
  document_seal_id: number;
  verification_token: string;
  verification_result: string;
  ip_address: string | null;
  user_agent: string | null;
  referer: string | null;
  verified_at: string;
}

interface Props {
  seals: DocumentSeal[];
  selectedSeal: DocumentSeal | null;
  verificationLogs: VerificationLog[];
  filters: {
    status?: string | null;
    document_type?: string | null;
    search?: string | null;
  };
  flash?: {
    success?: string | null;
    error?: string | null;
  };
}

export default function Index({
  seals = [],
  selectedSeal = null,
  verificationLogs = [],
  filters = {},
  flash = {}
}: Props) {
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showRevokeModal, setShowRevokeModal] = useState(false);
  const [copiedToken, setCopiedToken] = useState(false);

  const [searchFilter, setSearchFilter] = useState(filters.search || '');

  const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

  const copyUrl = (url: string) => {
    navigator.clipboard.writeText(url);
    setCopiedToken(true);
    setTimeout(() => setCopiedToken(false), 2000);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-success-subtle text-success">
            <CheckCircle2 className="w-3 h-3" />
            Sah & Otentik
          </span>
        );
      case 'revoked':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-danger-subtle text-danger">
            <Ban className="w-3 h-3" />
            Dicabut (Revoked)
          </span>
        );
      case 'expired':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-warning-subtle text-warning">
            <Clock className="w-3 h-3" />
            Kedaluwarsa
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-surface-elevated text-text-muted">
            {status}
          </span>
        );
    }
  };

  return (
    <AppLayout title="Universal Document Cryptographic Seal & Verification">
      <div className="space-y-6">
        {/* Flash Notifications */}
        {flash?.success && (
          <div className="p-4 rounded-xl bg-success-subtle border border-success-subtle text-success flex items-center justify-between">
            <div className="flex items-center gap-2">
              <CheckCircle2 className="w-5 h-5 flex-shrink-0" />
              <span>{flash.success}</span>
            </div>
          </div>
        )}
        {flash?.error && (
          <div className="p-4 rounded-xl bg-danger-subtle border border-danger-subtle text-danger flex items-center justify-between">
            <div className="flex items-center gap-2">
              <X className="w-5 h-5 flex-shrink-0" />
              <span>{flash.error}</span>
            </div>
          </div>
        )}

        {/* Header Banner */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl bg-surface border border-border-subtle shadow-sm">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
              <ShieldCheck className="w-7 h-7" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold text-text-main">Segel Kriptografis & Verifikasi Dokumen</h1>
                <span className="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-primary/10 text-primary border border-primary/20">
                  Level 2 Foundation
                </span>
              </div>
              <p className="text-sm text-text-muted mt-1">
                Tanda tangan digital (TTE), segel anti-tampering SHA-256/HMAC, dan portal verifikasi QR publik untuk seluruh dokumen resmi.
              </p>
            </div>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2.5 text-sm font-semibold rounded-xl bg-primary hover:bg-primary-hover text-primary-contrast transition-colors flex items-center gap-2 shadow-sm self-start md:self-auto"
          >
            <Plus className="w-4 h-4" />
            <span>Terbitkan Segel Dokumen</span>
          </button>
        </div>

        {/* Main Content: Split Master-Detail */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
          {/* Left Column: Seals List */}
          <div className="lg:col-span-7 space-y-4">
            {/* Search & Filter Bar */}
            <div className="bg-surface p-4 rounded-2xl border border-border-subtle shadow-sm flex flex-col md:flex-row gap-3 justify-between items-center">
              <form action="/seal" method="GET" className="flex items-center gap-2 w-full md:w-auto flex-1">
                <div className="relative w-full">
                  <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle" />
                  <input
                    type="text"
                    name="search"
                    value={searchFilter}
                    onChange={(e) => setSearchFilter(e.target.value)}
                    placeholder="Cari nomor dokumen, judul, subjek..."
                    className="w-full pl-9 pr-4 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary"
                  />
                </div>
                <button
                  type="submit"
                  className="px-3.5 py-2 text-sm font-medium rounded-xl bg-surface-elevated border border-border-subtle hover:bg-surface text-text-main transition-colors"
                >
                  Filter
                </button>
              </form>

              <div className="flex items-center gap-1.5 self-end md:self-auto">
                <a
                  href="/seal"
                  className={`px-2.5 py-1 text-xs font-semibold rounded-lg border ${
                    !filters.status
                      ? 'bg-primary text-primary-contrast border-primary'
                      : 'bg-surface-elevated border-border-subtle text-text-muted hover:text-text-main'
                  }`}
                >
                  Semua
                </a>
                <a
                  href="/seal?status=active"
                  className={`px-2.5 py-1 text-xs font-semibold rounded-lg border ${
                    filters.status === 'active'
                      ? 'bg-primary text-primary-contrast border-primary'
                      : 'bg-surface-elevated border-border-subtle text-text-muted hover:text-text-main'
                  }`}
                >
                  Aktif
                </a>
                <a
                  href="/seal?status=revoked"
                  className={`px-2.5 py-1 text-xs font-semibold rounded-lg border ${
                    filters.status === 'revoked'
                      ? 'bg-primary text-primary-contrast border-primary'
                      : 'bg-surface-elevated border-border-subtle text-text-muted hover:text-text-main'
                  }`}
                >
                  Dicabut
                </a>
              </div>
            </div>

            {/* List Table / Cards */}
            <div className="bg-surface rounded-2xl border border-border-subtle overflow-hidden shadow-sm">
              {seals.length === 0 ? (
                <div className="p-12 text-center">
                  <ShieldCheck className="w-12 h-12 text-text-subtle mx-auto mb-3 opacity-60" />
                  <h3 className="text-base font-semibold text-text-main">Belum Ada Dokumen Disegel</h3>
                  <p className="text-sm text-text-muted mt-1 max-w-sm mx-auto">
                    Dokumen yang diterbitkan oleh modul Billing (kwitansi), EHR (surat dokter), atau Sertifikasi akan tercatat secara otomatis di sini.
                  </p>
                  <button
                    onClick={() => setShowCreateModal(true)}
                    className="mt-4 px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Segel Dokumen Sekarang
                  </button>
                </div>
              ) : (
                <div className="divide-y divide-border-subtle">
                  {seals.map((seal) => (
                    <a
                      key={seal.id}
                      href={`/seal?token=${seal.verification_token}`}
                      className={`p-4.5 block hover:bg-surface-elevated/50 transition-colors ${
                        selectedSeal?.id === seal.id ? 'bg-surface-elevated/70 border-l-4 border-l-primary' : ''
                      }`}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="space-y-1">
                          <div className="flex items-center gap-2">
                            <span className="font-mono font-bold text-sm text-primary">
                              {seal.document_number}
                            </span>
                            <span className="text-xs px-2 py-0.5 rounded bg-surface-elevated border border-border-subtle text-text-muted uppercase font-mono">
                              {seal.document_type}
                            </span>
                            {getStatusBadge(seal.status)}
                          </div>
                          <h3 className="text-sm font-semibold text-text-main">{seal.title}</h3>
                          <div className="text-xs text-text-muted flex flex-wrap items-center gap-x-4 gap-y-1 pt-1">
                            <span>Subjek: <strong className="text-text-main">{seal.subject_name}</strong></span>
                            <span>Penandatangan: <strong className="text-text-main">{seal.signer_name}</strong></span>
                          </div>
                        </div>

                        <div className="text-right flex flex-col items-end gap-1 flex-shrink-0">
                          <span className="text-xs text-text-subtle font-mono">
                            {new Date(seal.created_at).toLocaleDateString('id-ID', {
                              day: '2-digit',
                              month: 'short',
                              year: 'numeric',
                            })}
                          </span>
                          <span className="p-1 rounded bg-surface-elevated border border-border-subtle text-text-muted">
                            <QrCode className="w-4 h-4" />
                          </span>
                        </div>
                      </div>
                    </a>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Right Column: Selected Seal Details & Audit Scan Logs */}
          <div className="lg:col-span-5 space-y-5">
            {selectedSeal ? (
              <div className="space-y-5">
                {/* Seal Certificate Card */}
                <div className="bg-surface p-6 rounded-2xl border border-border-subtle shadow-sm space-y-5">
                  <div className="flex items-center justify-between pb-4 border-b border-border-subtle">
                    <div className="flex items-center gap-2">
                      <ShieldCheck className="w-5 h-5 text-primary" />
                      <span className="text-sm font-bold text-text-main">Detail Sertifikat Segel</span>
                    </div>
                    {getStatusBadge(selectedSeal.status)}
                  </div>

                  {/* Document Core Info */}
                  <div className="space-y-3 text-sm">
                    <div>
                      <span className="text-xs text-text-muted block">Nomor Dokumen</span>
                      <span className="font-mono font-bold text-base text-text-main">
                        {selectedSeal.document_number}
                      </span>
                    </div>

                    <div>
                      <span className="text-xs text-text-muted block">Judul / Peruntukan</span>
                      <span className="font-semibold text-text-main">{selectedSeal.title}</span>
                    </div>

                    <div className="grid grid-cols-2 gap-3 pt-2">
                      <div>
                        <span className="text-xs text-text-muted block">Nama Subjek / Pasien</span>
                        <span className="font-medium text-text-main">{selectedSeal.subject_name}</span>
                        {selectedSeal.subject_identifier && (
                          <span className="text-xs text-text-subtle font-mono block">
                            {selectedSeal.subject_identifier}
                          </span>
                        )}
                      </div>
                      <div>
                        <span className="text-xs text-text-muted block">Penandatangan Resmi</span>
                        <span className="font-medium text-text-main">{selectedSeal.signer_name}</span>
                        {selectedSeal.signer_credential && (
                          <span className="text-xs text-text-subtle font-mono block">
                            {selectedSeal.signer_credential}
                          </span>
                        )}
                      </div>
                    </div>

                    {(selectedSeal.valid_from || selectedSeal.valid_until) && (
                      <div className="p-3 rounded-xl bg-surface-elevated border border-border-subtle text-xs space-y-1">
                        <span className="font-semibold text-text-main block">Masa Berlaku Dokumen:</span>
                        <div className="flex justify-between text-text-muted">
                          <span>Dari: {selectedSeal.valid_from || '-'}</span>
                          <span>Hingga: {selectedSeal.valid_until || 'Selamanya'}</span>
                        </div>
                      </div>
                    )}

                    {selectedSeal.status === 'revoked' && (
                      <div className="p-3.5 rounded-xl bg-danger-subtle border border-danger-subtle text-xs text-danger space-y-1">
                        <span className="font-bold flex items-center gap-1.5">
                          <AlertTriangle className="w-4 h-4" />
                          Dokumen Telah Dicabut (Revoked)
                        </span>
                        <div>Alasan: {selectedSeal.revocation_reason}</div>
                        <div className="text-danger/80">Waktu: {selectedSeal.revoked_at}</div>
                      </div>
                    )}

                    {/* Cryptographic Hash Snippet */}
                    <div className="pt-2">
                      <span className="text-xs text-text-muted block mb-1">Canonical SHA-256 Hash</span>
                      <div className="p-2.5 rounded-xl bg-surface-elevated border border-border-subtle font-mono text-xs text-text-subtle break-all">
                        {selectedSeal.canonical_hash}
                      </div>
                    </div>

                    {/* Public Verification Link */}
                    <div className="pt-2">
                      <span className="text-xs text-text-muted block mb-1">Tautan Portal Verifikasi Publik</span>
                      <div className="flex items-center gap-2">
                        <input
                          type="text"
                          readOnly
                          value={selectedSeal.verification_url}
                          className="flex-1 px-3 py-1.5 text-xs font-mono rounded-lg border border-border-subtle bg-surface-elevated text-text-muted"
                        />
                        <button
                          onClick={() => copyUrl(selectedSeal.verification_url)}
                          className="p-2 rounded-lg border border-border-subtle hover:bg-surface-elevated text-text-muted hover:text-text-main transition-colors"
                          title="Salin Tautan"
                        >
                          <Copy className="w-4 h-4" />
                        </button>
                        <a
                          href={selectedSeal.verification_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="p-2 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors"
                          title="Buka Halaman Verifikasi Publik"
                        >
                          <ExternalLink className="w-4 h-4" />
                        </a>
                      </div>
                      {copiedToken && (
                        <span className="text-xs text-success mt-1 block">Tautan berhasil disalin ke clipboard!</span>
                      )}
                    </div>
                  </div>

                  {/* Revoke Action Button */}
                  {selectedSeal.status === 'active' && (
                    <div className="pt-4 border-t border-border-subtle flex justify-end">
                      <button
                        onClick={() => setShowRevokeModal(true)}
                        className="px-3.5 py-1.5 text-xs font-semibold rounded-xl bg-danger-subtle text-danger hover:bg-danger hover:text-white transition-colors flex items-center gap-1.5"
                      >
                        <Ban className="w-3.5 h-3.5" />
                        <span>Cabut Keabsahan Dokumen</span>
                      </button>
                    </div>
                  )}
                </div>

                {/* Audit Verification Logs Card */}
                <div className="bg-surface p-5 rounded-2xl border border-border-subtle shadow-sm space-y-4">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <History className="w-4 h-4 text-primary" />
                      <h3 className="text-sm font-bold text-text-main">Riwayat Pemindaian QR Code Publik</h3>
                    </div>
                    <span className="text-xs px-2 py-0.5 rounded-full bg-surface-elevated border border-border-subtle text-text-muted">
                      {verificationLogs.length} Pemindaian
                    </span>
                  </div>

                  {verificationLogs.length === 0 ? (
                    <p className="text-xs text-text-muted py-4 text-center">
                      Belum ada riwayat pemindaian QR code untuk dokumen ini.
                    </p>
                  ) : (
                    <div className="divide-y divide-border-subtle max-h-60 overflow-y-auto">
                      {verificationLogs.map((log) => (
                        <div key={log.id} className="py-2.5 text-xs flex items-center justify-between">
                          <div className="space-y-0.5">
                            <div className="flex items-center gap-2">
                              <span
                                className={`px-2 py-0.5 rounded-full text-2xs font-semibold ${
                                  log.verification_result === 'success'
                                    ? 'bg-success-subtle text-success'
                                    : 'bg-danger-subtle text-danger'
                                }`}
                              >
                                {log.verification_result.toUpperCase()}
                              </span>
                              <span className="font-mono text-text-main">{log.ip_address || 'Unknown IP'}</span>
                            </div>
                            <div className="text-text-subtle truncate max-w-xs">{log.user_agent || '-'}</div>
                          </div>
                          <span className="text-text-subtle text-2xs font-mono">
                            {new Date(log.verified_at).toLocaleTimeString('id-ID', {
                              hour: '2-digit',
                              minute: '2-digit',
                            })}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            ) : (
              <div className="p-8 rounded-2xl bg-surface border border-border-subtle text-center text-text-muted">
                <FileText className="w-8 h-8 text-text-subtle mx-auto mb-2 opacity-50" />
                <p className="text-sm">Pilih dokumen di sebelah kiri untuk melihat detail sertifikat dan riwayat pemindaian.</p>
              </div>
            )}
          </div>
        </div>

        {/* MODAL: MANUAL DOCUMENT SEAL ISSUANCE */}
        {showCreateModal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-xl shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <ShieldCheck className="w-5 h-5 text-primary" />
                  <h3 className="text-base font-bold text-text-main">Terbitkan Segel Dokumen Baru</h3>
                </div>
                <button
                  onClick={() => setShowCreateModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action="/seal/documents" method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tipe Dokumen *</label>
                    <select
                      name="document_type"
                      required
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    >
                      <option value="sick_leave">Surat Keterangan Sakit (Dokter)</option>
                      <option value="invoice">Faktur Pembayaran / Invoice</option>
                      <option value="receipt">Kwitansi Kasir</option>
                      <option value="medical_resume">Resume Medis Pasien</option>
                      <option value="referral">Surat Rujukan</option>
                      <option value="certificate">Sertifikat / Surat Resmi</option>
                      <option value="custom">Dokumen Khusus Lainnya</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Nomor Dokumen *</label>
                    <input
                      type="text"
                      name="document_number"
                      required
                      placeholder="Contoh: SKD/2026/10/0042"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Judul Dokumen *</label>
                  <input
                    type="text"
                    name="title"
                    required
                    placeholder="Contoh: Surat Keterangan Istirahat Sakit Pasien"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Nama Subjek / Pasien / Klien *</label>
                    <input
                      type="text"
                      name="subject_name"
                      required
                      placeholder="Contoh: Ahmad Fauzi"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Identitas Subjek (NIK / MRN)</label>
                    <input
                      type="text"
                      name="subject_identifier"
                      placeholder="Contoh: NIK: 3171xxxxxxxx"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Nama Penandatangan Resmi *</label>
                    <input
                      type="text"
                      name="signer_name"
                      required
                      placeholder="Contoh: dr. Budi Santoso, Sp.A"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kredensial Penandatangan (SIP/NIP)</label>
                    <input
                      type="text"
                      name="signer_credential"
                      placeholder="Contoh: SIP: 503/SIP/2024"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Berlaku Mulai</label>
                    <input
                      type="date"
                      name="valid_from"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Berlaku Hingga</label>
                    <input
                      type="date"
                      name="valid_until"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Catatan / Ringkasan Dokumen</label>
                  <textarea
                    name="notes"
                    rows={2}
                    placeholder="Catatan tambahan yang akan masuk ke payload kanonikal..."
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowCreateModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Terbitkan & Segel
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: REVOKE DOCUMENT */}
        {showRevokeModal && selectedSeal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-md shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2 text-danger">
                  <Ban className="w-5 h-5" />
                  <h3 className="text-base font-bold text-text-main">Cabut Keabsahan Dokumen</h3>
                </div>
                <button
                  onClick={() => setShowRevokeModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action={`/seal/documents/${selectedSeal.verification_token}/revoke`} method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />

                <p className="text-sm text-text-muted">
                  Apakah Anda yakin ingin mencabut dokumen <strong className="text-text-main">{selectedSeal.document_number}</strong>? Status dokumen pada portal verifikasi publik akan berubah menjadi <strong>Dicabut / Tidak Sah</strong>.
                </p>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Alasan Pencabutan Resmi *</label>
                  <textarea
                    name="reason"
                    required
                    rows={3}
                    placeholder="Contoh: Transaksi telah dibatalkan, pasien salah identitas..."
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowRevokeModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-danger text-white hover:bg-danger/90"
                  >
                    Konfirmasi Pencabutan
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
