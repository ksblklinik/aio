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
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200">
          <div>
            <div className="flex items-center gap-2.5">
              <h2 className="text-xl font-bold tracking-tight text-gray-900">
                Proyek & Task Board
              </h2>
              <span className="text-xs px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium border border-blue-200">
                Workspace: {currentTenant?.name}
              </span>
            </div>
            <p className="text-xs text-gray-500 mt-1">
              Data terisolasi secara aman menggunakan Tenant Scoping SQLite. Anda bertindak sebagai <strong className="text-blue-700 uppercase font-semibold">{currentRole}</strong>.
            </p>
          </div>

          <button
            onClick={() => setShowCreateModal(true)}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all active:scale-95 shrink-0"
          >
            <FolderPlus className="w-4 h-4" />
            <span>Tambah Proyek Baru</span>
          </button>
        </div>

        {/* Empty State */}
        {projects.length === 0 ? (
          <div className="p-12 text-center rounded-xl bg-white border border-gray-200 shadow-xs space-y-4">
            <div className="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center mx-auto text-blue-600">
              <Layers className="w-6 h-6" />
            </div>
            <div>
              <h3 className="text-base font-bold text-gray-900">Belum ada proyek di workspace ini</h3>
              <p className="text-xs text-gray-500 max-w-sm mx-auto mt-1">
                Mulai kelola inisiatif bisnis tim Anda dengan membuat proyek pertama.
              </p>
            </div>
            <button
              onClick={() => setShowCreateModal(true)}
              className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg inline-flex items-center gap-2 shadow-sm"
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
                  className="rounded-xl bg-white border border-gray-200 p-5 flex flex-col justify-between shadow-xs hover:border-gray-300 transition-all group"
                >
                  <div className="space-y-4">
                    {/* Project Topbar */}
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <span className={`text-[10px] uppercase font-bold px-2 py-0.5 rounded-full border ${
                          project.status === 'completed'
                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                            : 'bg-amber-50 text-amber-700 border-amber-200'
                        }`}>
                          {project.status === 'completed' ? 'Selesai' : 'Aktif'}
                        </span>
                        <h3 className="text-base font-bold text-gray-900 group-hover:text-blue-600 transition-colors mt-2">
                          {project.name}
                        </h3>
                      </div>

                      {/* Delete Project Button (RBAC: Only Owner/Admin) */}
                      {isOwnerOrAdmin ? (
                        <button
                          onClick={() => handleDeleteProject(project.id, project.name)}
                          title="Hapus Proyek (Khusus Owner/Admin)"
                          className="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      ) : (
                        <span title="Hanya Owner/Admin yang dapat menghapus" className="p-1.5 text-gray-300 cursor-not-allowed">
                          <Trash2 className="w-4 h-4 opacity-40" />
                        </span>
                      )}
                    </div>

                    {project.description && (
                      <p className="text-xs text-gray-500 line-clamp-2">
                        {project.description}
                      </p>
                    )}

                    {/* Progress Bar */}
                    <div className="space-y-1.5 pt-1">
                      <div className="flex items-center justify-between text-[11px] text-gray-500">
                        <span>Progress Tugas</span>
                        <span className="font-mono text-gray-700 font-semibold">{completedCount}/{totalCount} ({progressPct}%)</span>
                      </div>
                      <div className="h-1.5 w-full bg-gray-100 rounded-full overflow-hidden">
                        <div
                          className="h-full bg-blue-600 rounded-full transition-all duration-300"
                          style={{ width: `${progressPct}%` }}
                        />
                      </div>
                    </div>

                    {/* Tasks List */}
                    <div className="space-y-1.5 pt-3 border-t border-gray-100">
                      <div className="text-[11px] uppercase tracking-wider text-gray-400 font-bold mb-1">
                        Daftar Tugas:
                      </div>

                      <div className="space-y-1 max-h-48 overflow-y-auto pr-1">
                        {project.tasks.length === 0 ? (
                          <div className="text-[11px] text-gray-400 italic py-1">Belum ada tugas.</div>
                        ) : (
                          project.tasks.map((task) => (
                            <div
                              key={task.id}
                              className="group/task flex items-center justify-between p-1.5 rounded-lg hover:bg-gray-50 transition-colors text-xs"
                            >
                              <button
                                onClick={() => handleToggleTask(task.id)}
                                className="flex items-center gap-2 text-left truncate flex-1 mr-2"
                              >
                                {task.is_completed === 1 ? (
                                  <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                                ) : (
                                  <Circle className="w-4 h-4 text-gray-400 shrink-0 hover:text-blue-600" />
                                )}
                                <span className={`truncate ${task.is_completed === 1 ? 'line-through text-gray-400' : 'text-gray-700 font-medium'}`}>
                                  {task.title}
                                </span>
                              </button>

                              <button
                                onClick={() => handleDeleteTask(task.id)}
                                className="opacity-0 group-hover/task:opacity-100 p-1 text-gray-400 hover:text-red-600 transition-opacity"
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
                    className="mt-4 pt-3 border-t border-gray-100 flex items-center gap-2"
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
                      className="flex-1 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-800 placeholder-gray-400 focus:outline-none focus:bg-white focus:border-blue-500"
                    />
                    <button
                      type="submit"
                      className="p-1.5 rounded-lg bg-gray-100 hover:bg-blue-600 hover:text-white text-gray-600 transition-colors"
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
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-gray-900/50 backdrop-blur-xs animate-in fade-in duration-150">
          <div className="w-full max-w-md bg-white border border-gray-200 rounded-xl p-6 shadow-xl space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-gray-200">
              <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                <FolderPlus className="w-4 h-4 text-blue-600" />
                <span>Buat Proyek Baru</span>
              </h3>
              <button
                onClick={() => setShowCreateModal(false)}
                className="p-1 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateProject} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1">
                  Nama Proyek
                </label>
                <input
                  type="text"
                  required
                  placeholder="Misal: Redesign Landing Page"
                  value={data.name}
                  onChange={(e) => setData('name', e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1">
                  Deskripsi Singkat
                </label>
                <textarea
                  rows={3}
                  placeholder="Deskripsi target dan milestone..."
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 mb-1">
                  Status
                </label>
                <select
                  value={data.status}
                  onChange={(e) => setData('status', e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs text-gray-900 focus:outline-none focus:border-blue-500"
                >
                  <option value="in_progress">Dalam Pengerjaan (Active)</option>
                  <option value="planned">Direncanakan (Planned)</option>
                  <option value="completed">Selesai (Completed)</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-2 pt-3 border-t border-gray-200">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={processing}
                  className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold rounded-lg shadow-sm transition-all disabled:opacity-50"
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
