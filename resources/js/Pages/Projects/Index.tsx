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
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-800">
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-xl font-bold tracking-tight text-white">
                Proyek & Task Board
              </h2>
              <span className="text-xs px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-mono border border-indigo-500/30">
                Workspace: {currentTenant?.name}
              </span>
            </div>
            <p className="text-xs text-slate-400 mt-1">
              Data terisolasi secara aman menggunakan Tenant Scoping SQLite. Anda bertindak sebagai <strong className="text-emerald-400 uppercase font-mono">{currentRole}</strong>.
            </p>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-xl shadow-lg shadow-indigo-600/30 transition-all active:scale-95 shrink-0"
          >
            <FolderPlus className="w-4 h-4" />
            <span>Tambah Proyek Baru</span>
          </button>
        </div>

        {/* Empty State */}
        {projects.length === 0 ? (
          <div className="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800/80 space-y-4">
            <div className="w-12 h-12 rounded-2xl bg-slate-800 flex items-center justify-center mx-auto text-slate-400">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h3 className="text-base font-bold text-slate-200">Belum ada proyek di workspace ini</h3>
              <p className="text-xs text-slate-400 max-w-sm mx-auto mt-1">
                Mulai kelola inisiatif bisnis tim Anda dengan membuat proyek pertama.
              </p>
            </div>
            <button
              onClick={() => setShowCreateModal(true)}
              className="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium rounded-xl inline-flex items-center gap-2 shadow-md shadow-indigo-600/30"
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
                  className="rounded-2xl bg-slate-900/60 backdrop-blur-xl border border-slate-800/80 p-5 flex flex-col justify-between hover:border-slate-700/80 transition-all shadow-xl group"
                >
                  <div className="space-y-4">
                    {/* Project Topbar */}
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <span className={`text-[10px] uppercase font-mono px-2 py-0.5 rounded-md font-semibold border ${
                          project.status === 'completed'
                            ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                            : 'bg-amber-500/20 text-amber-300 border-amber-500/30'
                        }`}>
                          {project.status === 'completed' ? 'Selesai' : 'Aktif'}
                        </span>
                        <h3 className="text-base font-bold text-slate-100 group-hover:text-indigo-300 transition-colors mt-2">
                          {project.name}
                        </h3>
                      </div>

                      {/* Delete Project Button (RBAC: Only Owner/Admin) */}
                      {isOwnerOrAdmin ? (
                        <button
                          onClick={() => handleDeleteProject(project.id, project.name)}
                          title="Hapus Proyek (Khusus Owner/Admin)"
                          className="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      ) : (
                        <span title="Hanya Owner/Admin yang dapat menghapus" className="p-1.5 text-slate-600 cursor-not-allowed">
                          <Trash2 className="w-4 h-4 opacity-30" />
                        </span>
                      )}
                    </div>

                    {project.description && (
                      <p className="text-xs text-slate-400 line-clamp-2">
                        {project.description}
                      </p>
                    )}

                    {/* Progress Bar */}
                    <div className="space-y-1.5 pt-1">
                      <div className="flex items-center justify-between text-[11px] text-slate-400">
                        <span>Progress Tugas</span>
                        <span className="font-mono text-slate-300 font-semibold">{completedCount}/{totalCount} ({progressPct}%)</span>
                      </div>
                      <div className="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div
                          className="h-full bg-gradient-to-r from-indigo-500 to-emerald-400 rounded-full transition-all duration-300"
                          style={{ width: `${progressPct}%` }}
                        />
                      </div>
                    </div>

                    {/* Tasks List */}
                    <div className="space-y-1.5 pt-2 border-t border-slate-800/80">
                      <div className="text-[11px] uppercase font-mono tracking-wider text-slate-500 font-semibold mb-1">
                        Daftar Tugas:
                      </div>

                      <div className="space-y-1 max-h-48 overflow-y-auto pr-1">
                        {project.tasks.length === 0 ? (
                          <div className="text-[11px] text-slate-500 italic py-1">Belum ada tugas.</div>
                        ) : (
                          project.tasks.map((task) => (
                            <div
                              key={task.id}
                              className="group/task flex items-center justify-between p-1.5 rounded-lg hover:bg-slate-800/60 transition-colors text-xs"
                            >
                              <button
                                onClick={() => handleToggleTask(task.id)}
                                className="flex items-center gap-2 text-left truncate flex-1 mr-2"
                              >
                                {task.is_completed === 1 ? (
                                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                                ) : (
                                  <Circle className="w-3.5 h-3.5 text-slate-500 shrink-0 hover:text-indigo-400" />
                                )}
                                <span className={`truncate ${task.is_completed === 1 ? 'line-through text-slate-500' : 'text-slate-300'}`}>
                                  {task.title}
                                </span>
                              </button>

                              <button
                                onClick={() => handleDeleteTask(task.id)}
                                className="opacity-0 group-hover/task:opacity-100 p-1 text-slate-500 hover:text-rose-400 transition-opacity"
                              >
                                <X className="w-3 h-3" />
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
                    className="mt-4 pt-3 border-t border-slate-800 flex items-center gap-2"
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
                      className="flex-1 bg-slate-950/80 border border-slate-800 rounded-lg px-2.5 py-1 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500"
                    />
                    <button
                      type="submit"
                      className="p-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-400 transition-colors"
                    >
                      <Plus className="w-3.5 h-3.5" />
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
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-slate-900 border border-slate-700 rounded-2xl p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-slate-800">
              <h3 className="text-base font-bold text-white flex items-center gap-2">
                <FolderPlus className="w-4 h-4 text-indigo-400" />
                <span>Buat Proyek Baru</span>
              </h3>
              <button
                onClick={() => setShowCreateModal(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateProject} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Nama Proyek
                </label>
                <input
                  type="text"
                  required
                  placeholder="Misal: Redesign Landing Page"
                  value={data.name}
                  onChange={(e) => setData('name', e.target.value)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Deskripsi Singkat
                </label>
                <textarea
                  rows={3}
                  placeholder="Deskripsi target dan milestone..."
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Status
                </label>
                <select
                  value={data.status}
                  onChange={(e) => setData('status', e.target.value)}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 focus:outline-none focus:border-indigo-500"
                >
                  <option value="in_progress">Dalam Pengerjaan (Active)</option>
                  <option value="planned">Direncanakan (Planned)</option>
                  <option value="completed">Selesai (Completed)</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={processing}
                  className="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold rounded-xl shadow-md shadow-indigo-600/30 transition-all disabled:opacity-50"
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
