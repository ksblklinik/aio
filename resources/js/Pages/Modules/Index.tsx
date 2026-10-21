import React, { useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  Blocks, 
  Plus, 
  Trash2, 
  ShieldCheck, 
  Package, 
  Layers, 
  Building2, 
  DollarSign, 
  Mail, 
  Settings, 
  Users, 
  LifeBuoy, 
  Search, 
  AlertTriangle, 
  X,
  ExternalLink,
  BookOpen,
  Coins,
  MessageSquare,
  BarChart3,
  Code2,
  Filter,
  ChevronDown,
  Check,
  RotateCcw
} from 'lucide-react';

interface ModuleItem {
  id: string;
  name: string;
  version: string;
  author: string;
  description: string;
  category: string;
  category_id?: string;
  category_label?: string;
  category_badge_class?: string;
  icon: string;
  route: string;
  dependencies: string[];
  is_core: boolean;
  is_active: boolean;
  system_active?: boolean;
  table_name: string | null;
}

export interface CategoryItem {
  id: string;
  label: string;
  icon: string;
  color: string;
  badge_class: string;
  description: string;
}

interface Props {
  modules: ModuleItem[];
  categories?: CategoryItem[];
  title?: string;
  currentWorkspace?: {
    id: number;
    name: string;
    slug: string;
  } | null;
  isSuperadmin?: boolean;
}

const iconRegistry: Record<string, React.ElementType> = {
  Blocks,
  Package,
  Layers,
  Building2,
  ShieldCheck,
  DollarSign,
  Mail,
  Settings,
  Users,
  LifeBuoy,
  BookOpen,
  Coins,
  MessageSquare,
  BarChart3,
  Code2,
};

