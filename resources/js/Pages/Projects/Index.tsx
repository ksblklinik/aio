import React, { useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { 
  FolderPlus, 
  Layers, 
  CheckCircle2, 
  Circle, 
  Trash2, 
  Plus, 
  X
} from 'lucide-react';

interface TaskItem {
  id: number;
  title: string;
  is_completed: number;
}

interface ProjectItem {
  id: number;
  name: string;
  description: string | null;
  status: string;
  created_at: string | null;
  tasks: TaskItem[];
}

interface ProjectsProps {
  projects: ProjectItem[];
  currentTenant?: {
    id: number;
    name: string;
    slug: string;
    plan: string;
  } | null;
  currentRole?: string | null;
}

export default function ProjectsIndex({ projects = [], currentTenant, currentRole = 'member' }: ProjectsProps) {
  const { props } = usePage<{ csrf_token?: string }>();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [activeTaskInputs, setActiveTaskInputs] = useState<{ [projectId: number]: string }>({});

  const isOwnerOrAdmin = currentRole === 'owner' || currentRole === 'admin';

  // Project Form
  const { data, setData, post, reset, processing } = useForm({
    name: '',
    description: '',
    status: 'in_progress',
    _token: props.csrf_token || '',
  });

  const handleCreateProject = (e: React.FormEvent) => {
    e.preventDefault();
    post('/projects', {
      onSuccess: () => {
        reset();
        setShowCreateModal(false);
      },
    });
  };

  const handleDeleteProject = (projectId: number, projectName: string) => {
    if (!confirm(`Hapus proyek "${projectName}"? Seluruh tugas di dalamnya akan terhapus.`)) {
      return;
    }
    router.post(`/projects/${projectId}/delete`);
  };

  const handleToggleTask = (taskId: number) => {
    router.post(`/tasks/${taskId}/toggle`);
  };

  const handleDeleteTask = (taskId: number) => {
    router.post(`/tasks/${taskId}/delete`);
  };

  const handleAddTask = (projectId: number, e: React.FormEvent) => {
    e.preventDefault();
    const title = activeTaskInputs[projectId]?.trim();
    if (!title) return;

    router.post('/tasks', {
      project_id: projectId,
      title: title,
    }, {
      onSuccess: () => {
        setActiveTaskInputs((prev) => ({ ...prev, [projectId]: '' }));
      },
    });
  };

  return (
    <AppLayout title="Proyek & Tugas Multi-Tenant">
      <div className="space-y-6 max-w-7xl mx-auto">
        {/* Header Actions */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-border-subtle">
          <div>
            <div className="flex items-center gap-2.5">
              <h2 className="text-xl font-bold tracking-tight text-text-main">
                Proyek & Task Board
              </h2>
              <span className="text-xs px-2.5 py-0.5 rounded-full bg-brand-primary-light text-brand-primary font-medium border border-brand-primary/20">
                Workspace: {currentTenant?.name}
              </span>
            </div>
            <p className="text-xs text-text-muted mt-1">
              Data terisolasi secara aman menggunakan Tenant Scoping SQLite. Anda bertindak sebagai <strong className="text-brand-primary uppercase font-semibold">{currentRole}</strong>.
            </p>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-lg shadow-sm transition-all active:scale-95 shrink-0"
          >
            <FolderPlus className="w-4 h-4" />
            <span>Tambah Proyek Baru</span>
          </button>
        </div>

        {/* Empty State */}
        {projects.length === 0 ? (
          <div className="p-12 text-center rounded-xl bg-surface border border-border-subtle shadow-xs space-y-4">
            <div className="w-12 h-12 rounded-xl bg-brand-primary-light flex items-center justify-center mx-auto text-brand-primary">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h3 className="text-base font-bold text-text-main">Belum ada proyek di workspace ini</h3>
              <p className="text-xs text-text-muted max-w-sm mx-auto mt-1">
                Mulai kelola inisiatif bisnis tim Anda dengan membuat proyek pertama.
              </p>
            </div>
            <button
              onClick={() => setShowCreateModal(true)}
              className="px-4 py-2 bg-brand-primary hover:bg-brand-primary-hover text-white text-xs font-semibold rounded-lg inline-flex items-center gap-2 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              <span>Buat Proyek Sekarang</span>
            </button>
          </div>
        ) : (
          /* Project Grid */
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {projects.map((project) => {
              const completedCount = project.tasks.filter((t) => t.is_completed === 1).length;
              const totalCount = project.tasks.length;
              const progressPct = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;

              return (
                <div
                  key={project.id}
                  className="rounded-xl bg-surface border border-border-subtle p-5 flex flex-col justify-between shadow-xs hover:border-border-main transition-all group"
                >
                  <div className="space-y-4">
                    {/* Project Topbar */}
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <span className={`text-[10px] uppercase font-bold px-2 py-0.5 rounded-full border ${
                          project.status === 'completed'
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'
                        }`}>
                          {project.status === 'completed' ? 'Selesai' : 'Aktif'}
                        </span>
                        <h3 className="text-base font-bold text-text-main group-hover:text-brand-primary transition-colors mt-2">
                          {project.name}
                        </h3>
                      </div>

                      {/* Delete Project Button (RBAC: Only Owner/Admin) */}
                      {isOwnerOrAdmin ? (
                        <button
                          onClick={() => handleDeleteProject(project.id, project.name)}
                          title="Hapus Proyek (Khusus Owner/Admin)"
                          className="p-1.5 rounded-lg text-text-muted hover:text-red-500 hover:bg-red-500/10 transition-colors"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      ) : (
                        <span title="Hanya Owner/Admin yang dapat menghapus" className="p-1.5 text-text-muted opacity-40 cursor-not-allowed">
                          <Trash2 className="w-4 h-4" />
                        </span>
                      )}
                    </div>

                    {project.description && (
                      <p className="text-xs text-text-muted line-clamp-2">
                        {project.description}
                      </p>
                    )}

                    {/* Progress Bar */}
                    <div className="space-y-1.5 pt-1">
                      <div className="flex items-center justify-between text-[11px] text-text-muted">
                        <span>Progress Tugas</span>
                        <span className="font-mono text-text-main font-semibold">{completedCount}/{totalCount} ({progressPct}%)</span>
                      </div>
                      <div className="h-1.5 w-full bg-surface-subtle rounded-full overflow-hidden">
                        <div
                          className="h-full bg-brand-primary rounded-full transition-all duration-300"
                          style={{ width: `${progressPct}%` }}
                        />
                      </div>
                    </div>

                    {/* Tasks List */}
                    <div className="space-y-1.5 pt-3 border-t border-border-subtle">
                      <div className="text-[11px] uppercase tracking-wider text-text-muted font-bold mb-1">
                        Daftar Tugas:
                      </div>

                      <div className="space-y-1 max-h-48 overflow-y-auto pr-1">
                        {project.tasks.length === 0 ? (
                          <div className="text-[11px] text-text-muted italic py-1">Belum ada tugas.</div>
                        ) : (
                          project.tasks.map((task) => (
                            <div
                              key={task.id}
                              className="group/task flex items-center justify-between p-1.5 rounded-lg hover:bg-surface-subtle transition-colors text-xs"
                            >
                              <button
                                onClick={() => handleToggleTask(task.id)}
                                className="flex items-center gap-2 text-left truncate flex-1 mr-2"
                              >
                                {task.is_completed === 1 ? (
                                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500 shrink-0" />
                                ) : (
                                  <Circle className="w-3.5 h-3.5 text-text-muted shrink-0 hover:text-brand-primary" />
                                )}
                                <span className={`truncate ${task.is_completed === 1 ? 'line-through text-text-muted' : 'text-text-main font-medium'}`}>
                                  {task.title}
                                </span>
                              </button>

                              <button
                                onClick={() => handleDeleteTask(task.id)}
                                className="opacity-0 group-hover/task:opacity-100 p-1 text-text-muted hover:text-red-500 transition-opacity"
                              >
                                <X className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          ))
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Add Task Form */}
                  <form
                    onSubmit={(e) => handleAddTask(project.id, e)}
                    className="mt-4 pt-3 border-t border-border-subtle flex items-center gap-2"
                  >
                    <input
                      type="text"
                      placeholder="Tambah tugas baru..."
                      value={activeTaskInputs[project.id] || ''}
                      onChange={(e) =>
                        setActiveTaskInputs((prev) => ({
                          ...prev,
                          [project.id]: e.target.value,
                        }))
                      }
                      className="flex-1 bg-surface-subtle border border-border-subtle rounded-lg px-2.5 py-1.5 text-xs text-text-main placeholder-text-muted focus:outline-none focus:bg-surface focus:border-brand-primary"
                    />
                    <button
                      type="submit"
                      className="p-1.5 rounded-lg bg-surface-subtle hover:bg-brand-primary hover:text-white text-text-muted transition-colors"
                    >
                      <Plus className="w-4 h-4" />
                    </button>
                  </form>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Modal Buat Proyek Baru */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-surface border border-border-subtle rounded-xl p-6 shadow-xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-border-subtle">
              <h3 className="text-base font-bold text-text-main flex items-center gap-2">
                <FolderPlus className="w-4 h-4 text-brand-primary" />
                <span>Buat Proyek Baru</span>
              </h3>
              <button
                onClick={() => setShowCreateModal(false)}
                className="p-1 rounded-lg text-text-muted hover:text-text-main hover:bg-surface-subtle"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateProject} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Nama Proyek
                </label>
                <input
                  type="text"
                  required
                  placeholder="Misal: Redesign Landing Page"
                  value={data.name}
                  onChange={(e) => setData('name', e.target.value)}
                  className="w-full px-3 py-2 bg-surface border border-border-subtle rounded-lg text-xs text-text-main placeholder-text-muted focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Deskripsi Singkat
                </label>
                <textarea
                  rows={3}
                  placeholder="Deskripsi target dan milestone..."
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  className="w-full px-3 py-2 bg-surface border border-border-subtle rounded-lg text-xs text-text-main placeholder-text-muted focus:outline-none focus:border-brand-primary focus:ring-1 focus:ring-brand-primary"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-text-main mb-1">
                  Status
                </label>
                <select
                  value={data.status}
                  onChange={(e) => setData('status', e.target.value)}
                  className="w-full px-3 py-2 bg-surface border border-border-subtle rounded-lg text-xs text-text-main focus:outline-none focus:border-brand-primary"
                >
                  <option value="in_progress">Dalam Pengerjaan (Active)</option>
                  <option value="planned">Direncanakan (Planned)</option>
                  <option value="completed">Selesai (Completed)</option>
                </select>
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
                  {processing ? 'Menyimpan...' : 'Simpan Proyek'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
