import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import {
  BookOpen,
  Search,
  Plus,
  Layers,
  ArrowRightLeft,
  Upload,
  Trash2,
  Globe,
  Building2,
  Tag,
  X,
  CheckCircle2,
  ExternalLink
} from 'lucide-react';

interface CodeSystem {
  id: number;
  tenant_id: number | null;
  code: string;
  name: string;
  uri: string | null;
  version: string | null;
  publisher: string | null;
  description: string | null;
  is_active: boolean | number;
  is_system: boolean | number;
  created_at: string;
}

interface Concept {
  id: number;
  tenant_id: number | null;
  code_system_id: number;
  system_code?: string;
  system_name?: string;
  code: string;
  display_name: string;
  description: string | null;
  parent_code: string | null;
  synonyms: string[];
  properties: Record<string, any>;
  is_active: boolean | number;
}

interface Mapping {
  id: number;
  tenant_id: number;
  source_system_id: number;
  source_system_code: string;
  source_system_name: string;
  source_code: string;
  target_system_id: number;
  target_system_code: string;
  target_system_name: string;
  target_code: string;
  equivalence: string;
  comments: string | null;
  created_at: string;
}

interface Props {
  codeSystems: CodeSystem[];
  selectedSystemId: number | null;
  concepts: Concept[];
  mappings: Mapping[];
  searchQuery?: string | null;
  flash?: {
    success?: string | null;
    error?: string | null;
  };
}

