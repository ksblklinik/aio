import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Zap, Mail, Lock, ArrowRight, Sparkles, CheckCircle2, AlertCircle } from 'lucide-react';

interface DemoUser {
  user_id: number;
  user_name: string;
  email: string;
  tenant_id: number;
  tenant_name: string;
  role: string;
}

interface LoginProps {
  demoUsers?: DemoUser[];
  isLocal?: boolean;
  flash?: {
    error?: string | null;
    success?: string | null;
  };
}

export default function Login({ demoUsers = [], isLocal = false }: LoginProps) {
  const { props } = usePage<{ 
    app?: { name?: string; tagline?: string; logo?: string | null; favicon?: string | null };
    flash?: { error?: string | null; success?: string | null }; 
    csrf_token?: string 
  }>();
  const appName = props.app?.name || 'aio SaaS';
  const appTagline = props.app?.tagline || 'Unified Full-Stack SaaS Framework with Multi-Tenancy';
  const appLogo = props.app?.logo || null;

  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    _token: props.csrf_token || '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/login');
  };

  const handleQuickLogin = (email: string) => {
    setData({
      email,
      password: 'password',
      _token: props.csrf_token || '',
    });
    // Auto submit
    setTimeout(() => {
      post('/login');
    }, 50);
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4 selection:bg-indigo-500 selection:text-white font-sans antialiased relative overflow-hidden">
      {/* Background Gradients */}
      <div className="absolute -top-40 -left-40 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute -bottom-40 -right-40 w-96 h-96 bg-purple-600/20 rounded-full blur-3xl pointer-events-none" />

      <div className="w-full max-w-md space-y-6 relative z-10">
        {/* Brand Header */}
        <div className="text-center space-y-2">
          {appLogo ? (
            <img src={appLogo} alt={appName} className="inline-block h-12 w-12 object-contain rounded-2xl shadow-lg" />
          ) : (
            <div className="inline-flex h-12 w-12 rounded-2xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 items-center justify-center shadow-lg shadow-indigo-500/30">
              <Zap className="h-6 w-6 text-white" />
            </div>
          )}
          <h2 className="text-2xl font-extrabold tracking-tight text-white">
            Masuk ke Akun Anda
          </h2>
          <p className="text-xs text-slate-400">
            {appTagline}
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

        {/* Login Card */}
        <div className="bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 rounded-2xl p-6 shadow-2xl space-y-4">
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-300 mb-1">
                Alamat Email
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                <input
                  type="email"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                  placeholder="anda@perusahaan.com"
                  className={`w-full pl-9 pr-3 py-2 bg-slate-950/80 border rounded-xl text-xs text-slate-100 placeholder-slate-400 focus:outline-none transition-all ${
                    errors.email 
                      ? 'border-rose-500 focus:border-rose-500 focus:ring-1 focus:ring-rose-500' 
                      : 'border-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500'
                  }`}
                />
              </div>
              {errors.email && (
                <p className="text-[11px] text-rose-400 mt-1">{errors.email}</p>
              )}
            </div>

            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="block text-xs font-medium text-slate-300">
                  Kata Sandi
                </label>
                <Link
                  href="/forgot-password"
                  className="text-[11px] text-indigo-400 hover:text-indigo-300 font-medium transition-colors"
                >
                  Lupa kata sandi?
                </Link>
              </div>
              <div className="relative">
                <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
                <input
                  type="password"
                  value={data.password}
                  onChange={(e) => setData('password', e.target.value)}
                  placeholder="••••••••"
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

            <button
              type="submit"
              disabled={processing}
              className="w-full py-2.5 px-4 bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white font-medium text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2 group disabled:opacity-50"
            >
              <span>{processing ? 'Memproses...' : 'Masuk Sekarang'}</span>
              <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
            </button>
          </form>

          {/* Quick Demo Login in Local Dev */}
          {isLocal && demoUsers.length > 0 && (
            <div className="mt-4 pt-4 border-t border-slate-800/80 space-y-2">
              <div className="flex items-center gap-1.5 text-amber-400 text-[11px] font-semibold">
                <Sparkles className="w-3.5 h-3.5" />
                <span>Dev 1-Click Fast Login (Tanpa Ketik Password):</span>
              </div>
              <div className="grid grid-cols-1 gap-1.5">
                {demoUsers.slice(0, 3).map((du) => (
                  <button
                    key={`${du.user_id}-${du.tenant_id}`}
                    type="button"
                    onClick={() => handleQuickLogin(du.email)}
                    className="p-2 rounded-xl bg-slate-950/80 border border-slate-800 hover:border-indigo-500/50 hover:bg-slate-800/60 text-left transition-all flex items-center justify-between text-xs group"
                  >
                    <div>
                      <div className="font-medium text-slate-200 group-hover:text-white">
                        {du.user_name}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        {du.tenant_name}
                      </div>
                    </div>
                    <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-slate-800 border border-slate-700 text-indigo-300 font-bold">
                      {du.role}
                    </span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Register Footer Link */}
        <div className="text-center text-xs text-slate-400">
          Belum memiliki workspace?{' '}
          <Link href="/register" className="text-indigo-400 hover:text-indigo-300 font-semibold underline underline-offset-4">
            Daftar Workspace Baru
          </Link>
        </div>
      </div>
    </div>
  );
}
