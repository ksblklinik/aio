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
}
