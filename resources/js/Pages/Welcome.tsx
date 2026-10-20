import { Link } from '@inertiajs/react';
import { 
  Zap, 
  ArrowRight, 
  ShieldCheck, 
  Cpu, 
  Database, 
  Sparkles,
  Code2
} from 'lucide-react';

interface WelcomeProps {
  appName: string;
  phpVersion: string;
}

export default function Welcome({ appName, phpVersion }: WelcomeProps) {
  const features = [
    {
      icon: Zap,
      title: 'Inertia.js Protocol V2',
      desc: 'Bebas dari overhead REST/GraphQL boilerplate ganda. Controller backend langsung memompa props ke React 19 UI.',
    },
    {
      icon: Cpu,
      title: 'PHP 8.3 + FrankenPHP',
      desc: 'Worker mode persisten dengan latensi mikrodetik, HTTP/3 terintegrasi, dan kemudahan deploy single-binary.',
    },
    {
      icon: Database,
      title: 'Built-in Multi-Tenancy',
      desc: 'Tenant scoping otomatis di level database PDO. Mencegah kebocoran data antar tenant SaaS secara deterministik.',
    },
    {
      icon: ShieldCheck,
      title: 'Stateful Hardened Security',
      desc: 'Sesi aman berbasis HTTP-only SameSite cookies dan proteksi CSRF otomatis tanpa celah token theft.',
    },
  ];

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 selection:bg-emerald-500 selection:text-white flex flex-col justify-between">
      {/* Background Glow Accents */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[700px] h-[500px] bg-gradient-to-b from-emerald-500/15 via-teal-500/5 to-transparent blur-3xl rounded-full"></div>
        <div className="absolute bottom-0 right-0 w-[500px] h-[400px] bg-emerald-600/10 blur-3xl rounded-full"></div>
      </div>

      {/* Navbar */}
      <header className="border-b border-slate-800/80 bg-slate-950/60 backdrop-blur-md sticky top-0 z-50">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="h-9 w-9 rounded-xl bg-gradient-to-tr from-emerald-500 to-cyan-500 flex items-center justify-center shadow-lg shadow-emerald-500/20">
              <Zap className="h-5 w-5 text-slate-950 font-black" />
            </div>
            <span className="font-extrabold text-lg tracking-tight bg-gradient-to-r from-white to-slate-300 bg-clip-text text-transparent">
              {appName || 'aio'}
            </span>
            <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-slate-800 text-emerald-400 border border-slate-700">
              PHP {phpVersion || '8.3'}
            </span>
          </div>

          <div className="flex items-center gap-4">
            <Link
              href="/dashboard"
              className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition-all duration-200 shadow-lg shadow-emerald-500/25 active:scale-95"
            >
              <span>Buka Demo Dashboard</span>
              <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </div>
        </div>
      </header>

      {/* Hero Section */}
      <main className="max-w-7xl mx-auto px-6 py-20 flex-1 flex flex-col items-center text-center justify-center">
        <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-slate-900 border border-slate-800 text-xs text-slate-300 mb-8 shadow-inner">
          <Sparkles className="h-4 w-4 text-emerald-400 animate-spin" style={{ animationDuration: '6s' }} />
          <span>Unified Full-Stack SaaS Engine untuk Solo & Agile Builders</span>
        </div>

        <h1 className="text-4xl sm:text-6xl md:text-7xl font-extrabold tracking-tight max-w-4xl leading-tight">
          Satu Arsitektur.{' '}
          <span className="bg-gradient-to-r from-emerald-400 via-teal-300 to-cyan-400 bg-clip-text text-transparent">
            Produktivitas Monolitik
          </span>
          , Interaktivitas SPA.
        </h1>

        <p className="mt-6 text-base sm:text-lg text-slate-400 max-w-2xl font-normal leading-relaxed">
          Kombinasi kekuatan performa tinggi PHP 8.3 & FrankenPHP di backend dengan fleksibilitas React 19 di frontend via protokol Inertia.js. Tanpa repot menulis dan menyinkronkan API ganda.
        </p>

        <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
          <Link
            href="/dashboard"
            className="inline-flex items-center gap-2 px-6 py-3.5 rounded-xl text-sm font-semibold bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition-all duration-200 shadow-xl shadow-emerald-500/30 hover:scale-[1.02] active:scale-95"
          >
            <span>Eksplorasi Dashboard SaaS</span>
            <ArrowRight className="h-4 w-4" />
          </Link>
          <a
            href="https://inertiajs.com"
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-2 px-6 py-3.5 rounded-xl text-sm font-medium bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800 transition-colors"
          >
            <Code2 className="h-4 w-4 text-emerald-400" />
            <span>Protokol Inertia.js</span>
          </a>
        </div>

        {/* Features Grid */}
        <div className="mt-24 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-left w-full">
          {features.map((feat, i) => {
            const Icon = feat.icon;
            return (
              <div
                key={i}
                className="p-6 rounded-2xl bg-slate-900/40 border border-slate-800/80 hover:border-emerald-500/40 transition-all duration-300 group hover:shadow-xl hover:shadow-emerald-500/5 backdrop-blur-sm"
              >
                <div className="h-10 w-10 rounded-xl bg-slate-800 border border-slate-700/60 flex items-center justify-center mb-4 group-hover:bg-emerald-500/10 group-hover:border-emerald-500/30 transition-colors">
                  <Icon className="h-5 w-5 text-emerald-400" />
                </div>
                <h2 className="text-base font-semibold text-slate-200 group-hover:text-emerald-300 transition-colors">
                  {feat.title}
                </h2>
                <p className="mt-2 text-xs text-slate-400 leading-relaxed">
                  {feat.desc}
                </p>
              </div>
            );
          })}
        </div>
      </main>

      {/* Footer */}
      <footer className="border-t border-slate-900 py-6 text-center text-xs text-slate-500">
        <p>&copy; 2026 aio Framework. Dirancang untuk efisiensi rekayasa perangkat lunak tertinggi.</p>
      </footer>
    </div>
  );
}
