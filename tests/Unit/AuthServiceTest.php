<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Session;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->session = new Session();
        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
    }

    public function test_register_creates_user_and_workspace_with_owner_role(): void
    {
        $user = $this->auth->register(
            'Charlie Kelly',
            'charlie@paddys.com',
            'ratking123',
            'Paddys Pub'
        );

        $this->assertNotNull($user->id);
        $this->assertSame('Charlie Kelly', $user->name);
        $this->assertTrue($this->auth->check());
        $this->assertSame($user->id, $this->auth->user()?->id);

        $tenant = $this->auth->currentTenant();
        $this->assertNotNull($tenant);
        $this->assertSame('Paddys Pub', $tenant->name);
        $this->assertSame('owner', $this->auth->currentRole());
        $this->assertSame((string) $tenant->id, $this->tenantContext->getTenantId());
    }

    public function test_attempt_login_success_and_failure(): void
    {
        $this->auth->register(
            'Dennis Reynolds',
            'dennis@golden.com',
            'five_star_man',
            'Reynolds Corp'
        );
        $this->auth->logout();

        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->user());

        // Failed login
        $this->assertFalse($this->auth->attempt('dennis@golden.com', 'wrongpassword'));
        $this->assertFalse($this->auth->check());

        // Successful login
        $this->assertTrue($this->auth->attempt('dennis@golden.com', 'five_star_man'));
        $this->assertTrue($this->auth->check());
        $this->assertSame('Dennis Reynolds', $this->auth->user()?->name);
        $this->assertSame('Reynolds Corp', $this->auth->currentTenant()?->name);
    }

    public function test_switch_tenant_changes_active_context(): void
    {
        $user = $this->auth->register(
            'Mac McDonald',
            'mac@karate.com',
            'projectbadass',
            'Dojo One'
        );
        $firstTenantId = (int) $this->auth->currentTenant()?->id;

        // Create second tenant and add Mac as member
        $tenant2 = Tenant::create($this->db, [
            'name' => 'Dojo Two',
            'slug' => 'dojo-two',
        ]);
        $tenant2->addMember($this->db, (int) $user->id, 'member');

        $this->assertTrue($this->auth->switchTenant((int) $tenant2->id));
        $this->assertSame('Dojo Two', $this->auth->currentTenant()?->name);
        $this->assertSame('member', $this->auth->currentRole());
        $this->assertSame((string) $tenant2->id, $this->tenantContext->getTenantId());

        // Switch back
        $this->assertTrue($this->auth->switchTenant($firstTenantId));
        $this->assertSame('Dojo One', $this->auth->currentTenant()?->name);
        $this->assertSame('owner', $this->auth->currentRole());
    }
}
