<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Projects\Controllers\TaskController;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use PHPUnit\Framework\TestCase;

class ProjectTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private TenantScope $tenantScope;
    private AuthService $auth;
    private Inertia $inertia;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/app/Modules/Projects/Database/Migrations', 'projects');

        $this->session = new Session();
        $this->tenantContext = new TenantContext();
        $this->tenantScope = new TenantScope($this->tenantContext, $this->db);
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);

        $this->inertia = Inertia::getInstance();
    }

    public function test_project_store_and_isolation_between_tenants(): void
    {
        // 1. Create Tenant A with Owner A
        $userA = $this->auth->register('Owner A', 'a@test.com', 'secret', 'Workspace A');
        $tenantA = $this->auth->currentTenant();

        $controller = new ProjectController($this->auth, $this->tenantScope, $this->db, $this->session, $this->inertia);

        // Store project for Tenant A
        $req = new Request('POST', '/projects', [], [], ['name' => 'Project For Workspace A']);
        $resp = $controller->store($req);
        $this->assertSame(302, $resp->getStatusCode());

        // 2. Create Tenant B with Owner B
        $this->auth->logout();
        $userB = $this->auth->register('Owner B', 'b@test.com', 'secret', 'Workspace B');
        $tenantB = $this->auth->currentTenant();

        // Check Tenant B projects: should be 0
        $projectsB = Project::allForTenant($this->tenantScope);
        $this->assertCount(0, $projectsB);

        // Store project for Tenant B
        $reqB = new Request('POST', '/projects', [], [], ['name' => 'Project For Workspace B']);
        $controller->store($reqB);

        $projectsB = Project::allForTenant($this->tenantScope);
        $this->assertCount(1, $projectsB);
        $this->assertSame('Project For Workspace B', $projectsB[0]->name);

        // Switch back to Tenant A
        $this->auth->login($userA, (int) $tenantA->id);
        $projectsA = Project::allForTenant($this->tenantScope);
        $this->assertCount(1, $projectsA);
        $this->assertSame('Project For Workspace A', $projectsA[0]->name);
    }

    public function test_rbac_delete_project_allowed_for_owner_blocked_for_member(): void
    {
        $user = $this->auth->register('Owner', 'owner@test.com', 'secret', 'Acme');
        $tenant = $this->auth->currentTenant();

        $controller = new ProjectController($this->auth, $this->tenantScope, $this->db, $this->session, $this->inertia);

        $req = new Request('POST', '/projects', [], [], ['name' => 'To Be Deleted']);
        $controller->store($req);

        $projects = Project::allForTenant($this->tenantScope);
        $this->assertCount(1, $projects);
        $projectId = (int) $projects[0]->id;

        // Create a regular member in same tenant
        $member = User::create($this->db, ['name' => 'Member', 'email' => 'member@test.com', 'password' => 'secret']);
        $tenant->addMember($this->db, (int) $member->id, 'member');

        // Log in as member and attempt to delete
        $this->auth->login($member, (int) $tenant->id);
        $this->assertSame('member', $this->auth->currentRole());

        $reqDel = new Request('POST', "/projects/{$projectId}/delete");
        $controller->destroy($reqDel, $projectId);

        // Project should STILL exist
        $this->assertCount(1, Project::allForTenant($this->tenantScope));
        $this->assertStringContainsString('Akses ditolak', (string) $this->session->getFlash('error'));

        // Log in as Owner and delete
        $this->auth->login($user, (int) $tenant->id);
        $this->assertSame('owner', $this->auth->currentRole());

        $controller->destroy($reqDel, $projectId);
        $this->assertCount(0, Project::allForTenant($this->tenantScope));
    }

    public function test_rbac_store_project_blocked_for_member_without_permission(): void
    {
        $owner = $this->auth->register('Owner', 'owner2@test.com', 'secret', 'Acme 2');
        $tenant = $this->auth->currentTenant();

        $controller = new ProjectController($this->auth, $this->tenantScope, $this->db, $this->session, $this->inertia);

        // Member
        $member = User::create($this->db, ['name' => 'Regular Member', 'email' => 'reg@test.com', 'password' => 'secret']);
        $tenant->addMember($this->db, (int) $member->id, 'member');

        $this->auth->login($member, (int) $tenant->id);
        $this->assertSame('member', $this->auth->currentRole());

        $req = new Request('POST', '/projects', [], [], ['name' => 'Unauthorized Project']);
        $resp = $controller->store($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertStringContainsString('Akses ditolak', (string) $this->session->getFlash('error'));
        $this->assertCount(0, Project::allForTenant($this->tenantScope));
    }

    public function test_task_crud_and_permission_enforcement(): void
    {
        $owner = $this->auth->register('Owner', 'owner3@test.com', 'secret', 'Acme 3');
        $tenant = $this->auth->currentTenant();

        $projectController = new ProjectController($this->auth, $this->tenantScope, $this->db, $this->session, $this->inertia);
        $taskController = new TaskController($this->auth, $this->db, $this->session);

        // Owner creates project
        $req = new Request('POST', '/projects', [], [], ['name' => 'Task Project']);
        $projectController->store($req);
        $projects = Project::allForTenant($this->tenantScope);
        $this->assertCount(1, $projects);
        $projectId = (int) $projects[0]->id;

        // Regular member attempts to create task
        $member = User::create($this->db, ['name' => 'Member 3', 'email' => 'mem3@test.com', 'password' => 'secret']);
        $tenant->addMember($this->db, (int) $member->id, 'member');

        $this->auth->login($member, (int) $tenant->id);

        $taskReq = new Request('POST', '/tasks', [], [], ['project_id' => $projectId, 'title' => 'Member Task']);
        $taskController->store($taskReq);
        $this->assertStringContainsString('Akses ditolak', (string) $this->session->getFlash('error'));

        // Owner creates task
        $this->auth->login($owner, (int) $tenant->id);
        $taskController->store($taskReq);
        $this->assertStringContainsString('berhasil ditambahkan', (string) $this->session->getFlash('success'));

        $tasks = $projects[0]->getTasks($this->db);
        $this->assertCount(1, $tasks);
        $taskId = (int) $tasks[0]->id;
        $this->assertFalse((bool) $tasks[0]->isCompleted);

        // Toggle task by owner
        $taskController->toggle(new Request('POST', "/tasks/{$taskId}/toggle"), $taskId);
        $tasksAfterToggle = $projects[0]->getTasks($this->db);
        $this->assertTrue((bool) $tasksAfterToggle[0]->isCompleted);

        // Destroy task by owner
        $taskController->destroy(new Request('POST', "/tasks/{$taskId}/delete"), $taskId);
        $tasksAfterDelete = $projects[0]->getTasks($this->db);
        $this->assertCount(0, $tasksAfterDelete);
    }
}
