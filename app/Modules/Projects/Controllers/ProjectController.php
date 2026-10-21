<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Projects\Models\Project;
use Core\Database\Connection;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;

class ProjectController
{
    public function __construct(
        private AuthService $auth,
        private TenantScope $tenantScope,
        private Connection $db,
        private Session $session,
        private Inertia $inertia,
        private ?\Core\Audit\AuditLogger $auditLogger = null,
        private ?\Core\Notification\NotificationService $notificationService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);
        $projects = Project::allForTenant($this->tenantScope);

        $projectsWithTasks = array_map(function (Project $p) use ($tenantId) {
            $tasks = $p->getTasks($this->db);
            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'status' => $p->status,
                'created_at' => $p->createdAt,
                'tasks' => array_map(fn($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'is_completed' => $t->isCompleted,
                ], $tasks),
            ];
        }, $projects);

        return $this->inertia->render('Projects/Index', [
            'projects' => $projectsWithTasks,
            'currentTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
            ] : null,
            'currentRole' => $this->auth->currentRole(),
            'flash' => [
                'success' => $this->session->getFlash('success'),
                'error' => $this->session->getFlash('error'),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $status = (string) $request->input('status', 'in_progress');

        if ($name === '') {
            $this->session->flash('error', 'Nama proyek wajib diisi.');
            return Response::redirect('/projects');
        }

        $user = $this->auth->user();

        $projectId = Project::createForTenant($this->tenantScope, [
            'user_id' => $user?->id ?? 0,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
        ]);

        $this->auditLogger?->log(
            'project.created',
            "Membuat proyek baru [{$name}]",
            'project',
            (string) $projectId,
            ['name' => $name, 'status' => $status]
        );

        $tenantId = (int) ($this->auth->currentTenant()?->id ?? 0);
        if ($tenantId > 0 && $this->notificationService) {
            $this->notificationService->broadcastToTenant(
                $tenantId,
                'Proyek Baru Dibuat',
                "Proyek [{$name}] baru saja dibuat oleh " . ($user?->name ?? 'rekan kerja') . ".",
                'info',
                '/projects',
                'folder'
            );
        }

        $this->session->flash('success', "Proyek [{$name}] berhasil dibuat!");
        return Response::redirect('/projects');
    }

    public function update(Request $request, int $id): Response
    {
        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $data = [];
        if ($request->has('name')) {
            $data['name'] = trim((string) $request->input('name'));
        }
        if ($request->has('description')) {
            $data['description'] = trim((string) $request->input('description'));
        }
        if ($request->has('status')) {
            $data['status'] = (string) $request->input('status');
        }

        Project::update($this->db, $id, $tenantId, $data);

        $this->auditLogger?->log(
            'project.updated',
            "Memperbarui data proyek #{$id}",
            'project',
            (string) $id,
            $data
        );

        $this->session->flash('success', 'Proyek berhasil diperbarui.');
        return Response::redirect('/projects');
    }

    public function destroy(Request $request, int $id): Response
    {
        // RBAC: Hanya Owner atau Admin yang boleh menghapus proyek
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin') {
            $this->session->flash('error', 'Akses ditolak: Hanya Owner atau Admin yang dapat menghapus proyek.');
            return Response::redirect('/projects');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        Project::delete($this->db, $id, $tenantId);

        $this->auditLogger?->log(
            'project.deleted',
            "Menghapus proyek #{$id}",
            'project',
            (string) $id
        );

        $this->session->flash('success', 'Proyek berhasil dihapus.');
        return Response::redirect('/projects');
    }
}
