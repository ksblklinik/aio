<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Auth\ApiTokenService;
use Core\Database\Connection;
use Core\Database\Migrator;
use PHPUnit\Framework\TestCase;

class ApiTokenTest extends TestCase
{
    private Connection $db;
    private ApiTokenService $tokenService;
    private int $tenantId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->tokenService = new ApiTokenService($this->db);

        // Seed tenant & user
        $this->tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Acme Enterprise',
            'slug' => 'acme-ent',
            'plan' => 'enterprise',
        ]);

        $this->userId = (int) $this->db->insert('users', [
            'name' => 'Alice Developer',
            'email' => 'alice@acme.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => 'admin',
        ]);
    }

    public function test_create_token_generates_plain_token_and_hashes_in_db(): void
    {
        $created = $this->tokenService->createToken(
            $this->tenantId,
            $this->userId,
            'CI/CD Pipeline',
            ['projects:read', 'projects:write']
        );

        $this->assertGreaterThan(0, $created['id']);
        $this->assertStringStartsWith('aio_pat_', $created['plain_token']);
        $this->assertSame('CI/CD Pipeline', $created['name']);
        $this->assertSame(['projects:read', 'projects:write'], $created['abilities']);

        // Pastikan token plaintext TIDAK tersimpan di database, melainkan hash SHA-256
        $row = $this->db->selectOne("SELECT * FROM api_tokens WHERE id = ?", [$created['id']]);
        $this->assertNotNull($row);
        $this->assertNotSame($created['plain_token'], $row['token_hash']);
        $this->assertSame(hash('sha256', $created['plain_token']), $row['token_hash']);
    }

    public function test_validate_token_succeeds_and_updates_last_used_at(): void
    {
        $created = $this->tokenService->createToken($this->tenantId, $this->userId, 'Mobile App');

        $validated = $this->tokenService->validateToken($created['plain_token']);
        $this->assertNotNull($validated);
        $this->assertSame('Mobile App', $validated['name']);
        $this->assertSame('Alice Developer', $validated['user_name']);
        $this->assertSame('Acme Enterprise', $validated['tenant_name']);
        $this->assertSame($this->tenantId, (int) $validated['tenant_id']);
        $this->assertNotNull($validated['last_used_at']);

        // Test with "Bearer " prefix
        $validatedWithPrefix = $this->tokenService->validateToken('Bearer ' . $created['plain_token']);
        $this->assertNotNull($validatedWithPrefix);
    }

    public function test_validate_token_returns_null_for_invalid_token(): void
    {
        $this->assertNull($this->tokenService->validateToken('invalid_token_string'));
        $this->assertNull($this->tokenService->validateToken('aio_pat_doesnotexist1234567890'));
        $this->assertNull($this->tokenService->validateToken(''));
    }

    public function test_validate_token_rejects_expired_token(): void
    {
        $pastDate = date('Y-m-d H:i:s', time() - 3600); // 1 jam lalu
        $created = $this->tokenService->createToken(
            $this->tenantId,
            $this->userId,
            'Temporary Token',
            ['*'],
            $pastDate
        );

        $validated = $this->tokenService->validateToken($created['plain_token']);
        $this->assertNull($validated);
    }

    public function test_has_ability_handles_wildcard_and_specific_permissions(): void
    {
        $wildcardToken = ['abilities_list' => ['*']];
        $this->assertTrue($this->tokenService->hasAbility($wildcardToken, 'projects:read'));
        $this->assertTrue($this->tokenService->hasAbility($wildcardToken, 'billing:manage'));

        $scopedToken = ['abilities_list' => ['projects:read', 'tasks:toggle']];
        $this->assertTrue($this->tokenService->hasAbility($scopedToken, 'projects:read'));
        $this->assertTrue($this->tokenService->hasAbility($scopedToken, 'tasks:toggle'));
        $this->assertFalse($this->tokenService->hasAbility($scopedToken, 'projects:delete'));
    }

    public function test_revoke_token_removes_token(): void
    {
        $created = $this->tokenService->createToken($this->tenantId, $this->userId, 'Revoke Test');
        $this->assertNotNull($this->tokenService->validateToken($created['plain_token']));

        $revoked = $this->tokenService->revokeToken($created['id'], $this->tenantId);
        $this->assertTrue($revoked);

        // Setelah di-revoke, token tidak valid lagi
        $this->assertNull($this->tokenService->validateToken($created['plain_token']));
    }

    public function test_get_tokens_for_tenant_lists_active_tokens(): void
    {
        $this->tokenService->createToken($this->tenantId, $this->userId, 'Token 1');
        $this->tokenService->createToken($this->tenantId, $this->userId, 'Token 2');

        $tokens = $this->tokenService->getTokensForTenant($this->tenantId);
        $this->assertCount(2, $tokens);
        $this->assertSame('Token 2', $tokens[0]['name']);
        $this->assertSame('Token 1', $tokens[1]['name']);
    }
}
