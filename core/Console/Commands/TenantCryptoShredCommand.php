<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Privacy\TenantPrivacyManagerInterface;

class TenantCryptoShredCommand extends Command
{
    protected string $signature = 'tenant:crypto-shred';
    protected string $description = 'Instantly shred a tenant cryptographic key to render past encrypted data unrecoverable';

    public function handle(array $arguments = [], array $options = []): int
    {
        $tenantId = isset($arguments[0]) ? (int) $arguments[0] : 0;
        if ($tenantId <= 0) {
            $this->error("Penggunaan: php aio tenant:crypto-shred <tenant_id> [--force]");
            return 1;
        }

        $force = isset($options['force']);
        if (!$force) {
            $this->error("PERINGATAN: Memusnahkan kunci enkripsi tenant akan membuat seluruh data tersandi menjadi sampah matematis permanen! Tambahkan flag --force untuk melanjutkan.");
            return 1;
        }

        /** @var TenantPrivacyManagerInterface $privacy */
        $privacy = $this->container->get(TenantPrivacyManagerInterface::class);

        try {
            $this->warn("Memusnahkan cryptographic key untuk tenant [{$tenantId}]...");
            $privacy->shredKey($tenantId);

            $this->success("Kunci enkripsi tenant [{$tenantId}] berhasil dimusnahkan secara permanen (Crypto-Shredded).");
            $this->line("Seluruh payload ciphertext historis tenant kini tidak dapat dipulihkan oleh siapapun.");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Gagal melakukan crypto-shredding: " . $e->getMessage());
            return 1;
        }
    }
}