export default function ModulesIndex({ modules = [], categories: serverCategories = [], currentWorkspace, isSuperadmin }: Props) {
  const { props } = usePage<{ csrf_token?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [isFilterDropdownOpen, setIsFilterDropdownOpen] = useState(false);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [deleteConfirmModule, setDeleteConfirmModule] = useState<ModuleItem | null>(null);
  const dropdownRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsFilterDropdownOpen(false);
      }
    }
    if (isFilterDropdownOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      return () => document.removeEventListener('mousedown', handleClickOutside);
    }
  }, [isFilterDropdownOpen]);

  // Form Scaffold
  const { data, setData, post, reset, processing, errors } = useForm({
    name: '',
    id: '',
    description: '',
    category: 'collaboration',
    icon: 'Package',
    _token: props.csrf_token || '',
  });

  const categoryFilters = [
    { id: 'all', label: 'Semua Kategori', icon: 'Blocks', count: modules.length },
    ...(serverCategories && serverCategories.length > 0
      ? serverCategories.map((c) => ({
          id: c.id,
          label: c.label,
          icon: c.icon,
          badge_class: c.badge_class,
          count: modules.filter((m) => (m.category_id || m.category) === c.id).length
        }))
      : Array.from(new Set(modules.map((m) => m.category_id || m.category).filter(Boolean))).map((cat) => ({
          id: cat,
          label: cat,
          icon: 'Package',
          badge_class: '',
          count: modules.filter((m) => (m.category_id || m.category) === cat).length
        }))
    )
  ];

  const activeCategoryObj = categoryFilters.find((c) => c.id === selectedCategory) || categoryFilters[0];

  const filteredModules = modules.filter((m) => {
    const matchesSearch = 
      m.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      m.description.toLowerCase().includes(searchQuery.toLowerCase()) ||
      m.id.toLowerCase().includes(searchQuery.toLowerCase());
    const modCatId = m.category_id || m.category;
    const matchesCat = selectedCategory === 'all' || modCatId === selectedCategory || m.category === selectedCategory;
    return matchesSearch && matchesCat;
  });

  const handleToggleActive = (module: ModuleItem, scope: 'tenant' | 'global' = 'tenant') => {
    if (module.is_core) {
      alert(`Modul inti [${module.name}] dilindungi dan tidak dapat dinonaktifkan.`);
      return;
    }

    router.post('/modules/toggle', { id: module.id, scope });
  };

  const handleCreateModule = (e: React.FormEvent) => {
    e.preventDefault();
    post('/modules/create', {
      onSuccess: () => {
        reset();
        setShowCreateModal(false);
      },
    });
  };

  const handleDeleteModule = () => {
    if (!deleteConfirmModule) return;
    router.post('/modules/delete', { id: deleteConfirmModule.id }, {
      onFinish: () => setDeleteConfirmModule(null),
    });
  };

  return (
    <AppLayout title="Manajer Modul & Ekstensi">
      <div className="space-y-6 max-w-7xl mx-auto">
        {/* Header Actions */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-border-subtle">
          <div>
            <div className="flex flex-wrap items-center gap-2.5">
              <h2 className="text-xl font-bold tracking-tight text-text-main flex items-center gap-2">
                <Blocks className="w-5 h-5 text-brand-primary" />
                <span>Manajer Modul</span>
              </h2>
              {isSuperadmin && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                  <ShieldCheck className="w-3.5 h-3.5" />
                  <span>Platform Superadmin</span>
                </span>
              )}
              {currentWorkspace && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                  <Building2 className="w-3.5 h-3.5" />
                  <span>Workspace: {currentWorkspace.name}</span>
                </span>
              )}
              <span className="text-xs px-2.5 py-0.5 rounded-full bg-brand-primary-light text-brand-primary font-semibold border border-brand-primary/20">
                {modules.filter((m) => m.is_active).length} Aktif / {modules.length} Total
              </span>
            </div>
            <p className="text-xs text-text-muted mt-1">
              {isSuperadmin
                ? 'Anda masuk sebagai Platform Superadmin. Anda memiliki hak akses penuh untuk mengelola modul secara global di seluruh server.'
                : currentWorkspace
                ? `Kelola modul & fitur khusus untuk workspace '${currentWorkspace.name}'. Pengaturan aktif/nonaktif diisolasi per tenant.`
                : 'Pusat kendali ekstensi, dependensi, dan siklus hidup modular framework SaaS aio.'}
            </p>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-lg shadow-sm transition-all active:scale-95 shrink-0"
          >
            <Plus className="w-4 h-4" />
            <span>Tambah Modul Baru</span>
          </button>
        </div>

        {/* Responsive Control Bar */}
        <div className="bg-surface p-3 rounded-xl border border-border-subtle shadow-xs space-y-2.5">
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5">
            {/* Search Input */}
            <div className="relative flex-1">
              <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
              <input
                type="text"
                placeholder="Cari modul berdasarkan nama, slug, atau deskripsi..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-9 pr-8 py-2 bg-surface-subtle border border-border-subtle rounded-lg text-xs text-text-main placeholder-text-muted focus:outline-none focus:bg-surface focus:border-brand-primary transition-colors"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 rounded text-text-muted hover:text-text-main"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Category Filter Dropdown */}
            <div className="relative shrink-0" ref={dropdownRef}>
              <button
                type="button"
                onClick={() => setIsFilterDropdownOpen((prev) => !prev)}
                className={`w-full sm:w-auto inline-flex items-center justify-between sm:justify-start gap-2.5 px-3.5 py-2 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                  selectedCategory !== 'all'
                    ? 'bg-brand-primary-light text-brand-primary border-brand-primary/30 shadow-2xs'
                    : 'bg-surface-subtle hover:bg-surface text-text-main border-border-subtle'
                }`}
              >
                <div className="flex items-center gap-2 truncate">
                  <Filter className="w-3.5 h-3.5 text-text-muted" />
                  <span className="text-text-muted font-normal">Kategori:</span>
                  <span className="font-semibold text-text-main truncate max-w-[140px] sm:max-w-[200px]">
                    {activeCategoryObj.label}
                  </span>
                </div>
                <div className="flex items-center gap-1.5 shrink-0 pl-1">
                  <span className="px-1.5 py-0.2 rounded-full text-[10px] font-mono font-medium bg-surface text-text-muted border border-border-subtle">
                    {filteredModules.length}
                  </span>
                  <ChevronDown className={`w-3.5 h-3.5 text-text-muted transition-transform duration-200 ${isFilterDropdownOpen ? 'rotate-180' : ''}`} />
                </div>
              </button>

              {/* Dropdown Menu Popover */}
              {isFilterDropdownOpen && (
                <div className="absolute right-0 top-full mt-1.5 w-72 sm:w-80 bg-surface border border-border-subtle rounded-xl shadow-xl z-30 p-1.5 animate-in fade-in zoom-in-95 duration-100">
                  <div className="px-2.5 py-2 border-b border-border-subtle flex items-center justify-between">
                    <span className="text-[11px] font-bold text-text-muted uppercase tracking-wider">
                      Pilih Kategori Organisasi
                    </span>
                    {selectedCategory !== 'all' && (
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedCategory('all');
                          setIsFilterDropdownOpen(false);
                        }}
                        className="text-[10px] text-brand-primary hover:underline font-semibold"
                      >
                        Reset Filter
                      </button>
                    )}
                  </div>

                  <div className="py-1 max-h-64 overflow-y-auto space-y-0.5">
                    {categoryFilters.map((cat) => {
                      const CatIcon = iconRegistry[cat.icon] || Package;
                      const isSelected = selectedCategory === cat.id;

                      return (
                        <button
                          key={cat.id}
                          type="button"
                          onClick={() => {
                            setSelectedCategory(cat.id);
                            setIsFilterDropdownOpen(false);
                          }}
                          className={`w-full flex items-center justify-between px-2.5 py-2 rounded-lg text-xs transition-colors cursor-pointer ${
                            isSelected
                              ? 'bg-brand-primary-light text-brand-primary font-semibold'
                              : 'text-text-main hover:bg-surface-subtle'
                          }`}
                        >
                          <div className="flex items-center gap-2.5 truncate">
                            <CatIcon className={`w-4 h-4 shrink-0 ${isSelected ? 'text-brand-primary' : 'text-text-muted'}`} />
                            <span className="truncate">{cat.label}</span>
                          </div>
                          <div className="flex items-center gap-2 shrink-0">
                            <span className={`text-[10px] font-mono px-1.5 py-0.2 rounded-full ${
                              isSelected
                                ? 'bg-brand-primary text-white font-bold'
                                : 'bg-surface-subtle text-text-muted border border-border-subtle'
                            }`}>
                              {cat.count}
                            </span>
                            {isSelected && <Check className="w-3.5 h-3.5 text-brand-primary shrink-0" />}
                          </div>
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Active Filter Chips / Reset Bar */}
          {(selectedCategory !== 'all' || searchQuery) && (
            <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-dashed border-border-subtle text-xs">
              <span className="text-text-muted text-[11px]">Filter Aktif:</span>
              {selectedCategory !== 'all' && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-brand-primary-light text-brand-primary border border-brand-primary/20">
                  <span>Kategori: {activeCategoryObj.label}</span>
                  <button
                    type="button"
                    onClick={() => setSelectedCategory('all')}
                    className="hover:text-brand-primary-hover p-0.5 rounded-full cursor-pointer"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              {searchQuery && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-text-main border border-border-subtle">
                  <span>Pencarian: "{searchQuery}"</span>
                  <button
                    type="button"
                    onClick={() => setSearchQuery('')}
                    className="text-text-muted hover:text-text-main p-0.5 rounded-full cursor-pointer"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </span>
              )}
              <button
                type="button"
                onClick={() => {
                  setSelectedCategory('all');
                  setSearchQuery('');
                }}
                className="text-[11px] text-text-muted hover:text-rose-500 font-medium ml-auto flex items-center gap-1 transition-colors cursor-pointer"
              >
                <RotateCcw className="w-3 h-3" />
                <span>Reset Semua</span>
              </button>
            </div>
          )}
        </div>

        {/* Modules Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {filteredModules.map((module) => {
            const Icon = iconRegistry[module.icon] || Package;

            return (
              <div
                key={module.id}
                className={`rounded-xl bg-surface border transition-all p-5 flex flex-col justify-between shadow-xs ${
                  module.is_active 
                    ? 'border-border-subtle hover:border-border-main' 
                    : 'border-border-subtle opacity-75 bg-surface-subtle/50'
                }`}
              >
                <div className="space-y-4">
                  {/* Top Card Info */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className={`h-10 w-10 rounded-xl flex items-center justify-center shrink-0 border ${
                        module.is_active 
                          ? 'bg-brand-primary-light border-brand-primary/20 text-brand-primary' 
                          : 'bg-surface-subtle border-border-subtle text-text-muted'
                      }`}>
                        <Icon className="h-5 w-5" />
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <h3 className="font-bold text-sm text-text-main">
                            {module.name}
                          </h3>
                          <span className="text-[10px] font-mono text-text-muted bg-surface-subtle px-1.5 py-0.2 rounded border border-border-subtle">
                            v{module.version}
                          </span>
                        </div>
                        <span className="text-[11px] text-text-muted">
                          by {module.author}
                        </span>
                      </div>
                    </div>

                    {/* Badge Core / Extension / Server Status */}
                    <div className="flex items-center gap-1.5 shrink-0">
                      {module.system_active === false && (
                        <span className="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full border bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20">
                          Mati di Server
                        </span>
                      )}
                      <span className={`text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full border ${
                        module.is_core
                          ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20'
                          : 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20'
                      }`}>
                        {module.is_core ? 'Core' : 'Ekstensi'}
                      </span>
                    </div>
                  </div>

                  {/* Description */}
                  <p className="text-xs text-text-muted line-clamp-3 leading-relaxed">
                    {module.description || 'Tidak ada deskripsi modul.'}
                  </p>

                  {/* Dependencies & Category */}
                  <div className="space-y-1.5 pt-2 border-t border-border-subtle text-[11px]">
                    <div className="flex items-center justify-between text-text-muted">
                      <span>Kategori:</span>
                      <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border ${module.category_badge_class || 'bg-slate-500/10 text-slate-700 dark:text-slate-300 border-slate-500/20'}`}>
                        {module.category_label || module.category}
                      </span>
                    </div>

                    <div className="flex items-center justify-between text-text-muted">
                      <span>Dependensi:</span>
                      <div className="flex items-center gap-1">
                        {module.dependencies && module.dependencies.length > 0 ? (
                          module.dependencies.map((dep) => (
                            <span key={dep} className="font-mono text-[10px] px-1.5 py-0.2 rounded bg-surface-subtle text-text-main border border-border-subtle">
                              {dep}
                            </span>
                          ))
                        ) : (
                          <span className="italic text-text-muted">Mandiri (None)</span>
                        )}
                      </div>
                    </div>

                    {module.table_name && (
                      <div className="flex items-center justify-between text-text-muted">
                        <span>Tabel Data:</span>
                        <code className="font-mono text-[10px] text-text-main">{module.table_name}</code>
                      </div>
                    )}
                  </div>
                </div>

                {/* Bottom Actions: Toggle & Delete */}
                <div className="mt-5 pt-3 border-t border-border-subtle flex flex-col gap-2.5">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      {/* Toggle Switch */}
                      <button
                        type="button"
                        onClick={() => {
                          if (module.system_active === false && isSuperadmin) {
                            handleToggleActive(module, 'global');
                          } else {
                            handleToggleActive(module, 'tenant');
                          }
                        }}
                        disabled={module.is_core}
                        title={
                          module.is_core 
                            ? 'Modul inti tidak dapat dinonaktifkan' 
                            : module.system_active === false && isSuperadmin
                            ? 'Klik untuk mengaktifkan kembali di tingkat server (Global)'
                            : (module.is_active ? 'Nonaktifkan untuk Workspace' : 'Aktifkan untuk Workspace')
                        }
                        className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                          module.is_core 
                            ? 'opacity-50 cursor-not-allowed bg-emerald-500' 
                            : module.is_active 
                            ? 'bg-emerald-500' 
                            : 'bg-surface-subtle'
                        }`}
                      >
                        <span
                          className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out ${
                            module.is_active ? 'translate-x-4' : 'translate-x-0'
                          }`}
                        />
                      </button>
                      <span className="text-xs font-medium text-text-main">
                        {module.system_active === false ? 'Mati di Server' : (module.is_active ? 'Aktif' : 'Nonaktif')}
                      </span>
                    </div>

                    {/* Action Links */}
                    <div className="flex items-center gap-2">
                      {module.is_active && module.route && !module.is_core && (
                        <a
                          href={module.route}
                          title="Buka Halaman Modul"
                          className="p-1.5 rounded-lg text-text-muted hover:text-brand-primary hover:bg-surface-subtle transition-colors"
                        >
                          <ExternalLink className="w-4 h-4" />
                        </a>
                      )}

                      {!module.is_core && (
                        <button
                          type="button"
                          onClick={() => setDeleteConfirmModule(module)}
                          title="Hapus Modul (Clean Wipe)"
                          className="p-1.5 rounded-lg text-text-muted hover:text-red-600 hover:bg-red-500/10 transition-colors"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </div>

                  {/* Superadmin Dual Controls (Server-Wide vs Workspace) */}
                  {isSuperadmin && !module.is_core && (
                    <div className="pt-2 border-t border-dashed border-border-subtle flex items-center justify-between text-[11px] text-text-muted">
                      <span className="flex items-center gap-1">
                        <ShieldCheck className="w-3 h-3 text-amber-500 shrink-0" />
                        <span>Server:</span>
                        <strong className={module.system_active === false ? 'text-rose-500 font-semibold' : 'text-emerald-500 font-semibold'}>
                          {module.system_active === false ? 'Mati' : 'Aktif'}
                        </strong>
                      </span>
                      <button
                        type="button"
                        onClick={() => handleToggleActive(module, 'global')}
                        className={`text-[10px] font-semibold px-2 py-0.5 rounded border transition-colors ${
                          module.system_active === false
                            ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 border-emerald-500/30'
                            : 'bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 border-rose-500/30'
                        }`}
                      >
                        {module.system_active === false ? 'Aktifkan di Server' : 'Matikan di Server'}
                      </button>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {filteredModules.length === 0 && (
          <div className="p-12 text-center rounded-xl bg-surface border border-border-subtle shadow-xs space-y-3">
            <Blocks className="w-10 h-10 text-text-muted mx-auto" />
            <h3 className="text-sm font-bold text-text-main">Tidak ada modul yang cocok</h3>
            <p className="text-xs text-text-muted">Cobalah kata kunci lain atau pilih kategori Semua.</p>
          </div>
        )}
      </div>

      {/* Modal Tambah Modul Baru (Scaffold Wizard) */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="w-full max-w-lg bg-surface border border-border-subtle rounded-xl p-6 shadow-xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-border-subtle">
              <h3 className="text-base font-bold text-text-main flex items-center gap-2">
                <Plus className="w-5 h-5 text-brand-primary" />
                <span>Buat Template Modul Baru</span>
              </h3>
              <button
                onClick={() => setShowCreateModal(false)}
                className="p-1 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateModule} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Nama Modul
                </label>
                <input
                  type="text"
                  placeholder="Misal: Invoicing & Billing"
                  value={data.name}
                  onChange={(e) => {
                    const val = e.target.value;
                    setData((prev) => ({
                      ...prev,
                      name: val,
                      id: prev.id ? prev.id : val.toLowerCase().replace(/[^a-zA-Z0-9]/g, '_'),
                    }));
                  }}
                  className={`w-full px-3 py-2 bg-surface border rounded-lg text-xs text-text-main placeholder-text-muted focus:outline-none transition-colors ${
                    errors.name ? 'border-rose-500 focus:border-rose-500 focus:ring-1 focus:ring-rose-500' : 'border-border-subtle focus:border-brand-primary'
                  }`}
                />
                {errors.name && (
                  <p className="text-[11px] text-rose-500 mt-1">{errors.name}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-text-main mb-1">
                    Slug ID (Unik)
                  </label>
                  <input
                    type="text"
                    placeholder="invoicing"
                    value={data.id}
                    onChange={(e) => setData('id', e.target.value)}
                    className={`w-full px-3 py-2 bg-surface border rounded-lg text-xs text-text-main placeholder-text-muted font-mono focus:outline-none transition-colors ${
                      errors.id ? 'border-rose-500 focus:border-rose-500 focus:ring-1 focus:ring-rose-500' : 'border-border-subtle focus:border-brand-primary'
                    }`}
                  />
                  {errors.id && (
                    <p className="text-[11px] text-rose-500 mt-1">{errors.id}</p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-main mb-1">
                    Kategori Organisasi
                  </label>
                  <select
                    value={data.category}
                    onChange={(e) => setData('category', e.target.value)}
                    className="w-full px-3 py-2 bg-surface border border-border-subtle rounded-lg text-xs text-text-main focus:outline-none focus:border-brand-primary"
                  >
                    {serverCategories && serverCategories.length > 0 ? (
                      serverCategories.map((cat) => (
                        <option key={cat.id} value={cat.id}>
                          {cat.label} ({cat.id})
                        </option>
                      ))
                    ) : (
                      <>
                        <option value="collaboration">Kolaborasi & Alur Kerja</option>
                        <option value="core">Core & Keamanan</option>
                        <option value="people">Anggota & Keanggotaan</option>
                        <option value="knowledge">Pengetahuan & Dokumen</option>
                        <option value="resources">Sumber Daya & Finansial</option>
                        <option value="engagement">Komunikasi & Pengumuman</option>
                        <option value="analytics">Analitik & Laporan</option>
                        <option value="developer">Integrasi & Ekstensi</option>
                      </>
                    )}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Deskripsi Modul
                </label>
                <textarea
                  rows={2}
                  placeholder="Jelaskan tujuan dan fungsi modul ini..."
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  className="w-full px-3 py-2 bg-surface border border-border-subtle rounded-lg text-xs text-text-main placeholder-text-muted focus:outline-none focus:border-brand-primary"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Pilih Ikon Tampilan
                </label>
                <div className="grid grid-cols-5 gap-2">
                  {Object.keys(iconRegistry).map((iconName) => {
                    const IconComp = iconRegistry[iconName];
                    const isSelected = data.icon === iconName;
                    return (
                      <button
                        type="button"
                        key={iconName}
                        onClick={() => setData('icon', iconName)}
                        className={`p-2 rounded-lg border flex flex-col items-center gap-1 transition-colors ${
                          isSelected
                            ? 'bg-brand-primary-light border-brand-primary text-brand-primary font-semibold'
                            : 'bg-surface border-border-subtle text-text-muted hover:bg-surface-subtle'
                        }`}
                      >
                        <IconComp className="w-4 h-4" />
                        <span className="text-[9px] truncate w-full text-center">{iconName}</span>
                      </button>
                    );
                  })}
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-3 border-t border-border-subtle">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 rounded-lg text-xs font-medium text-text-muted hover:bg-surface-subtle transition-colors"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={processing}
                  className="px-4 py-2 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-lg shadow-sm transition-all disabled:opacity-50"
                >
                  {processing ? 'Menyusun...' : 'Scaffold Modul'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Konfirmasi Hapus (Clean Wipe Warning) */}
      {deleteConfirmModule && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-surface border border-rose-500/30 rounded-xl p-6 shadow-xl space-y-4">
            <div className="flex items-center gap-3 text-rose-500">
              <div className="h-10 w-10 rounded-full bg-rose-500/10 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-bold text-base text-text-main">Hapus Modul (Clean Wipe)?</h3>
                <span className="text-xs text-text-muted">Modul: {deleteConfirmModule.name}</span>
              </div>
            </div>

            <div className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-xs text-rose-700 dark:text-rose-300 space-y-1.5 leading-relaxed">
              <p className="font-semibold">Perhatian Tindakan Permanen:</p>
              <ul className="list-disc list-inside space-y-0.5 text-[11px]">
                <li>File controller & routes modul akan dihapus dari sistem.</li>
                <li>Halaman React Inertia akan dihapus dari disk.</li>
                {deleteConfirmModule.table_name && (
                  <li>Tabel database <code className="font-mono bg-rose-500/20 px-1 rounded">{deleteConfirmModule.table_name}</code> akan di-DROP beserta seluruh datanya.</li>
                )}
              </ul>
            </div>

            <div className="flex items-center justify-end gap-2 pt-2 border-t border-border-subtle">
              <button
                type="button"
                onClick={() => setDeleteConfirmModule(null)}
                className="px-4 py-2 rounded-lg text-xs font-medium text-text-muted hover:bg-surface-subtle transition-colors"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={handleDeleteModule}
                className="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all"
              >
                Ya, Hapus Bersih
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
