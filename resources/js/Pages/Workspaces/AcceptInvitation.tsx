import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Building2, Shield, ArrowRight, UserCheck, ShieldAlert, CheckCircle2, AlertCircle } from 'lucide-react';

interface InvitationData {
  id: number;
  tenant_id: number;
  email: string;
  role: string;
  expires_at: string;
}

interface TenantData {
  id: number;
  name: string;
  slug: string;
}

interface UserData {
  id: number;
  name: string;
  email: string;
}

interface AcceptInvitationProps {
  token: string;
  invitation: InvitationData | null;
  tenant: TenantData | null;
  currentUser: UserData | null;
  isValid: boolean;
  error?: string | null;
}

export default function AcceptInvitation({
  token,
  invitation,
  tenant,
  currentUser,
  isValid,
  error,
}: AcceptInvitationProps) {
  const { props } = usePage<{ flash?: { error?: string | null; success?: string | null }; csrf_token?: string }>();
  const { post, processing } = useForm({
    token: token || '',
    _token: props.csrf_token || '',
  });

  const handleAccept = (e: React.FormEvent) => {
    e.preventDefault();
    post('/invitations/accept');
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4 selection:bg-indigo-500 selection:text-white font-sans antialiased relative overflow-hidden">
      {/* Background Gradients */}
      <div className="absolute -top-40 -left-40 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute -bottom-40 -right-40 w-96 h-96 bg-purple-600/20 rounded-full blur-3xl pointer-events-none" />

      <div className="w-full max-w-md space-y-6 relative z-10">
        {/* Brand Header */}
        <div className="text-center space-y-2">
          <div className="inline-flex h-12 w-12 rounded-2xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 items-center justify-center shadow-lg shadow-indigo-500/30">
            <Building2 className="h-6 w-6 text-white" />
          </div>
          <h2 className="text-2xl font-extrabold tracking-tight text-white">
            Undangan Workspace
          </h2>
          <p className="text-xs text-slate-400">
            Kolaborasi dalam ruang kerja bersama di platform aio
          </p>
        </div>

        {/* Flash messages */}
        {props.flash?.error && (
          <div className="p-3 rounded-xl bg-rose-950/60 border border-rose-500/40 text-rose-200 text-xs flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" />
            <span>{props.flash.error}</span>
          </div>
        )}
        {props.flash?.success && (
          <div className="p-3 rounded-xl bg-emerald-950/60 border border-emerald-500/40 text-emerald-200 text-xs flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
            <span>{props.flash.success}</span>
          </div>
        )}

        {/* Card */}
        <div className="bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-2xl p-6 shadow-2xl space-y-5">
          {!isValid || !invitation || !tenant ? (
            <div className="text-center space-y-4 py-2">
              <div className="w-12 h-12 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center mx-auto">
                <ShieldAlert className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-semibold text-white">Tautan Undangan Tidak Valid</h3>
                <p className="text-xs text-slate-400 mt-1">
                  {error || 'Undangan ini tidak ditemukan, sudah pernah diterima, atau telah melewati batas kedaluwarsa.'}
                </p>
              </div>
              <Link
                href="/login"
                className="inline-flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-slate-800 hover:bg-slate-700 text-white font-medium text-xs rounded-xl transition-all"
              >
                <span>Ke Halaman Masuk</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </Link>
            </div>
          ) : (
            <div className="space-y-5">
              {/* Workspace Badge */}
              <div className="p-4 rounded-xl bg-slate-950/70 border border-slate-800/80 space-y-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 flex items-center justify-center font-bold text-sm">
                    {tenant.name.slice(0, 2).toUpperCase()}
                  </div>
                  <div>
                    <h4 className="text-sm font-bold text-white">{tenant.name}</h4>
                    <p className="text-[11px] text-slate-400">/{tenant.slug}</p>
                  </div>
                </div>

                <div className="pt-2 border-t border-slate-800/60 grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-[11px] text-slate-400 block">Peran yang Diberikan:</span>
                    <span className="inline-flex items-center gap-1 font-semibold text-indigo-300 font-mono text-[11px] uppercase mt-0.5">
                      <Shield className="w-3 h-3" />
                      {invitation.role}
                    </span>
                  </div>
                  <div>
                    <span className="text-[11px] text-slate-400 block">Tujuan Email:</span>
                    <span className="text-slate-300 font-medium truncate block text-[11px] mt-0.5" title={invitation.email}>
                      {invitation.email}
                    </span>
                  </div>
                </div>
              </div>

              {/* Action based on auth status */}
              {currentUser ? (
                <form onSubmit={handleAccept} className="space-y-4">
                  <div className="p-3 rounded-xl bg-indigo-950/30 border border-indigo-500/20 text-xs flex items-center gap-2.5">
                    <UserCheck className="w-4 h-4 text-indigo-400 shrink-0" />
                    <div className="text-slate-300">
                      Menerima sebagai <strong className="text-white">{currentUser.name}</strong> ({currentUser.email})
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={processing}
                    className="w-full py-2.5 px-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-medium text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2 group disabled:opacity-50"
                  >
                    <span>{processing ? 'Menghubungkan...' : 'Terima Undangan & Gabung'}</span>
                    <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
                  </button>
                </form>
              ) : (
                <div className="space-y-3">
                  <p className="text-xs text-slate-400 text-center">
                    Silakan masuk ke akun Anda untuk menerima undangan ini:
                  </p>
                  <Link
                    href={`/login`}
                    className="w-full py-2.5 px-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-medium text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2 text-center"
                  >
                    <span>Masuk ke Akun Saya</span>
                    <ArrowRight className="w-3.5 h-3.5" />
                  </Link>
                  <Link
                    href={`/register`}
                    className="w-full py-2 px-4 bg-slate-950/80 border border-slate-800 hover:bg-slate-800 text-slate-300 hover:text-white font-medium text-xs rounded-xl transition-all flex items-center justify-center gap-2 text-center"
                  >
                    <span>Buat Akun Baru</span>
                  </Link>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
