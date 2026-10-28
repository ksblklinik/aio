<?php

declare(strict_types=1);

namespace Core\Privacy;

interface TenantPrivacyManagerInterface
{
    /**
     * Get the tenant cryptographic key manager.
     */
    public function getKeyManager(): TenantKeyManager;

    /**
     * Get the data portability exporter.
     */
    public function getExporter(): DataPortabilityExporter;

    /**
     * Get the tenant data shredder and anonymizer.
     */
    public function getShredder(): TenantDataShredder;

    /**
     * Encrypt a string using the tenant's isolated cryptographic key.
     */
    public function encrypt(int $tenantId, string $plaintext): string;

    /**
     * Decrypt a ciphertext using the tenant's isolated cryptographic key.
     * Returns null if key is shredded or ciphertext cannot be decrypted.
     */
    public function decrypt(int $tenantId, string $ciphertext): ?string;

    /**
     * Instantly crypto-shred the tenant's cryptographic key.
     * All past data encrypted with this key becomes mathematically unrecoverable.
     */
    public function shredKey(int $tenantId): bool;

    /**
     * Export all tenant data across all modules in portable JSON format.
     */
    public function exportTenant(int $tenantId, array $options = []): array;

    /**
     * Anonymize personally identifiable information (PII) for the tenant.
     * Complies with GDPR Right to Erasure while preserving financial ledger balances.
     */
    public function anonymizeTenant(int $tenantId, ?string $reason = null): array;

    /**
     * Completely shred and purge all physical records of the tenant.
     * Also destroys the tenant cryptographic key and issues a destruction certificate.
     */
    public function shredTenant(int $tenantId, bool $force = false, ?string $reason = null): array;
}
