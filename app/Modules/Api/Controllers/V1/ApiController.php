<?php

declare(strict_types=1);

namespace App\Modules\Api\Controllers\V1;

use App\Modules\Projects\Models\Project;
use Core\Audit\AuditLogger;
use Core\Auth\ApiTokenService;
use Core\Database\Connection;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Response;

class ApiController
{
    public function __construct(
        private Connection $db,
        private TenantScope $tenantScope,
        private AuditLogger $auditLogger,
        private ApiTokenService $tokenService
    ) {
    }

    /**
     * GET /api/v1/me
     * Menampilkan informasi pengguna, workspace tenant, dan hak akses token aktif.
     */
    public function me(Request $request): Response
    {
        $token = $request->getAttribute('api_token');

        return Response::json([
            'status' => 'success',
            'user' => [
                'id' => (int) $token['user_id'],
                'name' => $token['user_name'],
                'email' => $token['user_email'],
                'role' => $token['user_role'] ?? 'member',
                'is_superadmin' => (bool) $token['is_superadmin'],
            ],
            'tenant' => [
                'id' => (int) $token['tenant_id'],
                'name' => $token['tenant_name'],
                'slug' => $token['tenant_slug'],
                'plan' => $token['tenant_plan'],
            ],
            'token' => [
                'id' => (int) $token['id'],
                'name' => $token['name'],
                'abilities' => $token['abilities_list'],
                'last_used_at' => $token['last_used_at'],
                'expires_at' => $token['expires_at'],
            ],
        ]);
    }

    /**
     * GET /api/v1/projects
     * Menampilkan daftar proyek yang terisolasi sesuai tenant API token.
     */
    public function projects(Request $request): Response
    {
        $token = $request->getAttribute('api_token');

        if (!$this->tokenService->hasAbility($token, 'projects:read')) {
            return Response::json([
                'error' => 'Forbidden',
                'message' => 'Token ini tidak memiliki hak akses (ability) [projects:read].',
            ], 403);
        }

        $projects = Project::allForTenant($this->tenantScope);

        $data = array_map(function (Project $p) {
            $tasks = $p->getTasks($this->db);
            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'status' => $p->status,
                'created_at' => $p->createdAt,
                'total_tasks' => count($tasks),
                'tasks' => array_map(fn($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'is_completed' => (bool) $t->isCompleted,
                ], $tasks),
            ];
        }, $projects);

        return Response::json([
            'status' => 'success',
            'total' => count($data),
            'data' => $data,
        ]);
    }

    /**
     * POST /api/v1/projects
     * Membuat proyek baru secara programmatic via API.
     */
    public function createProject(Request $request): Response
    {
        $token = $request->getAttribute('api_token');

        if (!$this->tokenService->hasAbility($token, 'projects:write')) {
            return Response::json([
                'error' => 'Forbidden',
                'message' => 'Token ini tidak memiliki hak akses (ability) [projects:write].',
            ], 403);
        }

        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $status = (string) $request->input('status', 'in_progress');

        if ($name === '') {
            return Response::json([
                'error' => 'Unprocessable Entity',
                'message' => 'Field [name] proyek wajib disertakan.',
            ], 422);
        }

        $project = Project::createForTenant($this->tenantScope, [
            'user_id' => (int) $token['user_id'],
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
        ]);

        $this->auditLogger->log(
            action: 'project.created',
            description: "Membuat proyek baru via REST API: [{$name}]",
            entityType: 'project',
            entityId: (string) $project->id,
            properties: ['source' => 'rest_api', 'token_name' => $token['name']],
            tenantId: (int) $token['tenant_id'],
            userId: (int) $token['user_id'],
            userName: $token['user_name']
        );

        return Response::json([
            'status' => 'created',
            'message' => "Proyek [{$name}] berhasil dibuat via API.",
            'project' => [
                'id' => (int) $project->id,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'status' => $status,
            ],
        ], 201);
    }
}
