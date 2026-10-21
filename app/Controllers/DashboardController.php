<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Projects\Models\Project;
use Core\Database\Connection;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Response;

class DashboardController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private TenantScope $tenantScope,
        private Connection $db
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $user = $this->auth->user();
        $role = $this->auth->currentRole();

        // Ambil data projects yang terisolasi khusus tenant ini
        $projects = Project::allForTenant($this->tenantScope);
        $totalProjects = count($projects);

        $totalTasks = 0;
        $completedTasks = 0;
        $projectList = [];

        foreach ($projects as $p) {
            $tasks = $p->getTasks($this->db);
            $totalTasks += count($tasks);
            foreach ($tasks as $t) {
                if ($t->isCompleted === 1) {
                    $completedTasks++;
                }
            }

            $projectList[] = [
                'id' => (string) $p->id,
                'name' => $p->name,
                'status' => $p->status,
                'recordsCount' => count($tasks),
                'updatedAt' => $p->createdAt ?? 'Baru saja',
            ];
        }

        return $this->render('Dashboard', [
            'title' => 'Dashboard Overview',
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'role' => $role,
            'metrics' => [
                'projects' => (string) $totalProjects,
                'tasks' => "{$completedTasks} / {$totalTasks}",
                'completionRate' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) . '%' : '100%',
                'activeRole' => ucfirst($role ?? 'Member'),
            ],
            'projects' => $projectList,
        ]);
    }
}
