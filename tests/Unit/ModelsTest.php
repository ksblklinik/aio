<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use PHPUnit\Framework\TestCase;

class ModelsTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private TenantScope $tenantScope;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/app/Modules/Projects/Database/Migrations', 'projects');

        $this->tenantContext = new TenantContext();
        $this->tenantScope = new TenantScope($this->tenantContext, $this->db);
    }

    public function test_user_creation_and_authentication(): void
    {
        $user = User::create($this->db, [
            'name' => 'Alice Wonder',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);

        $this->assertNotNull($user->id);
        $this->assertSame('Alice Wonder', $user->name);
        $this->assertSame('alice@example.com', $user->email);
        $this->assertTrue($user->verifyPassword('secret123'));
        $this->assertFalse($user->verifyPassword('wrongpassword'));

        $found = User::findByEmail($this->db, 'alice@example.com');
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function test_tenant_and_membership_role_management(): void
    {
        $user = User::create($this->db, [
            'name' => 'Bob Builder',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        $tenant = Tenant::create($this->db, [
            'name' => 'Mega Corp',
            'slug' => 'mega-corp',
            'plan' => 'enterprise',
        ]);

        $this->assertNotNull($tenant->id);
        $this->assertSame('Mega Corp', $tenant->name);

        // Add Bob as Owner
        $tenant->addMember($this->db, (int) $user->id, 'owner');

        $this->assertTrue($user->hasRoleInTenant($this->db, (int) $tenant->id, 'owner'));
        $this->assertTrue($user->isOwnerInTenant($this->db, (int) $tenant->id));
        $this->assertSame('owner', $user->getRoleInTenant($this->db, (int) $tenant->id));

        $tenants = $user->getTenants($this->db);
        $this->assertCount(1, $tenants);
        $this->assertSame('Mega Corp', $tenants[0]['name']);
        $this->assertSame('owner', $tenants[0]['role']);

        $members = $tenant->getMembers($this->db);
        $this->assertCount(1, $members);
        $this->assertSame('Bob Builder', $members[0]['name']);
        $this->assertSame('owner', $members[0]['role']);
    }

    public function test_project_and_task_crud_with_tenant_scoping(): void
    {
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Alice', 'alice@test.com', 'hash')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Acme Corp', 'acme')");

        $this->tenantContext->setTenantId('1');

        $project = Project::createForTenant($this->tenantScope, [
            'user_id' => 1,
            'name' => 'Project Alpha',
            'description' => 'Test project description',
            'status' => 'in_progress',
        ]);

        $this->assertNotNull($project->id);
        $this->assertSame('1', (string) $project->tenantId);
        $this->assertSame('Project Alpha', $project->name);

        $projects = Project::allForTenant($this->tenantScope);
        $this->assertCount(1, $projects);
        $this->assertSame('Project Alpha', $projects[0]->name);

        // Add task
        $task = Task::create($this->db, [
            'tenant_id' => 1,
            'project_id' => (int) $project->id,
            'title' => 'Write unit tests',
            'is_completed' => 0,
        ]);

        $this->assertNotNull($task->id);
        $this->assertSame('Write unit tests', $task->title);
        $this->assertSame(0, $task->isCompleted);

        // Toggle task
        $toggled = Task::toggle($this->db, (int) $task->id, 1);
        $this->assertTrue($toggled);

        $tasks = Task::forProject($this->db, (int) $project->id, 1);
        $this->assertCount(1, $tasks);
        $this->assertSame(1, $tasks[0]->isCompleted);
    }
}
