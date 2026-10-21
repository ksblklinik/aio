import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { UserCheck, ChevronUp, ChevronDown, Sparkles } from 'lucide-react';

interface AuthProp {
  user: { id: number; name: string; email: string } | null;
  tenant: { id: number; name: string; slug: string; plan: string } | null;
  role: string | null;
  tenants: Array<{ id: number; name: string; slug: string; role: string }>;
}

export default function DevPersonaBar() {
  const { props } = usePage<{ auth?: AuthProp; app?: { env: string } }>();
  const [isOpen, setIsOpen] = useState(false);

  // Only render on local dev environment
  if (props.app?.env && props.app.env !== 'local') {
    return null;
  }

  const personas = [
    {
      userId: 1,
      tenantId: 1,
      name: 'John Doe',
      tenantName: 'Acme Corp',
      role: 'owner',
      color: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
      badge: 'Owner (Acme)',
    },
    {
      userId: 2,
      tenantId: 1,
      name: 'Sarah Smith',
      tenantName: 'Acme Corp',
      role: 'member',
      color: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
      badge: 'Member (Acme)',
    },
    {
      userId: 3,
      tenantId: 2,
      name: 'Alex Rivera',
      tenantName: 'Starlight Labs',
      role: 'owner',
      color: 'bg-purple-500/20 text-purple-300 border-purple-500/30',
      badge: 'Owner (Starlight)',
    },
  ];

  const handleSwitch = (userId: number, tenantId: number) => {
    const currentPath = window.location.pathname;
    window.location.href = `/_dev/switch-persona?user_id=${userId}&tenant_id=${tenantId}&redirect=${encodeURIComponent(currentPath)}`;
  };

  const currentRole = props.auth?.role || 'Guest';
  const currentUser = props.auth?.user?.name || 'Guest';

  return (
    <div className="fixed bottom-4 right-4 z-50 flex flex-col items-end font-sans">
      {isOpen && (
        <div className="mb-2 p-3 bg-slate-900/95 backdrop-blur-md border border-slate-700/80 rounded-2xl shadow-2xl w-80 text-xs text-slate-200 animate-in fade-in slide-in-from-bottom-2 duration-150">
          <div className="flex items-center justify-between pb-2 mb-2 border-b border-slate-800">
            <div className="flex items-center gap-1.5 font-bold text-amber-400">
              <Sparkles className="w-3.5 h-3.5" />
              <span>Dev Fast Persona Switcher</span>
            </div>
            <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">
              Local Dev
            </span>
          </div>

          <p className="text-[11px] text-slate-400 mb-2.5">
            Ganti persona secara instan untuk menguji tampilan UI dan izin RBAC tanpa perlu ketik login:
          </p>

          <div className="space-y-1.5">
            {personas.map((p) => {
              const isCurrent =
                props.auth?.user?.id === p.userId &&
                props.auth?.tenant?.id === p.tenantId;

              return (
                <button
                  key={`${p.userId}-${p.tenantId}`}
                  onClick={() => handleSwitch(p.userId, p.tenantId)}
                  className={`w-full text-left p-2 rounded-xl border transition-all flex items-center justify-between ${
                    isCurrent
                      ? 'bg-slate-800 border-indigo-500 ring-1 ring-indigo-500/50 text-white font-medium shadow-sm'
                      : 'bg-slate-950/60 border-slate-800/80 hover:bg-slate-800/60 hover:border-slate-700 text-slate-300'
                  }`}
                >
                  <div className="flex flex-col truncate pr-2">
                    <span className="font-semibold text-slate-100 flex items-center gap-1.5">
                      {p.name}
                      {isCurrent && (
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block animate-pulse" />
                      )}
                    </span>
                    <span className="text-[10px] text-slate-400">
                      {p.tenantName}
                    </span>
                  </div>
                  <span
                    className={`text-[10px] uppercase tracking-wider font-mono px-2 py-0.5 rounded-md border ${p.color}`}
                  >
                    {p.role}
                  </span>
                </button>
              );
            })}
          </div>

          <div className="mt-3 pt-2 border-t border-slate-800 flex items-center justify-between text-[10px] text-slate-400">
            <span>Route: <code>/_dev/switch-persona</code></span>
            <span className="text-emerald-400 font-mono">DEV_AUTO_LOGIN=ON</span>
          </div>
        </div>
      )}

      {/* Floating Toggle Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-2.5 px-3.5 py-2 bg-gradient-to-r from-indigo-600 via-indigo-700 to-slate-900 hover:from-indigo-500 hover:to-slate-800 text-white text-xs font-medium rounded-full shadow-lg shadow-indigo-900/40 border border-indigo-500/40 backdrop-blur-md transition-all active:scale-95 group"
      >
        <div className="flex items-center gap-1.5">
          <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping inline-block" />
          <UserCheck className="w-3.5 h-3.5 text-indigo-200" />
        </div>
        <div className="flex items-center gap-1.5 font-mono text-[11px]">
          <span className="text-indigo-200">{currentUser}</span>
          <span className="text-slate-400">/</span>
          <span className="px-1.5 py-0.5 rounded bg-indigo-950/80 text-emerald-300 font-semibold uppercase text-[9px] border border-indigo-700/50">
            {currentRole}
          </span>
        </div>
        {isOpen ? (
          <ChevronDown className="w-3.5 h-3.5 text-slate-400 group-hover:text-white" />
        ) : (
          <ChevronUp className="w-3.5 h-3.5 text-slate-400 group-hover:text-white" />
        )}
      </button>
    </div>
  );
}
