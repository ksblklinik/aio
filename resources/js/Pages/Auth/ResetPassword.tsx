import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Lock, ArrowRight, ArrowLeft, ShieldAlert, CheckCircle2, AlertCircle } from 'lucide-react';

interface ResetPasswordProps {
  token: string;
  email: string;
  isValid: boolean;
  flash?: {
    error?: string | null;
    success?: string | null;
  };
}

export default function ResetPassword({ token, email, isValid }: ResetPasswordProps) {
  const { props } = usePage<{ flash?: { error?: string | null; success?: string | null }; csrf_token?: string }>();
  const { data, setData, post, processing, errors } = useForm({
    token: token || '',
    password: '',
    password_confirmation: '',
    _token: props.csrf_token || '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/reset-password');
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
            <Lock className="h-6 w-6 text-white" />
          </div>
          <h2 className="text-2xl font-extrabold tracking-tight text-white">
            Atur Ulang Kata Sandi
          </h2>
          <p className="text-xs text-slate-400">
            Buat kata sandi baru yang aman untuk akun Anda.
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

        {/* Content Card */}
        <div className="bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-2xl p-6 shadow-2xl space-y-4">
          {!isValid ? (
            <div className="text-center space-y-4 py-2">
              <div className="w-12 h-12 rounded-full bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center mx-auto">
                <ShieldAlert className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-sm font-semibold text-white">Tautan Tidak Valid atau Kedaluwarsa</h3>
                <p className="text-xs text-slate-400 mt-1">
                  Tautan pengaturan ulang kata sandi ini sudah tidak berlaku atau sudah pernah digunakan.
                </p>
              </div>
              <Link
                href="/forgot-password"
                className="inline-flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all"
              >
                <span>Minta Tautan Baru</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </Link>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              {email && (
                <div className="p-2.5 rounded-xl bg-slate-950/60 border border-slate-800 text-xs flex items-center justify-between">
                  <span className="text-slate-400">Akun:</span>
                  <span className="font-semibold text-slate-200 font-mono">{email}</span>
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Kata Sandi Baru (Min. 8 Karakter)
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                  <input
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    placeholder="••••••••"
                    required
                    minLength={8}
                    className={`w-full pl-9 pr-3 py-2 bg-slate-950/80 border rounded-xl text-xs text-slate-100 placeholder-slate-400 focus:outline-none transition-all ${
                      errors.password 
                        ? 'border-rose-500 focus:border-rose-500 focus:ring-1 focus:ring-rose-500' 
                        : 'border-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'
                    }`}
                  />
                </div>
                {errors.password && (
                  <p className="text-[11px] text-rose-400 mt-1">{errors.password}</p>
                )}
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Konfirmasi Kata Sandi Baru
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                  <input
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    placeholder="••••••••"
                    required
                    minLength={8}
                    className={`w-full pl-9 pr-3 py-2 bg-slate-950/80 border rounded-xl text-xs text-slate-100 placeholder-slate-400 focus:outline-none transition-all ${
                      errors.password_confirmation 
                        ? 'border-rose-500 focus:border-rose-500 focus:ring-1 focus:ring-rose-500' 
                        : 'border-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'
                    }`}
                  />
                </div>
                {errors.password_confirmation && (
                  <p className="text-[11px] text-rose-400 mt-1">{errors.password_confirmation}</p>
                )}
              </div>

              <button
                type="submit"
                disabled={processing}
                className="w-full py-2.5 px-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-medium text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2 group disabled:opacity-50"
              >
                <span>{processing ? 'Menyimpan...' : 'Perbarui Kata Sandi'}</span>
                <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
              </button>
            </form>
          )}
        </div>

        {/* Back Link */}
        <div className="text-center text-xs text-slate-400">
          <Link
            href="/login"
            className="inline-flex items-center gap-1.5 text-indigo-400 hover:text-indigo-300 font-semibold"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            <span>Kembali ke Halaman Masuk</span>
          </Link>
        </div>
      </div>
    </div>
  );
}
