<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Http\Session;

class AuthService
{
    private ?User $cachedUser = null;
    private ?Tenant $cachedTenant = null;

    public function __construct(
        private Connection $db,
        private Session $session,
        private TenantContext $tenantContext
    ) {
    }

    public function check(): bool
    {
        return $this->session->has('auth_user_id');
    }

    public function user(): ?User
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $userId = $this->session->get('auth_user_id');
        if (!$userId) {
            return null;
        }

        $this->cachedUser = User::findById($this->db, (int) $userId);
        return $this->cachedUser;
    }

    public function currentTenant(): ?Tenant
    {
        if ($this->cachedTenant !== null) {
            return $this->cachedTenant;
        }

        $tenantId = $this->session->get('active_tenant_id');
        if ($tenantId) {
            $this->cachedTenant = Tenant::findById($this->db, (int) $tenantId);
            if ($this->cachedTenant) {
                $this->tenantContext->setTenantId((string) $this->cachedTenant->id);
                return $this->cachedTenant;
            }
        }

        // Fallback to first tenant of the user
        $user = $this->user();
        if ($user) {
            $tenants = $user->getTenants($this->db);
            if (!empty($tenants)) {
                $first = $tenants[0];
                $this->session->set('active_tenant_id', $first['id']);
                $this->tenantContext->setTenantId((string) $first['id']);
                $this->cachedTenant = Tenant::fromArray($first);
                return $this->cachedTenant;
            }
        }

        return null;
    }

    public function currentRole(): ?string
    {
        $user = $this->user();
        $tenant = $this->currentTenant();

        if (!$user || !$tenant || !$tenant->id) {
            return null;
        }

        return $user->getRoleInTenant($this->db, (int) $tenant->id);
    }

    public function isSuperadmin(): bool
    {
        $user = $this->user();
        return $user ? $user->isSuperadmin : false;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = User::findByEmail($this->db, $email);
        if (!$user || !$user->verifyPassword($password)) {
            return false;
        }

        $this->login($user);
        return true;
    }

    public function login(User $user, ?int $tenantId = null): void
    {
        $this->cachedUser = $user;
        $this->session->set('auth_user_id', $user->id);

        $targetTenantId = $tenantId;
        if (!$targetTenantId) {
            $tenants = $user->getTenants($this->db);
            if (!empty($tenants)) {
                $targetTenantId = (int) $tenants[0]['id'];
            }
        }

        if ($targetTenantId) {
            $this->session->set('active_tenant_id', $targetTenantId);
            $this->tenantContext->setTenantId((string) $targetTenantId);
            $this->cachedTenant = Tenant::findById($this->db, $targetTenantId);
        }
    }

    public function loginUsingId(int $userId, ?int $tenantId = null): ?User
    {
        $user = User::findById($this->db, $userId);
        if ($user) {
            $this->login($user, $tenantId);
            return $user;
        }
        return null;
    }

    public function register(string $name, string $email, string $password, string $workspaceName): User
    {
        $user = User::create($this->db, [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $workspaceName), '-'));
        if ($slug === '') {
            $slug = 'workspace-' . ($user->id ?? uniqid());
        }

        // Ensure unique slug
        $baseSlug = $slug;
        $counter = 1;
        while (Tenant::findBySlug($this->db, $slug) !== null) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $tenant = Tenant::create($this->db, [
            'name' => $workspaceName,
            'slug' => $slug,
            'plan' => 'pro',
        ]);

        $tenant->addMember($this->db, (int) $user->id, 'owner');

        $this->login($user, (int) $tenant->id);
        return $user;
    }

    public function switchTenant(int $tenantId): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $role = $user->getRoleInTenant($this->db, $tenantId);
        if ($role === null) {
            return false;
        }

        $this->cachedTenant = null;
        $this->session->set('active_tenant_id', $tenantId);
        $this->tenantContext->setTenantId((string) $tenantId);
        return true;
    }

    public function logout(): void
    {
        $this->cachedUser = null;
        $this->cachedTenant = null;
        $this->session->remove('auth_user_id');
        $this->session->remove('active_tenant_id');
        $this->tenantContext->clear();
    }
}
