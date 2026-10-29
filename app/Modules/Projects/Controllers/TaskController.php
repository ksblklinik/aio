<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Projects\Models\Task;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;

class TaskController
{
    public function __construct(
        private AuthService $auth,
        private Connection $db,
        private Session $session,
        private ?\Core\Audit\AuditLogger $auditLogger = null
    ) {
    }

    public function store(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !can('projects.manage') && !can('projects.create')) {
            $this->session->flash('error', 'Akses ditolak: Izin [projects.create] diperlukan.');
            return Response::redirect('/projects');
        }

        $projectId = (int) $request->input('project_id', 0);
        $title = trim((string) $request->input('title', ''));

        if ($projectId <= 0 || $title === '') {
            $this->session->flash('error', 'Judul tugas wajib diisi.');
            return Response::redirect('/projects');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $taskId = Task::create($this->db, [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'title' => $title,
            'is_completed' => 0,
        ]);

        $this->auditLogger?->log(
            'task.created',
            "Membuat tugas baru: {$title}",
            'task',
            (string) $taskId,
            ['project_id' => $projectId, 'title' => $title]
        );

        $this->session->flash('success', 'Tugas baru berhasil ditambahkan.');
        return Response::redirect('/projects');
    }

    public function toggle(Request $request, int $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !can('projects.manage') && !can('projects.edit')) {
            $this->session->flash('error', 'Akses ditolak: Izin [projects.edit] diperlukan.');
            return Response::redirect('/projects');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        Task::toggle($this->db, $id, $tenantId);

        $this->auditLogger?->log(
            'task.toggled',
            "Mengubah status penyelesaian tugas #{$id}",
            'task',
            (string) $id
        );

        return Response::redirect('/projects');
    }

    public function destroy(Request $request, int $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !can('projects.manage') && !can('projects.delete')) {
            $this->session->flash('error', 'Akses ditolak: Izin [projects.delete] diperlukan.');
            return Response::redirect('/projects');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        Task::delete($this->db, $id, $tenantId);

        $this->auditLogger?->log(
            'task.deleted',
            "Menghapus tugas #{$id}",
            'task',
            (string) $id
        );

        $this->session->flash('success', 'Tugas berhasil dihapus.');
        return Response::redirect('/projects');
    }
}
