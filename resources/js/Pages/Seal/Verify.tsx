import {
  ShieldCheck,
  CheckCircle2,
  Ban,
  Clock,
  AlertTriangle,
  Building2,
  Calendar,
  Lock
} from 'lucide-react';

interface PublicDocument {
  document_type: string;
  document_number: string;
  title: string;
  issuer_name: string;
  signer_name: string;
  signer_role: string | null;
  signer_credential: string | null;
  subject_name: string;
  subject_identifier: string | null;
  status: string;
  canonical_hash: string;
  issued_at: string;
  valid_from: string | null;
  valid_until: string | null;
  revoked_at: string | null;
  revocation_reason: string | null;
}

interface VerificationResult {
  status: 'success' | 'revoked' | 'expired' | 'not_found' | string;
  is_valid: boolean;
  message: string;
  document: PublicDocument | null;
}

interface Props {
  token: string;
  verification: VerificationResult;
}

export default function Verify({ token, verification }: Props) {
  const { status, message, document } = verification;

  return (
    <div className="min-h-screen bg-surface-elevated/40 text-text-main flex flex-col justify-between p-4 sm:p-6 md:p-8 font-sans antialiased">
      {/* Top Header */}
      <header className="max-w-xl mx-auto w-full flex items-center justify-between py-4">
        <div className="flex items-center gap-2.5">
          <div className="w-10 h-10 rounded-xl bg-primary flex items-center justify-center text-primary-contrast shadow-sm">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <span className="font-bold text-base tracking-tight text-text-main block">
              Portal Verifikasi Resmi
            </span>
            <span className="text-2xs text-text-muted uppercase tracking-wider block">
              Universal Document Cryptographic Seal
            </span>
          </div>
        </div>
        <span className="text-xs font-mono px-2.5 py-1 rounded-full bg-surface border border-border-subtle text-text-muted">
          v1.0 Zero-Trust
        </span>
      </header>

      {/* Main Certificate Card */}
      <main className="max-w-xl mx-auto w-full my-auto py-4">
        <div className="bg-surface border border-border-subtle rounded-3xl shadow-xl overflow-hidden">
          {/* Status Header Banner */}
          {status === 'success' && (
            <div className="p-6 bg-success/10 border-b border-success/20 text-center space-y-2">
              <div className="w-14 h-14 rounded-full bg-success text-white flex items-center justify-center mx-auto shadow-md">
                <CheckCircle2 className="w-8 h-8" />
              </div>
              <h1 className="text-xl font-bold text-success">DOKUMEN RESMI & TERVERIFIKASI</h1>
              <p className="text-xs text-text-muted max-w-sm mx-auto">
                Dokumen ini asli, diterbitkan oleh institusi resmi, dan tercatat dalam register tanda tangan digital.
              </p>
            </div>
          )}

          {status === 'revoked' && (
            <div className="p-6 bg-danger/10 border-b border-danger/20 text-center space-y-2">
              <div className="w-14 h-14 rounded-full bg-danger text-white flex items-center justify-center mx-auto shadow-md">
                <Ban className="w-8 h-8" />
              </div>
              <h1 className="text-xl font-bold text-danger">DOKUMEN TELAH DICABUT</h1>
              <p className="text-xs text-text-muted max-w-sm mx-auto">
                Dokumen ini telah dicabut atau dibatalkan oleh pihak penerbit dan dinyatakan tidak berlaku lagi.
              </p>
            </div>
          )}

          {status === 'expired' && (
            <div className="p-6 bg-warning/10 border-b border-warning/20 text-center space-y-2">
              <div className="w-14 h-14 rounded-full bg-warning text-white flex items-center justify-center mx-auto shadow-md">
                <Clock className="w-8 h-8" />
              </div>
              <h1 className="text-xl font-bold text-warning">MASA BERLAKU TELAH BERAKHIR</h1>
              <p className="text-xs text-text-muted max-w-sm mx-auto">
                Dokumen ini sah saat diterbitkan, namun masa berlakunya kini telah kedaluwarsa.
              </p>
            </div>
          )}

          {status === 'not_found' && (
            <div className="p-8 text-center space-y-3">
              <div className="w-14 h-14 rounded-full bg-danger/10 text-danger flex items-center justify-center mx-auto border border-danger/20">
                <AlertTriangle className="w-8 h-8" />
              </div>
              <h1 className="text-lg font-bold text-text-main">Dokumen Tidak Ditemukan</h1>
              <p className="text-sm text-text-muted max-w-sm mx-auto">
                {message}
              </p>
              <div className="p-4 rounded-2xl bg-surface-elevated border border-border-subtle text-xs text-text-subtle font-mono break-all">
                Token: {token || 'N/A'}
              </div>
            </div>
          )}

          {/* Document Content Details (When Document Exists) */}
          {document && (
            <div className="p-6 sm:p-8 space-y-6">
              {/* Document Number & Title */}
              <div className="text-center pb-6 border-b border-border-subtle">
                <span className="text-xs px-2.5 py-1 rounded-full bg-surface-elevated border border-border-subtle font-mono text-text-muted uppercase tracking-wider inline-block mb-2">
                  {document.document_type}
                </span>
                <h2 className="text-xl sm:text-2xl font-bold text-text-main">
                  {document.title}
                </h2>
                <span className="text-sm font-mono font-semibold text-primary block mt-1">
                  {document.document_number}
                </span>
              </div>

              {/* Revocation Warning Box */}
              {status === 'revoked' && (
                <div className="p-4 rounded-2xl bg-danger-subtle border border-danger-subtle text-xs text-danger space-y-1.5">
                  <span className="font-bold block uppercase tracking-wider">Keterangan Pencabutan Resmi:</span>
                  <div className="text-sm">{document.revocation_reason}</div>
                  {document.revoked_at && (
                    <div className="text-xs opacity-80 pt-1">
                      Waktu Pencabutan: {new Date(document.revoked_at).toLocaleString('id-ID')}
                    </div>
                  )}
                </div>
              )}

              {/* Key Attributes Grid */}
              <div className="space-y-4 text-sm">
                <div className="flex items-start gap-3 p-3.5 rounded-2xl bg-surface-elevated/50 border border-border-subtle">
                  <Building2 className="w-5 h-5 text-text-subtle flex-shrink-0 mt-0.5" />
                  <div>
                    <span className="text-xs text-text-muted block">Institusi Penerbit</span>
                    <strong className="text-text-main font-semibold">{document.issuer_name}</strong>
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="p-3.5 rounded-2xl bg-surface-elevated/50 border border-border-subtle">
                    <span className="text-xs text-text-muted block">Nama Subjek / Pasien</span>
                    <strong className="text-text-main font-semibold block">{document.subject_name}</strong>
                    {document.subject_identifier && (
                      <span className="text-xs text-text-subtle font-mono block mt-0.5">
                        {document.subject_identifier}
                      </span>
                    )}
                  </div>

                  <div className="p-3.5 rounded-2xl bg-surface-elevated/50 border border-border-subtle">
                    <span className="text-xs text-text-muted block">Penandatangan Resmi</span>
                    <strong className="text-text-main font-semibold block">{document.signer_name}</strong>
                    <span className="text-xs text-text-subtle block mt-0.5">
                      {document.signer_role || 'Pejabat Berwenang'}
                      {document.signer_credential ? ` (${document.signer_credential})` : ''}
                    </span>
                  </div>
                </div>

                <div className="p-3.5 rounded-2xl bg-surface-elevated/50 border border-border-subtle flex items-center justify-between text-xs">
                  <div className="flex items-center gap-2 text-text-muted">
                    <Calendar className="w-4 h-4 text-text-subtle" />
                    <span>Tanggal Penerbitan:</span>
                  </div>
                  <span className="font-semibold text-text-main font-mono">
                    {new Date(document.issued_at).toLocaleDateString('id-ID', {
                      day: '2-digit',
                      month: 'long',
                      year: 'numeric',
                    })}
                  </span>
                </div>

                {(document.valid_from || document.valid_until) && (
                  <div className="p-3.5 rounded-2xl bg-surface-elevated/50 border border-border-subtle flex items-center justify-between text-xs">
                    <span className="text-text-muted">Masa Berlaku:</span>
                    <span className="font-semibold text-text-main font-mono">
                      {document.valid_from || 'Mulai'} &rarr; {document.valid_until || 'Seterusnya'}
                    </span>
                  </div>
                )}
              </div>

              {/* Cryptographic Seal Hash */}
              <div className="pt-4 border-t border-border-subtle space-y-2">
                <div className="flex items-center justify-between text-xs text-text-muted">
                  <div className="flex items-center gap-1.5">
                    <Lock className="w-3.5 h-3.5 text-text-subtle" />
                    <span className="font-semibold">Segel Kriptografis (SHA-256)</span>
                  </div>
                  <span className="text-2xs font-mono uppercase bg-success-subtle text-success px-2 py-0.5 rounded">
                    Integrity Match
                  </span>
                </div>
                <div className="p-3 rounded-2xl bg-surface-elevated font-mono text-2xs text-text-subtle break-all border border-border-subtle select-all">
                  {document.canonical_hash}
                </div>
              </div>
            </div>
          )}
        </div>
      </main>

      {/* Footer */}
      <footer className="max-w-xl mx-auto w-full py-6 text-center text-xs text-text-muted space-y-1">
        <p>
          Verifikasi keaslian dokumen resmi berbasis tanda tangan digital kriptografis.
        </p>
        <p className="text-2xs text-text-subtle">
          Antigravity Platform &bull; Level 2 Universal Shared Foundation &bull; aio
        </p>
      </footer>
    </div>
  );
}