export default function Index({
  codeSystems = [],
  selectedSystemId = null,
  concepts = [],
  mappings = [],
  searchQuery = '',
  flash = {}
}: Props) {
  const [activeTab, setActiveTab] = useState<'concepts' | 'systems' | 'mappings'>('concepts');
  const selectedSystem = selectedSystemId;
  const [searchFilter, setSearchFilter] = useState(searchQuery || '');

  // Modals state
  const [showSystemModal, setShowSystemModal] = useState(false);
  const [showConceptModal, setShowConceptModal] = useState(false);
  const [showMappingModal, setShowMappingModal] = useState(false);
  const [showBulkModal, setShowBulkModal] = useState(false);

  // Mapping Tester state
  const [testSourceSystem, setTestSourceSystem] = useState('');
  const [testSourceCode, setTestSourceCode] = useState('');
  const [testTargetSystem, setTestTargetSystem] = useState('');
  const [testResult, setTestResult] = useState<any>(null);
  const [isTesting, setIsTesting] = useState(false);

  const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

  const activeSystemObj = codeSystems.find((cs) => cs.id === selectedSystem) || codeSystems[0] || null;

  const handleRunMappingTest = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!testSourceSystem || !testSourceCode || !testTargetSystem) return;

    setIsTesting(true);
    setTestResult(null);

    try {
      const res = await fetch('/api/terminology/resolve-mapping', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          source_system_code: testSourceSystem,
          source_code: testSourceCode,
          target_system_code: testTargetSystem,
        }),
      });

      const data = await res.json();
      setTestResult(data);
    } catch (err: any) {
      setTestResult({ status: 'error', message: err.message || 'Gagal menghubungi server' });
    } finally {
      setIsTesting(false);
    }
  };

  return (
    <AppLayout title="Universal Semantic Coding & Terminology Registry">
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
              <BookOpen className="w-7 h-7" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold text-text-main">Kamus Kodifikasi & Terminologi Semantik</h1>
                <span className="px-2.5 py-0.5 text-xs font-semibold rounded-full bg-primary/10 text-primary border border-primary/20">
                  Level 2 Foundation
                </span>
              </div>
              <p className="text-sm text-text-muted mt-1">
                Katalog kamus standar resmi (ICD-10, ICD-9-CM, LOINC, KFA, HS-Code, KBLI), sinonim pencarian ramah bahasa lokal, dan cross-reference mapping.
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            <button
              onClick={() => setShowSystemModal(true)}
              className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated active:scale-[0.98] text-text-main transition-all duration-150 flex items-center gap-2"
            >
              <Plus className="w-4 h-4 text-text-muted" />
              <span>Tambah Standar</span>
            </button>
            <button
              onClick={() => setShowBulkModal(true)}
              className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated active:scale-[0.98] text-text-main transition-all duration-150 flex items-center gap-2"
            >
              <Upload className="w-4 h-4 text-text-muted" />
              <span>Bulk Import</span>
            </button>
            <button
              onClick={() => setShowConceptModal(true)}
              className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary hover:bg-primary-hover active:scale-[0.98] text-primary-contrast transition-all duration-150 flex items-center gap-2 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              <span>Tambah Konsep</span>
            </button>
          </div>
        </div>

        {/* Navigation Tabs */}
        <div className="flex border-b border-border-subtle gap-8">
          <button
            onClick={() => setActiveTab('concepts')}
            className={`pb-3 text-sm font-semibold flex items-center gap-2 transition-colors relative ${
              activeTab === 'concepts'
                ? 'text-primary border-b-2 border-primary -mb-px'
                : 'text-text-muted hover:text-text-main'
            }`}
          >
            <Search className="w-4 h-4" />
            <span>Kamus Konsep & Pencarian Cepat</span>
            <span className="px-2 py-0.5 rounded-full text-xs bg-surface-elevated border border-border-subtle text-text-muted">
              {concepts.length}
            </span>
          </button>
          <button
            onClick={() => setActiveTab('systems')}
            className={`pb-3 text-sm font-semibold flex items-center gap-2 transition-colors relative ${
              activeTab === 'systems'
                ? 'text-primary border-b-2 border-primary -mb-px'
                : 'text-text-muted hover:text-text-main'
            }`}
          >
            <Layers className="w-4 h-4" />
            <span>Katalog Sistem Kodifikasi</span>
            <span className="px-2 py-0.5 rounded-full text-xs bg-surface-elevated border border-border-subtle text-text-muted">
              {codeSystems.length}
            </span>
          </button>
          <button
            onClick={() => setActiveTab('mappings')}
            className={`pb-3 text-sm font-semibold flex items-center gap-2 transition-colors relative ${
              activeTab === 'mappings'
                ? 'text-primary border-b-2 border-primary -mb-px'
                : 'text-text-muted hover:text-text-main'
            }`}
          >
            <ArrowRightLeft className="w-4 h-4" />
            <span>Pemetaan Lintas Sistem (Cross-Reference)</span>
            <span className="px-2 py-0.5 rounded-full text-xs bg-surface-elevated border border-border-subtle text-text-muted">
              {mappings.length}
            </span>
          </button>
        </div>

        {/* TAB 1: KAMUS KONSEP & PENCARIAN */}
        {activeTab === 'concepts' && (
          <div className="space-y-6">
            {/* Filter Bar */}
            <div className="flex flex-col md:flex-row gap-4 justify-between items-stretch md:items-center bg-surface p-4 rounded-2xl border border-border-subtle shadow-sm">
              <div className="flex flex-wrap items-center gap-3">
                <label className="text-xs font-semibold text-text-muted uppercase tracking-wider">
                  Sistem Kodifikasi:
                </label>
                <div className="flex flex-wrap gap-1.5">
                  {codeSystems.map((cs) => (
                    <a
                      key={cs.id}
                      href={`/terminology?code_system_id=${cs.id}`}
                      className={`px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors border ${
                        activeSystemObj?.id === cs.id
                          ? 'bg-primary text-primary-contrast border-primary'
                          : 'bg-surface-elevated hover:bg-surface border-border-subtle text-text-muted hover:text-text-main'
                      }`}
                    >
                      {cs.code}
                    </a>
                  ))}
                </div>
              </div>

              <form action="/terminology" method="GET" className="flex items-center gap-2">
                <input type="hidden" name="code_system_id" value={activeSystemObj?.id || ''} />
                <div className="relative">
                  <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle" />
                  <input
                    type="text"
                    name="search"
                    value={searchFilter}
                    onChange={(e) => setSearchFilter(e.target.value)}
                    placeholder="Cari kode, nama, sinonim..."
                    className="pl-9 pr-4 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary w-64"
                  />
                </div>
                <button
                  type="submit"
                  className="px-3.5 py-2 text-sm font-medium rounded-xl bg-surface-elevated border border-border-subtle hover:bg-surface text-text-main transition-colors"
                >
                  Cari
                </button>
              </form>
            </div>

            {/* Concepts Table */}
            <div className="bg-surface rounded-2xl border border-border-subtle overflow-hidden shadow-sm">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between bg-surface-elevated/40">
                <div className="flex items-center gap-2">
                  <span className="font-semibold text-text-main">
                    Konsep Standar: {activeSystemObj?.code || 'Pilih Sistem'}
                  </span>
                  <span className="text-xs text-text-muted">
                    ({activeSystemObj?.name})
                  </span>
                </div>
                {activeSystemObj?.uri && (
                  <span className="text-xs text-text-muted flex items-center gap-1 font-mono">
                    <ExternalLink className="w-3.5 h-3.5 text-text-subtle" />
                    {activeSystemObj.uri}
                  </span>
                )}
              </div>

              {concepts.length === 0 ? (
                <div className="p-12 text-center">
                  <BookOpen className="w-12 h-12 text-text-subtle mx-auto mb-3 opacity-60" />
                  <h3 className="text-base font-semibold text-text-main">Belum Ada Konsep</h3>
                  <p className="text-sm text-text-muted mt-1 max-w-md mx-auto">
                    Belum ada entri kode atau konsep pada sistem kodifikasi ini. Anda dapat menambahkan konsep satuan atau mengunggah dataset melalui Bulk Import.
                  </p>
                  <div className="mt-4 flex items-center justify-center gap-3">
                    <button
                      onClick={() => setShowConceptModal(true)}
                      className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                    >
                      Tambah Konsep
                    </button>
                    <button
                      onClick={() => setShowBulkModal(true)}
                      className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                    >
                      Bulk Import
                    </button>
                  </div>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="border-b border-border-subtle bg-surface-elevated/60 text-xs font-semibold text-text-muted uppercase tracking-wider">
                        <th className="py-3 px-4">Kode Konsep</th>
                        <th className="py-3 px-4">Display Name (Preferred Term)</th>
                        <th className="py-3 px-4">Parent Code</th>
                        <th className="py-3 px-4">Sinonim Pencarian (Bahasa Lokal)</th>
                        <th className="py-3 px-4 text-center">Status</th>
                        <th className="py-3 px-4 text-right">Aksi</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-subtle text-sm">
                      {concepts.map((concept) => (
                        <tr key={concept.id} className="hover:bg-surface-elevated/40 transition-colors">
                          <td className="py-3.5 px-4">
                            <span className="font-mono font-bold text-primary bg-primary/10 border border-primary/20 px-2 py-0.5 rounded-md">
                              {concept.code}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 font-medium text-text-main">
                            <div>{concept.display_name}</div>
                            {concept.description && (
                              <div className="text-xs text-text-muted mt-0.5">{concept.description}</div>
                            )}
                          </td>
                          <td className="py-3.5 px-4 text-text-muted font-mono text-xs">
                            {concept.parent_code ? (
                              <span className="bg-surface-elevated px-2 py-0.5 rounded border border-border-subtle">
                                {concept.parent_code}
                              </span>
                            ) : (
                              <span className="text-text-subtle">-</span>
                            )}
                          </td>
                          <td className="py-3.5 px-4">
                            <div className="flex flex-wrap gap-1 max-w-xs">
                              {concept.synonyms && concept.synonyms.length > 0 ? (
                                concept.synonyms.map((syn, idx) => (
                                  <span
                                    key={idx}
                                    className="px-2 py-0.5 text-xs rounded-md bg-surface-elevated border border-border-subtle text-text-muted flex items-center gap-1"
                                  >
                                    <Tag className="w-3 h-3 text-text-subtle" />
                                    {syn}
                                  </span>
                                ))
                              ) : (
                                <span className="text-text-subtle text-xs">-</span>
                              )}
                            </div>
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            <span
                              className={`px-2 py-0.5 text-xs font-medium rounded-full ${
                                concept.is_active
                                  ? 'bg-success-subtle text-success'
                                  : 'bg-surface-elevated text-text-muted'
                              }`}
                            >
                              {concept.is_active ? 'Aktif' : 'Non-Aktif'}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 text-right">
                            <form
                              action={`/terminology/concepts/${concept.id}/delete`}
                              method="POST"
                              onSubmit={(e) => {
                                if (!confirm(`Hapus konsep ${concept.code}?`)) e.preventDefault();
                              }}
                              className="inline-block"
                            >
                              <input type="hidden" name="_token" value={csrfToken} />
                              <input type="hidden" name="code_system_id" value={concept.code_system_id} />
                              <button
                                type="submit"
                                className="p-1.5 rounded-lg text-text-muted hover:text-danger hover:bg-danger-subtle transition-colors"
                                title="Hapus Konsep"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </form>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        )}

        {/* TAB 2: KATALOG SISTEM KODIFIKASI */}
        {activeTab === 'systems' && (
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
              {codeSystems.map((cs) => (
                <div
                  key={cs.id}
                  className="p-5 rounded-2xl bg-surface border border-border-subtle hover:border-border-strong transition-all shadow-sm flex flex-col justify-between"
                >
                  <div>
                    <div className="flex items-center justify-between mb-3">
                      <span className="font-mono font-bold text-base text-primary bg-primary/10 border border-primary/20 px-2.5 py-1 rounded-lg">
                        {cs.code}
                      </span>
                      {cs.is_system ? (
                        <span className="flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                          <Globe className="w-3 h-3" />
                          Master Platform
                        </span>
                      ) : (
                        <span className="flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-elevated text-text-muted border border-border-subtle">
                          <Building2 className="w-3 h-3" />
                          Custom Tenant
                        </span>
                      )}
                    </div>
                    <h3 className="text-base font-semibold text-text-main">{cs.name}</h3>
                    <p className="text-xs text-text-muted mt-1 line-clamp-2">
                      {cs.description || 'Tidak ada deskripsi.'}
                    </p>

                    <div className="mt-4 pt-3 border-t border-border-subtle space-y-1 text-xs text-text-muted">
                      {cs.uri && (
                        <div className="truncate font-mono">
                          <span className="font-medium text-text-subtle">URI:</span> {cs.uri}
                        </div>
                      )}
                      {cs.publisher && (
                        <div>
                          <span className="font-medium text-text-subtle">Penerbit:</span> {cs.publisher}
                        </div>
                      )}
                      {cs.version && (
                        <div>
                          <span className="font-medium text-text-subtle">Versi:</span> {cs.version}
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="mt-5 pt-3 border-t border-border-subtle flex items-center justify-between">
                    <a
                      href={`/terminology?code_system_id=${cs.id}`}
                      className="text-xs font-semibold text-primary hover:underline flex items-center gap-1"
                    >
                      Buka Kamus Konsep &rarr;
                    </a>

                    {!cs.is_system && (
                      <form
                        action={`/terminology/code-systems/${cs.id}/delete`}
                        method="POST"
                        onSubmit={(e) => {
                          if (!confirm(`Hapus sistem kodifikasi ${cs.code}?`)) e.preventDefault();
                        }}
                      >
                        <input type="hidden" name="_token" value={csrfToken} />
                        <button
                          type="submit"
                          className="p-1 rounded text-text-muted hover:text-danger transition-colors"
                          title="Hapus"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </form>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* TAB 3: PEMETAAN LINTAS SISTEM (CROSS-REFERENCE) */}
        {activeTab === 'mappings' && (
          <div className="space-y-6">
            {/* Interactive Quick Mapping Tester */}
            <div className="p-5 rounded-2xl bg-surface-elevated/40 border border-border-subtle">
              <div className="flex items-center gap-2 mb-3">
                <ArrowRightLeft className="w-5 h-5 text-primary" />
                <h3 className="text-sm font-semibold text-text-main">
                  Tester Resolusi Pemetaan Cepat (Cross-Reference Engine)
                </h3>
              </div>
              <form onSubmit={handleRunMappingTest} className="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">
                    Sistem Sumber (Source Code System)
                  </label>
                  <select
                    value={testSourceSystem}
                    onChange={(e) => setTestSourceSystem(e.target.value)}
                    required
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main"
                  >
                    <option value="">-- Pilih Sistem --</option>
                    {codeSystems.map((cs) => (
                      <option key={cs.id} value={cs.code}>
                        {cs.code} ({cs.name})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">
                    Kode Sumber (Source Code)
                  </label>
                  <input
                    type="text"
                    value={testSourceCode}
                    onChange={(e) => setTestSourceCode(e.target.value)}
                    placeholder="Contoh: KONS-01"
                    required
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">
                    Sistem Target (Target Code System)
                  </label>
                  <select
                    value={testTargetSystem}
                    onChange={(e) => setTestTargetSystem(e.target.value)}
                    required
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main"
                  >
                    <option value="">-- Pilih Sistem --</option>
                    {codeSystems.map((cs) => (
                      <option key={cs.id} value={cs.code}>
                        {cs.code} ({cs.name})
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex items-end">
                  <button
                    type="submit"
                    disabled={isTesting}
                    className="w-full py-2 px-4 rounded-xl text-sm font-semibold bg-primary hover:bg-primary-hover text-primary-contrast transition-colors disabled:opacity-50"
                  >
                    {isTesting ? 'Mencari...' : 'Tes Konversi'}
                  </button>
                </div>
              </form>

              {testResult && (
                <div className="mt-4 p-4 rounded-xl bg-surface border border-border-subtle">
                  {testResult.status === 'success' ? (
                    <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <CheckCircle2 className="w-4 h-4 text-success" />
                          <span className="font-semibold text-text-main">Pemetaan Berhasil Ditemukan:</span>
                          <span className="font-mono text-primary font-bold">{testResult.data.source_code}</span>
                          <span className="text-text-muted">&rarr;</span>
                          <span className="font-mono text-success font-bold">{testResult.data.target_code}</span>
                          <span className="text-xs px-2 py-0.5 rounded bg-surface-elevated border border-border-subtle text-text-muted uppercase">
                            {testResult.data.equivalence}
                          </span>
                        </div>
                        {testResult.data.target_concept && (
                          <div className="text-xs text-text-muted pl-6">
                            Target Term: <strong className="text-text-main">{testResult.data.target_concept.display_name}</strong>
                          </div>
                        )}
                      </div>
                    </div>
                  ) : (
                    <div className="text-sm text-danger flex items-center gap-2">
                      <X className="w-4 h-4" />
                      <span>{testResult.message || 'Pemetaan tidak ditemukan.'}</span>
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* Mappings Table */}
            <div className="bg-surface rounded-2xl border border-border-subtle overflow-hidden shadow-sm">
              <div className="p-4 border-b border-border-subtle flex items-center justify-between bg-surface-elevated/40">
                <span className="font-semibold text-text-main">
                  Daftar Aturan Konversi Kode Lintas Standar
                </span>
                <button
                  onClick={() => setShowMappingModal(true)}
                  className="px-3.5 py-1.5 text-xs font-semibold rounded-xl bg-primary text-primary-contrast flex items-center gap-1.5"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Tambah Pemetaan</span>
                </button>
              </div>

              {mappings.length === 0 ? (
                <div className="p-12 text-center">
                  <ArrowRightLeft className="w-12 h-12 text-text-subtle mx-auto mb-3 opacity-60" />
                  <h3 className="text-base font-semibold text-text-main">Belum Ada Pemetaan Kode</h3>
                  <p className="text-sm text-text-muted mt-1 max-w-md mx-auto">
                    Buat aturan konversi untuk memetakan kode tindakan atau diagnosis internal ke format BPJS, SatuSehat, atau HS-Code.
                  </p>
                  <button
                    onClick={() => setShowMappingModal(true)}
                    className="mt-4 px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Tambah Pemetaan Baru
                  </button>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="border-b border-border-subtle bg-surface-elevated/60 text-xs font-semibold text-text-muted uppercase tracking-wider">
                        <th className="py-3 px-4">Sistem Sumber</th>
                        <th className="py-3 px-4">Kode Sumber</th>
                        <th className="py-3 px-4 text-center">Relasi</th>
                        <th className="py-3 px-4">Sistem Target</th>
                        <th className="py-3 px-4">Kode Target</th>
                        <th className="py-3 px-4">Keterangan</th>
                        <th className="py-3 px-4 text-right">Aksi</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-subtle text-sm">
                      {mappings.map((m) => (
                        <tr key={m.id} className="hover:bg-surface-elevated/40 transition-colors">
                          <td className="py-3.5 px-4 font-medium text-text-main">
                            <span className="bg-surface-elevated px-2 py-0.5 rounded border border-border-subtle text-xs">
                              {m.source_system_code}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 font-mono font-bold text-primary">
                            {m.source_code}
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            <span className="px-2 py-0.5 text-xs rounded bg-primary/10 text-primary border border-primary/20 uppercase font-mono">
                              {m.equivalence}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 font-medium text-text-main">
                            <span className="bg-surface-elevated px-2 py-0.5 rounded border border-border-subtle text-xs">
                              {m.target_system_code}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 font-mono font-bold text-success">
                            {m.target_code}
                          </td>
                          <td className="py-3.5 px-4 text-text-muted text-xs">
                            {m.comments || '-'}
                          </td>
                          <td className="py-3.5 px-4 text-right">
                            <form
                              action={`/terminology/mappings/${m.id}/delete`}
                              method="POST"
                              onSubmit={(e) => {
                                if (!confirm('Hapus pemetaan ini?')) e.preventDefault();
                              }}
                              className="inline-block"
                            >
                              <input type="hidden" name="_token" value={csrfToken} />
                              <button
                                type="submit"
                                className="p-1.5 rounded-lg text-text-muted hover:text-danger hover:bg-danger-subtle transition-colors"
                                title="Hapus Pemetaan"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </form>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        )}

        {/* MODAL: TAMBAH SISTEM KODIFIKASI */}
        {showSystemModal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-lg shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main">Tambah Sistem Kodifikasi Baru</h3>
                <button
                  onClick={() => setShowSystemModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action="/terminology/code-systems" method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kode Standar *</label>
                    <input
                      type="text"
                      name="code"
                      required
                      placeholder="Contoh: KFA, BPJS-TND"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main font-mono uppercase"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Versi</label>
                    <input
                      type="text"
                      name="version"
                      placeholder="Contoh: 2026.1"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Nama Sistem *</label>
                  <input
                    type="text"
                    name="name"
                    required
                    placeholder="Contoh: Kamus Farmasi & Alkes Kemenkes"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">URI Identifikasi Resmi</label>
                  <input
                    type="text"
                    name="uri"
                    placeholder="Contoh: http://sys-ids.kemkes.go.id/kfa"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main font-mono text-xs"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Penerbit (Publisher)</label>
                  <input
                    type="text"
                    name="publisher"
                    placeholder="Contoh: Kementerian Kesehatan RI"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Deskripsi</label>
                  <textarea
                    name="description"
                    rows={2}
                    placeholder="Penjelasan ringkas peruntukan sistem ini..."
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowSystemModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Simpan Sistem
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: TAMBAH KONSEP SATUAN */}
        {showConceptModal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-lg shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main">Tambah Konsep Terminologi Baru</h3>
                <button
                  onClick={() => setShowConceptModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action="/terminology/concepts" method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Sistem Kodifikasi *</label>
                  <select
                    name="code_system_id"
                    defaultValue={activeSystemObj?.id || ''}
                    required
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  >
                    {codeSystems.map((cs) => (
                      <option key={cs.id} value={cs.id}>
                        {cs.code} - {cs.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Kode Konsep *</label>
                    <input
                      type="text"
                      name="code"
                      required
                      placeholder="Contoh: A91, J45.0"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main font-mono"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Parent Code (Hierarki)</label>
                    <input
                      type="text"
                      name="parent_code"
                      placeholder="Contoh: A90-A99"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main font-mono"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Display Name (Preferred Term) *</label>
                  <input
                    type="text"
                    name="display_name"
                    required
                    placeholder="Contoh: Dengue haemorrhagic fever"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">
                    Sinonim Pencarian Ramah Bahasa Lokal (Pisahkan dengan koma)
                  </label>
                  <input
                    type="text"
                    name="synonyms"
                    placeholder="Contoh: Demam Berdarah Dengue, DBD, DHF"
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                  <span className="text-xs text-text-muted mt-1 block">
                    Kata kunci ini memungkinkan dokter/user mencari kode dengan istilah lokal sehari-hari.
                  </span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Deskripsi Tambahan</label>
                  <textarea
                    name="description"
                    rows={2}
                    placeholder="Catatan klinis atau spesifikasi konsep..."
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowConceptModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Simpan Konsep
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: TAMBAH PEMETAAN (CROSS-REFERENCE) */}
        {showMappingModal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-lg shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <h3 className="text-base font-bold text-text-main">Tambah Pemetaan Lintas Sistem</h3>
                <button
                  onClick={() => setShowMappingModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action="/terminology/mappings" method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />

                <div className="p-3 rounded-xl bg-surface-elevated/60 border border-border-subtle space-y-3">
                  <span className="text-xs font-bold text-text-main uppercase tracking-wider block">Sumber (Source)</span>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold text-text-muted mb-1">Sistem Sumber *</label>
                      <select
                        name="source_system_id"
                        required
                        className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main"
                      >
                        {codeSystems.map((cs) => (
                          <option key={cs.id} value={cs.id}>
                            {cs.code}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-text-muted mb-1">Kode Sumber *</label>
                      <input
                        type="text"
                        name="source_code"
                        required
                        placeholder="Contoh: KONS-01"
                        className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main font-mono"
                      />
                    </div>
                  </div>
                </div>

                <div className="p-3 rounded-xl bg-surface-elevated/60 border border-border-subtle space-y-3">
                  <span className="text-xs font-bold text-text-main uppercase tracking-wider block">Target (Destination)</span>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold text-text-muted mb-1">Sistem Target *</label>
                      <select
                        name="target_system_id"
                        required
                        className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main"
                      >
                        {codeSystems.map((cs) => (
                          <option key={cs.id} value={cs.id}>
                            {cs.code}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-text-muted mb-1">Kode Target *</label>
                      <input
                        type="text"
                        name="target_code"
                        required
                        placeholder="Contoh: 0101"
                        className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface text-text-main font-mono"
                      />
                    </div>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Tingkat Kesetaraan *</label>
                    <select
                      name="equivalence"
                      defaultValue="equivalent"
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    >
                      <option value="equivalent">Equivalent (Sama Persis)</option>
                      <option value="narrower">Narrower (Lebih Spesifik)</option>
                      <option value="wider">Wider (Lebih Luas)</option>
                      <option value="related_to">Related To (Terkait)</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-text-muted mb-1">Keterangan</label>
                    <input
                      type="text"
                      name="comments"
                      placeholder="Catatan konversi..."
                      className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                    />
                  </div>
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowMappingModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Simpan Pemetaan
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* MODAL: BULK IMPORTER */}
        {showBulkModal && (
          <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-surface border border-border-subtle rounded-2xl w-full max-w-xl shadow-xl overflow-hidden">
              <div className="p-5 border-b border-border-subtle flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Upload className="w-5 h-5 text-primary" />
                  <h3 className="text-base font-bold text-text-main">Bulk Import Konsep Terminologi</h3>
                </div>
                <button
                  onClick={() => setShowBulkModal(false)}
                  className="p-1 rounded-lg text-text-muted hover:text-text-main"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
              <form action="/terminology/concepts/bulk-import" method="POST" className="p-5 space-y-4">
                <input type="hidden" name="_token" value={csrfToken} />

                <div>
                  <label className="block text-xs font-semibold text-text-muted mb-1">Target Sistem Kodifikasi *</label>
                  <select
                    name="code_system_id"
                    defaultValue={activeSystemObj?.id || ''}
                    required
                    className="w-full px-3 py-2 text-sm rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  >
                    {codeSystems.map((cs) => (
                      <option key={cs.id} value={cs.id}>
                        {cs.code} - {cs.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="text-xs font-semibold text-text-muted">Data JSON Array Konsep *</label>
                    <span className="text-xs text-text-subtle font-mono">Format: Array of Objects</span>
                  </div>
                  <textarea
                    name="json_data"
                    required
                    rows={8}
                    placeholder={`[
  {
    "code": "A91",
    "display_name": "Dengue haemorrhagic fever",
    "parent_code": "A90-A99",
    "synonyms": ["Demam Berdarah Dengue", "DBD"]
  }
]`}
                    className="w-full p-3 text-xs font-mono rounded-xl border border-border-subtle bg-surface-elevated text-text-main"
                  />
                  <p className="text-xs text-text-muted mt-1">
                    Pastikan JSON valid. Setiap objek dapat memuat: <code>code</code>, <code>display_name</code>, <code>parent_code</code>, <code>synonyms</code>, dan <code>description</code>.
                  </p>
                </div>

                <div className="pt-3 border-t border-border-subtle flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShowBulkModal(false)}
                    className="px-4 py-2 text-sm font-medium rounded-xl border border-border-subtle hover:bg-surface-elevated"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 text-sm font-semibold rounded-xl bg-primary text-primary-contrast"
                  >
                    Mulai Impor Data
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
