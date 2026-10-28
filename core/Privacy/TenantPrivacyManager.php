<?php

declare(strict_types=1);

namespace Core\Privacy;

class TenantPrivacyManager implements TenantPrivacyManagerInterface
{
    private TenantKeyManager $keyManager;
    private DataPortabilityExporter $exporter;
    private TenantDataShredder $shredder;

    public function __construct(
        TenantKeyManager $keyManager,
        DataPortabilityExporter $exporter,
        TenantDataShredder $shredder
    ) {
        $this->keyManager = $keyManager;
        $this->exporter = $exporter;
        $this->shredder = $shredder;
    }

    public function getKeyManager(): TenantKeyManager
    {
        return $this->keyManager;
    }

    public function getExporter(): DataPortabilityExporter
    {
        return $this->exporter;
    }

    public function getShredder(): TenantDataShredder
    {
        return $this->shredder;
    }

    public function encrypt(int $tenantId, string $plaintext): string
    {
        return $this->keyManager->encrypt($tenantId, $plaintext);
    }

    public function decrypt(int $tenantId, string $ciphertext): ?string
    {
        return $this->keyManager->decrypt($tenantId, $ciphertext);
    }

    public function shredKey(int $tenantId): bool
    {
        return $this->keyManager->shredKey($tenantId);
    }

    public function exportTenant(int $tenantId, array $options = []): array
    {
        return $this->exporter->exportTenant($tenantId, $options);
    }

    public function anonymizeTenant(int $tenantId, ?string $reason = null): array
    {
        return $this->shredder->anonymizeTenant($tenantId, $reason);
    }

    public function shredTenant(int $tenantId, bool $force = false, ?string $reason = null): array
    {
        return $this->shredder->shredTenant($tenantId, $force, $reason);
    }
}
