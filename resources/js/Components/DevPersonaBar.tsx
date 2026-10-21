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
      color: 'bg-emerald-50 text-emerald-700 border-emerald-200',
      badge: 'Owner (Acme)',
    },
    {
      userId: 2,
      tenantId: 1,
      name: 'Sarah Smith',
      tenantName: 'Acme Corp',
      role: 'member',
      color: 'bg-blue-50 text-blue-700 border-blue-200',
      badge: 'Member (Acme)',
    },
    {
      userId: 3,
      tenantId: 2,
      name: 'Alex Rivera',
      tenantName: 'Starlight Labs',
      role: 'owner',
      color: 'bg-purple-50 text-purple-700 border-purple-200',
      badge: 'Owner (Starlight)',
    },
    {
      userId: 4,
      tenantId: 1,
      name: 'System Superadmin',
      tenantName: 'Platform Global',
      role: 'superadmin',
      color: 'bg-amber-500/10 text-amber-500 border-amber-500/30',
      badge: 'Platform Superadmin',
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
        <div className="mb-2 p-3.5 bg-white border border-gray-200 rounded-xl shadow-xl w-80 text-xs text-gray-700 animate-in fade-in slide-in-from-bottom-2 duration-150">
          <div className="flex items-center justify-between pb-2 mb-2 border-b border-gray-200">
            <div className="flex items-center gap-1.5 font-bold text-blue-700">
              <Sparkles className="w-3.5 h-3.5 text-blue-600" />
              <span>Dev Fast Persona Switcher</span>
            </div>
            <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 border border-gray-200 font-semibold">
              Local Dev
            </span>
          </div>

          <p className="text-[11px] text-gray-500 mb-2.5">
            Ganti persona secara instan untuk menguji tampilan UI dan izin RBAC tanpa perlu login manual:
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
                  className={`w-full text-left p-2 rounded-lg border transition-all flex items-center justify-between ${
                    isCurrent
                      ? 'bg-blue-50 border-blue-400 text-blue-900 font-medium shadow-xs'
                      : 'bg-white border-gray-200 hover:bg-gray-50 text-gray-700'
                  }`}
                >
                  <div className="flex flex-col truncate pr-2">
                    <span className="font-semibold text-gray-900 flex items-center gap-1.5">
                      {p.name}
                      {isCurrent && (
                        <span className="w-1.5 h-1.5 rounded-full bg-blue-600 inline-block" />
                      )}
                    </span>
                    <span className="text-[10px] text-gray-500">
                      {p.tenantName}
                    </span>
                  </div>
                  <span
                    className={`text-[10px] uppercase tracking-wider font-mono font-semibold px-2 py-0.5 rounded-md border ${p.color}`}
                  >
                    {p.role}
                  </span>
                </button>
              );
            })}
          </div>

          <div className="mt-3 pt-2 border-t border-gray-100 flex items-center justify-between text-[10px] text-gray-400 font-mono">
            <span>Route: /_dev/switch-persona</span>
            <span className="text-emerald-600 font-semibold">DEV_MODE=ON</span>
          </div>
        </div>
      )}

      {/* Floating Toggle Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-2 px-3 py-1.5 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold rounded-full shadow-md border border-gray-200 transition-all active:scale-95 group"
      >
        <div className="flex items-center gap-1.5">
          <span className="w-2 h-2 rounded-full bg-emerald-500 inline-block" />
          <UserCheck className="w-3.5 h-3.5 text-blue-600" />
        </div>
        <div className="flex items-center gap-1.5 font-mono text-[11px]">
          <span className="text-gray-900">{currentUser}</span>
          <span className="text-gray-400">/</span>
          <span className="px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-bold uppercase text-[9px] border border-blue-200">
            {currentRole}
          </span>
        </div>
        {isOpen ? (
          <ChevronDown className="w-3.5 h-3.5 text-gray-400" />
        ) : (
          <ChevronUp className="w-3.5 h-3.5 text-gray-400" />
        )}
      </button>
    </div>
  );
}
